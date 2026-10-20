"""
Tesseract OCR Microservice for Academic Management System
Provides secure HTTPS/HTTP REST API for Optical Character Recognition
Deployed separately on a Linux Server / Docker / Render for InfinityFree integration.
"""

import os
import io
import shutil
import logging
from typing import Optional
from fastapi import FastAPI, UploadFile, File, Header, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

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
    version="1.0.0"
)

# Optional CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

def is_tesseract_available() -> bool:
    """Check if the tesseract binary is installed and executable in PATH."""
    return shutil.which("tesseract") is not None

@app.get("/health")
def health_check():
    """Health status and capability report."""
    tesseract_ok = is_tesseract_available()
    return {
        "status": "healthy",
        "service": "ams-ocr-engine",
        "tesseract_installed": tesseract_ok,
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
    allowed_exts = {"jpg", "jpeg", "png", "pdf"}
    if ext not in allowed_exts:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=f"Unsupported file extension '{ext}'. Allowed: jpg, jpeg, png, pdf"
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
    """Run Tesseract OCR on image bytes."""
    from PIL import Image
    try:
        import pytesseract
    except ImportError:
        raise RuntimeError("pytesseract is not installed on OCR server.")

    image = Image.open(io.BytesIO(content))
    # Convert RGBA to RGB for standard OCR
    if image.mode in ("RGBA", "P"):
        image = image.convert("RGB")

    if not is_tesseract_available():
        logger.warning("Tesseract binary not found in system PATH. Returning placeholder.")
        return {
            "text": f"[Tesseract binary not installed on host. Filename: {filename}]",
            "pages": 1,
            "is_scanned": True
        }

    # Run OCR with layout analysis (PSM 4 or 6: assume single uniform block or column of text)
    custom_config = r'--oem 3 --psm 6'
    extracted_text = pytesseract.image_to_string(image, config=custom_config)
    
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
    
    if not is_tesseract_available():
        logger.warning("Tesseract binary not found on host. Returning digital text fallback.")
        return {
            "text": combined_digital_text or f"[Scanned PDF without Tesseract installed on host: {filename}]",
            "pages": total_pages,
            "is_scanned": True
        }

    try:
        from pdf2image import convert_from_bytes
        import pytesseract
        
        images = convert_from_bytes(content, first_page=1, last_page=MAX_PAGES)
        ocr_texts = []
        for idx, img in enumerate(images):
            if img.mode != "RGB":
                img = img.convert("RGB")
            page_ocr = pytesseract.image_to_string(img, config=r'--oem 3 --psm 6')
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
