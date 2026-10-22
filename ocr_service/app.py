"""
Tesseract OCR Microservice for Academic Management System
Provides secure HTTPS/HTTP REST API for Optical Character Recognition.
Enhanced with OpenCV preprocessing (deskewing, CLAHE, denoising, adaptive thresholding),
pypdfium2 high-fidelity PDF rasterization, intelligent PSM/OEM layout analysis,
caching, and performance metrics.
"""

import os
import io
import time
import shutil
import hashlib
import logging
from typing import Optional, Dict, Any, List, Tuple
from fastapi import FastAPI, UploadFile, File, Form, Header, HTTPException, Query, status
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image

# Import dedicated OpenCV preprocessor
try:
    from preprocessor import ImagePreprocessor
except ImportError:
    from ocr_service.preprocessor import ImagePreprocessor

# Configure structured logging
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] [%(name)s] %(message)s")
logger = logging.getLogger("ocr_service")

# Environment configuration
OCR_API_KEY = os.getenv("OCR_API_KEY", "ads_ocr_secure_secret_key_2026")
MAX_FILE_SIZE_MB = int(os.getenv("MAX_FILE_SIZE_MB", "10"))
MAX_PAGES = int(os.getenv("MAX_PAGES", "5"))
DEFAULT_LANG = os.getenv("DEFAULT_LANG", "eng")
DEFAULT_OEM = int(os.getenv("DEFAULT_OEM", "1"))  # 1 = Neural Nets LSTM, 3 = Default
DEFAULT_PSM = int(os.getenv("DEFAULT_PSM", "3"))  # 3 = Fully automatic page segmentation
DEFAULT_DPI = int(os.getenv("DEFAULT_DPI", "300"))
ENABLE_CACHE = os.getenv("ENABLE_CACHE", "true").lower() in ("true", "1", "yes")

app = FastAPI(
    title="Academic Management System — High-Accuracy OCR Microservice",
    description="Extracts text from images and documents using Tesseract OCR, OpenCV, & PyPDFium2",
    version="2.0.0"
)

# CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# In-memory LRU Cache (hash -> result)
OCR_RESULT_CACHE: Dict[str, Dict[str, Any]] = {}
MAX_CACHE_ENTRIES = 250


def find_tesseract_binary() -> Optional[str]:
    """Find tesseract binary in PATH or common Windows/Linux install locations."""
    # Check custom env var first
    custom_path = os.getenv("TESSERACT_PATH")
    if custom_path and os.path.isfile(custom_path):
        tessdata_dir = os.path.join(os.path.dirname(custom_path), "tessdata")
        if os.path.isdir(tessdata_dir) and not os.getenv("TESSDATA_PREFIX"):
            os.environ["TESSDATA_PREFIX"] = tessdata_dir
        return custom_path

    which_path = shutil.which("tesseract")
    if which_path:
        tessdata_dir = os.path.join(os.path.dirname(which_path), "tessdata")
        if os.path.isdir(tessdata_dir) and not os.getenv("TESSDATA_PREFIX"):
            os.environ["TESSDATA_PREFIX"] = tessdata_dir
        return which_path

    # Common Windows install paths
    common_paths = [
        r"D:\tesseract\tesseract.exe",
        r"C:\Program Files\Tesseract-OCR\tesseract.exe",
        r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
        os.path.expandvars(r"%LOCALAPPDATA%\Programs\Tesseract-OCR\tesseract.exe"),
        r"D:\Tesseract-OCR\tesseract.exe",
        r"D:\xampp\Tesseract-OCR\tesseract.exe",
    ]
    for p in common_paths:
        if os.path.isfile(p):
            tessdata_dir = os.path.join(os.path.dirname(p), "tessdata")
            if os.path.isdir(tessdata_dir) and not os.getenv("TESSDATA_PREFIX"):
                os.environ["TESSDATA_PREFIX"] = tessdata_dir
            return p
    return None


def get_tesseract_languages() -> List[str]:
    """Return list of installed language models."""
    tess_path = find_tesseract_binary()
    if not tess_path:
        return []
    try:
        import pytesseract
        pytesseract.pytesseract.tesseract_cmd = tess_path
        return pytesseract.get_languages()
    except Exception:
        return ["eng"]


def compute_sha256(data: bytes) -> str:
    """Compute SHA-256 fingerprint for caching."""
    h = hashlib.sha256()
    h.update(data)
    return h.hexdigest()


