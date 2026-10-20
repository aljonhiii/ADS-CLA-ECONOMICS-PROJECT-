"""
Tesseract OCR Microservice for Academic Management System
Provides secure HTTPS/HTTP REST API for Optical Character Recognition.
Handles images (JPG, PNG) and PDF documents with smart preprocessing and layout analysis.
"""

import os
import io
import shutil
import logging
from typing import Optional
from fastapi import FastAPI, UploadFile, File, Header, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image, ImageOps, ImageEnhance

# Configure logging
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("ocr_service")

# Environment configuration
OCR_API_KEY = os.getenv("OCR_API_KEY", "ads_ocr_secure_secret_key_2026")
MAX_FILE_SIZE_MB = int(os.getenv("MAX_FILE_SIZE_MB", "10"))
MAX_PAGES = int(os.getenv("MAX_PAGES", "5"))

app = FastAPI(
    title="Academic Management System — OCR Microservice",
    description="Extracts text from images and documents using Tesseract OCR & PyPDF",
    version="1.1.0"
)

# CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

def find_tesseract_binary() -> Optional[str]:
    """Find tesseract binary in PATH or common Windows install locations."""
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

def find_poppler_path() -> Optional[str]:
    """Find poppler utility path for pdf2image on Windows."""
    common_poppler = [
        r"D:\poppler\bin",
        r"D:\poppler\Library\bin",
        r"C:\Program Files\poppler\bin",
        r"C:\Program Files (x86)\poppler\bin",
        r"C:\poppler\bin",
        r"D:\xampp\poppler\bin",
        os.path.expandvars(r"%LOCALAPPDATA%\Programs\poppler\bin"),
    ]
    for p in common_poppler:
        if os.path.isdir(p):
            return p
    return None

def is_tesseract_available() -> bool:
    """Check if the tesseract binary is installed and executable."""
    return find_tesseract_binary() is not None

# Configure pytesseract at module load time
TESS_PATH = find_tesseract_binary()
if TESS_PATH:
    try:
        import pytesseract
        pytesseract.pytesseract.tesseract_cmd = TESS_PATH
        logger.info(f"Tesseract OCR initialized: {TESS_PATH}")
    except ImportError:
        logger.warning("pytesseract package not installed.")

def preprocess_image(image: Image.Image) -> Image.Image:
    """
    Optimize uploaded photo/document for maximum OCR accuracy:
    1. Correct smartphone orientation (EXIF transpose).
    2. Upscale low-res images for sharp character recognition.
    3. Enhance contrast on faint text.
    """
    # 1. Handle smartphone EXIF orientation
    try:
        image = ImageOps.exif_transpose(image)
    except Exception:
        pass

    # 2. Convert to RGB
    if image.mode not in ("RGB", "L"):
        image = image.convert("RGB")

    # 3. Scale up small images (below 1600px) so character strokes are clear to Tesseract
    w, h = image.size
    max_dim = max(w, h)
    if max_dim < 1600:
        scale = min(2.5, 2000.0 / max_dim)
        if scale > 1.1:
            new_w = int(w * scale)
            new_h = int(h * scale)
            image = image.resize((new_w, new_h), Image.Resampling.LANCZOS)

    return image

@app.get("/health")
def health_check():
    """Health status and capability report."""
    tess_bin = find_tesseract_binary()
    return {
        "status": "healthy",
        "service": "ams-ocr-engine",
        "tesseract_installed": tess_bin is not None,
        "tesseract_path": tess_bin,
        "max_file_size_mb": MAX_FILE_SIZE_MB,
        "max_pages": MAX_PAGES
    }

@app.post("/ocr")
async def process_ocr(
    file: UploadFile = File(...),
    x_ocr_api_key: Optional[str] = Header(None, alias="X-OCR-API-KEY")
):
    """
    Extract text from uploaded Image (JPG, PNG) or PDF document.
    Requires header: X-OCR-API-KEY
    """
    # 1. API Key Authentication
    if not x_ocr_api_key or x_ocr_api_key != OCR_API_KEY:
        logger.warning("Unauthorized access attempt to /ocr")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing X-OCR-API-KEY header."
        )

    # 2. Validate filename and MIME type
    filename = file.filename or "upload.dat"
    ext = filename.split(".")[-1].lower() if "." in filename else ""
    allowed_exts = {"jpg", "jpeg", "png", "pdf", "webp", "bmp", "tiff"}
    if ext not in allowed_exts:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=f"Unsupported file extension '{ext}'. Allowed: jpg, jpeg, png, pdf, webp, bmp, tiff"
        )

    # 3. Read content and validate size
    content = await file.read()
    file_size_mb = len(content) / (1024 * 1024)
    if file_size_mb > MAX_FILE_SIZE_MB:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail=f"File size ({file_size_mb:.2f}MB) exceeds limit of {MAX_FILE_SIZE_MB}MB."
        )

    logger.info(f"Processing '{filename}' ({len(content)} bytes, type: {file.content_type})")

    # 4. Extract Text
    try:
        if ext == "pdf":
            result = process_pdf(content, filename)
        else:
            result = process_image(content, filename)
        
        return {
            "success": True,
            "filename": filename,
            "text": result["text"],
            "pages": result["pages"],
            "is_scanned": result.get("is_scanned", False),
            "message": "Text extracted successfully."
        }
    except Exception as e:
        logger.error(f"Error processing {filename}: {str(e)}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"OCR processing failed: {str(e)}"
        )