@app.get("/health")
def health_check():
    """Health status and capability report."""
    tess_bin = find_tesseract_binary()
    import cv2
    langs = get_tesseract_languages()
    return {
        "status": "healthy",
        "service": "ams-ocr-engine-v2",
        "tesseract_installed": tess_bin is not None,
        "tesseract_path": tess_bin,
        "opencv_version": cv2.__version__,
        "available_languages": langs,
        "default_oem": DEFAULT_OEM,
        "default_psm": DEFAULT_PSM,
        "max_file_size_mb": MAX_FILE_SIZE_MB,
        "max_pages": MAX_PAGES,
        "cached_documents": len(OCR_RESULT_CACHE)
    }


@app.post("/ocr")
async def process_ocr(
    file: UploadFile = File(...),
    psm: Optional[int] = Form(None),
    oem: Optional[int] = Form(None),
    lang: Optional[str] = Form(None),
    profile: Optional[str] = Form("auto"),
    x_ocr_api_key: Optional[str] = Header(None, alias="X-OCR-API-KEY")
):
    """
    Extract text from uploaded Image (JPG, PNG, WebP) or PDF document.
    Requires header: X-OCR-API-KEY
    Supports layout parameters:
    - psm: Page segmentation mode (3 for auto/multi-column, 6 for single block)
    - oem: OCR Engine Mode (1 for LSTM, 3 for Default)
    - lang: Language code (e.g., 'eng')
    - profile: Preprocessing profile ('auto', 'roster', 'school_notes', 'scanned_doc', 'raw')
    """
    # 1. API Key Authentication
    if not x_ocr_api_key or x_ocr_api_key != OCR_API_KEY:
        logger.warning("Unauthorized access attempt to /ocr")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing X-OCR-API-KEY header."
        )

    # 2. Validate filename and extension
    filename = file.filename or "upload.dat"
    ext = filename.split(".")[-1].lower() if "." in filename else ""
    allowed_exts = {"jpg", "jpeg", "png", "pdf", "webp", "bmp", "tiff"}
    if ext not in allowed_exts:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=f"Unsupported file extension '{ext}'. Allowed: {', '.join(sorted(allowed_exts))}"
        )

    # 3. Read content and validate size
    content = await file.read()
    file_size_mb = len(content) / (1024 * 1024)
    if file_size_mb > MAX_FILE_SIZE_MB:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail=f"File size ({file_size_mb:.2f}MB) exceeds limit of {MAX_FILE_SIZE_MB}MB."
        )

    # Check cache if enabled
    cache_key = ""
    effective_psm = psm if psm is not None else DEFAULT_PSM
    effective_oem = oem if oem is not None else DEFAULT_OEM
    effective_lang = lang if lang and lang.strip() else DEFAULT_LANG
    effective_profile = profile if profile and profile.strip() else "auto"

    if ENABLE_CACHE:
        cache_key = compute_sha256(content) + f"_{effective_psm}_{effective_oem}_{effective_lang}_{effective_profile}"
        if cache_key in OCR_RESULT_CACHE:
            logger.info(f"Serving cached OCR result for '{filename}' ({cache_key[:12]}...)")
            cached = dict(OCR_RESULT_CACHE[cache_key])
            cached["cached"] = True
            return cached

    start_time = time.time()
    logger.info(f"Processing '{filename}' ({len(content)} bytes, profile={effective_profile}, psm={effective_psm}, oem={effective_oem}, lang={effective_lang})")

    # 4. Extract Text
    try:
        if ext == "pdf":
            result = process_pdf(
                content=content,
                filename=filename,
                profile=effective_profile,
                psm=effective_psm,
                oem=effective_oem,
                lang=effective_lang
            )
        else:
            result = process_image(
                content=content,
                filename=filename,
                profile=effective_profile,
                psm=effective_psm,
                oem=effective_oem,
                lang=effective_lang
            )

        duration_ms = round((time.time() - start_time) * 1000, 1)

        response_payload = {
            "success": True,
            "filename": filename,
            "text": result["text"],
            "pages": result["pages"],
            "is_scanned": result.get("is_scanned", False),
            "source": result.get("source", "ocr_service"),
            "processing_time_ms": duration_ms,
            "metadata": result.get("metadata", {}),
            "message": "Text extracted successfully."
        }

        # Store in cache
        if ENABLE_CACHE and cache_key:
            if len(OCR_RESULT_CACHE) >= MAX_CACHE_ENTRIES:
                # Evict oldest entry
                oldest_key = next(iter(OCR_RESULT_CACHE))
                del OCR_RESULT_CACHE[oldest_key]
            OCR_RESULT_CACHE[cache_key] = response_payload

        return response_payload

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error processing {filename}: {str(e)}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"OCR processing failed: {str(e)}"
        )


def run_tesseract_with_fallback(
    primary_img: Image.Image,
    binary_img: Optional[Image.Image],
    psm: int,
    oem: int,
    lang: str
) -> Tuple[str, Dict[str, Any]]:
    """
    Execute Tesseract with an intelligent multi-pass strategy:
    1. Primary pass: Run with specified PSM & OEM on enhanced grayscale image.
    2. Fallback pass A: If PSM 3 was requested and produced very few characters, evaluate PSM 6.
    3. Fallback pass B: If contrast/grayscale had very low word count, evaluate binary (Otsu) image.
    """
    import pytesseract

    config_str = f"--oem {oem} --psm {psm}"
    best_text = ""
    pass_used = f"primary (psm={psm}, oem={oem})"

    # Primary pass
    try:
        best_text = pytesseract.image_to_string(primary_img, lang=lang, config=config_str).strip()
    except Exception as e:
        logger.warning(f"Primary Tesseract pass error: {e}")

    # Fallback A: If PSM 3 returned low text count (< 25 chars), test PSM 6 (uniform single block)
    if psm == 3 and len(best_text) < 25:
        try:
            alt_config = f"--oem {oem} --psm 6"
            alt_text = pytesseract.image_to_string(primary_img, lang=lang, config=alt_config).strip()
            if len(alt_text) > len(best_text):
                best_text = alt_text
                pass_used = f"fallback_psm6 (oem={oem})"
                logger.info(f"Fallback to PSM 6 improved text extraction ({len(alt_text)} chars)")
        except Exception as e:
            logger.warning(f"Fallback PSM 6 error: {e}")

    # Fallback B: If text is still sparse and binary image is available, evaluate binary image
    if len(best_text) < 25 and binary_img is not None:
        try:
            bin_text = pytesseract.image_to_string(binary_img, lang=lang, config=f"--oem {oem} --psm {psm}").strip()
            if len(bin_text) > len(best_text):
                best_text = bin_text
                pass_used = f"binary_threshold (psm={psm}, oem={oem})"
                logger.info(f"Binary threshold pass improved text extraction ({len(bin_text)} chars)")
        except Exception as e:
            logger.warning(f"Binary threshold pass error: {e}")

    return best_text, {"pass_used": pass_used}


def process_image(
    content: bytes,
    filename: str,
    profile: str = "auto",
    psm: int = 3,
    oem: int = 1,
    lang: str = "eng"
) -> Dict[str, Any]:
    """Run OpenCV preprocessing and Tesseract OCR on image bytes."""
    tess_path = find_tesseract_binary()
    if not tess_path:
        logger.warning("Tesseract binary not found on host. Returning placeholder.")
        return {
            "text": f"[Tesseract binary not installed on host. Filename: {filename}]",
            "pages": 1,
            "is_scanned": True,
            "source": "placeholder"
        }

    try:
        import pytesseract
        pytesseract.pytesseract.tesseract_cmd = tess_path
    except ImportError:
        raise RuntimeError("pytesseract is not installed on OCR server.")

    # 1. Load image
    raw_image = Image.open(io.BytesIO(content))

    # 2. OpenCV preprocessing
    prep_data = ImagePreprocessor.process(raw_image, profile=profile)
    primary_img = prep_data["primary_image"]
    binary_img = prep_data["binary_image"]

    # 3. Intelligent multi-pass Tesseract execution
    extracted_text, ocr_meta = run_tesseract_with_fallback(
        primary_img=primary_img,
        binary_img=binary_img,
        psm=psm,
        oem=oem,
        lang=lang
    )

    metadata = {
        "skew_angle": prep_data.get("skew_angle", 0.0),
        "upscaled": prep_data.get("upscaled", False),
        "scale_factor": prep_data.get("scale_factor", 1.0),
        "pass_used": ocr_meta.get("pass_used", "primary")
    }

    return {
        "text": extracted_text,
        "pages": 1,
        "is_scanned": True,
        "source": "ocr_service",
        "metadata": metadata
    }


def rasterize_pdf_page_pypdfium(pdf_bytes: bytes, max_pages: int, dpi: int = 300) -> List[Image.Image]:
    """
    Render PDF pages to PIL Images using pypdfium2 at specified DPI (e.g. 300).
    Requires NO external poppler installation on Windows.
    """
    import pypdfium2 as pdfium
    pdf = pdfium.PdfDocument(pdf_bytes)
    total_pages = min(len(pdf), max_pages)
    scale = float(dpi) / 72.0
    images = []

    for idx in range(total_pages):
        page = pdf[idx]
        pil_image = page.render(scale=scale).to_pil()
        images.append(pil_image)

    return images