def process_image(content: bytes, filename: str) -> dict:
    """Run Tesseract OCR on image bytes with adaptive segmentation."""
    try:
        import pytesseract
    except ImportError:
        raise RuntimeError("pytesseract is not installed on OCR server.")

    tess_path = find_tesseract_binary()
    if not tess_path:
        logger.warning("Tesseract binary not found on host. Returning placeholder.")
        return {
            "text": f"[Tesseract binary not installed on host. Filename: {filename}]",
            "pages": 1,
            "is_scanned": True
        }

    pytesseract.pytesseract.tesseract_cmd = tess_path

    # Open and pre-process image
    raw_image = Image.open(io.BytesIO(content))
    processed_image = preprocess_image(raw_image)

    # Adaptive layout analysis:
    # PSM 3: Fully automatic page segmentation (detects multi-column tables like BOYS / GIRLS)
    extracted_text = ""
    try:
        extracted_text = pytesseract.image_to_string(processed_image, config=r'--oem 3 --psm 3')
    except Exception as e:
        logger.warning(f"PSM 3 extraction error: {e}. Retrying with PSM 6...")

    # Fallback to PSM 6 (single uniform block) if PSM 3 returned very little text
    if len(extracted_text.strip()) < 25:
        try:
            psm6_text = pytesseract.image_to_string(processed_image, config=r'--oem 3 --psm 6')
            if len(psm6_text.strip()) > len(extracted_text.strip()):
                extracted_text = psm6_text
        except Exception as e:
            logger.warning(f"PSM 6 fallback failed: {e}")

    return {
        "text": extracted_text.strip(),
        "pages": 1,
        "is_scanned": True
    }

def process_pdf(content: bytes, filename: str) -> dict:
    """Process PDF: Extract digital text with pypdf, or rasterize scanned pages and run OCR."""
    import pypdf
    pdf_reader = pypdf.PdfReader(io.BytesIO(content))
    total_pages = len(pdf_reader.pages)

    if total_pages > MAX_PAGES:
        raise ValueError(f"PDF contains {total_pages} pages, which exceeds the limit of {MAX_PAGES} pages.")

    # Check for selectable text across pages
    extracted_pages = []
    has_empty_page = False

    for i, page in enumerate(pdf_reader.pages):
        page_text = page.extract_text() or ""
        if len(page_text.strip()) < 15:
            has_empty_page = True
        extracted_pages.append(page_text.strip())

    combined_digital_text = "\n\n".join([p for p in extracted_pages if p])

    # If selectable text is found on all pages, return digital text directly
    if not has_empty_page and len(combined_digital_text) > 30:
        return {
            "text": combined_digital_text,
            "pages": total_pages,
            "is_scanned": False
        }

    # Otherwise, the PDF has scanned image pages: convert to images and run Tesseract
    logger.info(f"PDF '{filename}' appears scanned. Converting pages to images for OCR...")
    
    tess_path = find_tesseract_binary()
    if not tess_path:
        logger.warning("Tesseract binary not found on host. Returning digital text fallback.")
        return {
            "text": combined_digital_text or f"[Scanned PDF without Tesseract installed on host: {filename}]",
            "pages": total_pages,
            "is_scanned": True
        }

    try:
        from pdf2image import convert_from_bytes
        import pytesseract
        
        pytesseract.pytesseract.tesseract_cmd = tess_path
        poppler = find_poppler_path()
        
        kwargs = {"first_page": 1, "last_page": MAX_PAGES}
        if poppler:
            kwargs["poppler_path"] = poppler

        images = convert_from_bytes(content, **kwargs)
        ocr_texts = []
        for idx, img in enumerate(images):
            img = preprocess_image(img)
            page_ocr = pytesseract.image_to_string(img, config=r'--oem 3 --psm 3')
            ocr_texts.append(page_ocr.strip())
        
        return {
            "text": "\n\n".join(ocr_texts),
            "pages": len(images),
            "is_scanned": True
        }
    except Exception as e:
        logger.warning(f"pdf2image conversion failed ({str(e)}). Falling back to extracted text.")
        return {
            "text": combined_digital_text,
            "pages": total_pages,
            "is_scanned": False
        }

if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", "8000"))
    logger.info(f"Starting OCR microservice on port {port}...")
    uvicorn.run("app:app", host="0.0.0.0", port=port, reload=True)