def process_pdf(
    content: bytes,
    filename: str,
    profile: str = "auto",
    psm: int = 3,
    oem: int = 1,
    lang: str = "eng"
) -> Dict[str, Any]:
    """
    Process PDF:
    1. Check for digital selectable text with PyPDF. If all pages contain rich selectable text,
       return digital text immediately (fastest, no rasterization overhead).
    2. For scanned or mixed PDFs: Rasterize pages to 300 DPI images with pypdfium2,
       apply OpenCV preprocessing, and run Tesseract OCR.
    """
    import pypdf
    pdf_reader = pypdf.PdfReader(io.BytesIO(content))
    total_pages = len(pdf_reader.pages)

    if total_pages > MAX_PAGES:
        raise ValueError(f"PDF contains {total_pages} pages, which exceeds the limit of {MAX_PAGES} pages.")

    # Check for digital selectable text across pages
    extracted_pages = []
    has_empty_page = False

    for i, page in enumerate(pdf_reader.pages):
        try:
            page_text = page.extract_text(extraction_mode="layout") or ""
        except Exception:
            page_text = page.extract_text() or ""
        clean_page_text = page_text.strip()
        if len(clean_page_text) < 15:
            has_empty_page = True
        extracted_pages.append(clean_page_text)

    combined_digital_text = "\n\n".join([p for p in extracted_pages if p])

    # If selectable text is found on all pages, return digital text directly
    if not has_empty_page and len(combined_digital_text) > 30:
        logger.info(f"PDF '{filename}' contains digital text on all {total_pages} pages. Direct extraction used.")
        return {
            "text": combined_digital_text,
            "pages": total_pages,
            "is_scanned": False,
            "source": "pdf_digital_text",
            "metadata": {"method": "pypdf_digital_stream"}
        }

    # Otherwise, scanned or image-based PDF: rasterize pages
    logger.info(f"PDF '{filename}' appears scanned. Rasterizing {total_pages} pages for OCR...")

    tess_path = find_tesseract_binary()
    if not tess_path:
        logger.warning("Tesseract binary not found on host. Returning digital text fallback.")
        return {
            "text": combined_digital_text or f"[Scanned PDF without Tesseract installed on host: {filename}]",
            "pages": total_pages,
            "is_scanned": True,
            "source": "fallback"
        }

    try:
        import pytesseract
        pytesseract.pytesseract.tesseract_cmd = tess_path
    except ImportError:
        raise RuntimeError("pytesseract is not installed on OCR server.")

    # 1. Rasterize pages with pypdfium2 (preferred, zero-dependency on Windows)
    images = []
    raster_method = "pypdfium2"
    try:
        images = rasterize_pdf_page_pypdfium(content, max_pages=MAX_PAGES, dpi=DEFAULT_DPI)
    except Exception as e_pdfium:
        logger.warning(f"pypdfium2 rasterization failed ({e_pdfium}). Attempting pdf2image fallback...")
        try:
            from pdf2image import convert_from_bytes
            images = convert_from_bytes(content, first_page=1, last_page=MAX_PAGES)
            raster_method = "pdf2image"
        except Exception as e_pdf2img:
            logger.warning(f"pdf2image fallback also failed ({e_pdf2img}). Extracting embedded image streams...")
            # Fallback to pypdf embedded image objects
            for p in pdf_reader.pages:
                for img_obj in p.images:
                    images.append(Image.open(io.BytesIO(img_obj.data)))
            raster_method = "pypdf_embedded_images"

    if not images:
        logger.warning("No rasterized images could be produced from PDF. Returning digital text fallback.")
        return {
            "text": combined_digital_text,
            "pages": total_pages,
            "is_scanned": True,
            "source": "pdf_digital_text_fallback"
        }

    # 2. Process each rasterized page with OpenCV and Tesseract
    ocr_texts = []
    page_metas = []

    for idx, img in enumerate(images):
        prep_data = ImagePreprocessor.process(img, profile=profile)
        page_text, meta = run_tesseract_with_fallback(
            primary_img=prep_data["primary_image"],
            binary_img=prep_data["binary_image"],
            psm=psm,
            oem=oem,
            lang=lang
        )
        ocr_texts.append(page_text.strip())
        page_metas.append({
            "page": idx + 1,
            "skew_angle": prep_data.get("skew_angle", 0.0),
            "pass_used": meta.get("pass_used", "primary")
        })

    return {
        "text": "\n\n".join([t for t in ocr_texts if t]),
        "pages": len(images),
        "is_scanned": True,
        "source": "ocr_service",
        "metadata": {
            "raster_method": raster_method,
            "page_details": page_metas
        }
    }


if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", "8000"))
    logger.info(f"Starting High-Accuracy OCR microservice on port {port}...")
    uvicorn.run("app:app", host="0.0.0.0", port=port, reload=True)
