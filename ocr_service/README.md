# Tesseract OCR Pipeline & Microservice Guide

High-Accuracy Optical Character Recognition (OCR) pipeline for the **Academic Management System**.
Engineered for scanned documents, PDF class rosters, photographed student lists, and school notes.

---

## Architecture Overview

```
                                      ┌──────────────────────────────────────────────┐
                                      │              Document Upload                 │
                                      │         (PDF, JPG, PNG, WebP)                │
                                      └──────────────────────┬───────────────────────┘
                                                             │
                                      ┌──────────────────────▼───────────────────────┐
                                      │   Security & MIME Validation (PHP finfo)     │
                                      │    - Content verification (getimagesize)     │
                                      │    - Cryptographic SHA-256 Cache Check       │
                                      └──────────┬─────────────────────────┬─────────┘
                                                 │                         │
                               (Digital PDF Text)│                         │(Scanned PDF or Image)
                                                 │                         │
                               ┌─────────────────▼────────┐      ┌─────────▼──────────────┐
                               │  PdfTextExtractor.php    │      │ Cache Hit?             │
                               │  - Pure PHP stream parse │      │ - Return in < 5ms      │
                               │  - 0ms OCR overhead      │      └─────────┬──────────────┘
                               └──────────────────────────┘                │
                                                                           │(Cache Miss)
                                                    ┌──────────────────────┴──────────────────────┐
                                                    │                                             │
                                         (Microservice Online)                          (Offline / Fallback)
                                                    │                                             │
                               ┌────────────────────▼─────────────────────┐       ┌───────────────▼──────────────┐
                               │ Python FastAPI Microservice (Port 8000)  │       │ LocalTesseractEngine.php     │
                               │ - OpenCV Preprocessing:                  │       │ - proc_open execution        │
                               │   * EXIF orientation correction          │       │ - Safe escapeshellarg()      │
                               │   * Resolution upscale (Cubic)           │       │ - Timeout enforcement        │
                               │   * CLAHE contrast enhancement           │       │ - Isolated storage/tmp/      │
                               │   * Automatic deskewing                  │       │ - Direct Tesseract CLI       │
                               │   * Bilateral edge-preserving denoise    │       │   (D:\tesseract\tesseract)   │
                               │ - PyPDFium2 300 DPI PDF rasterization    │       └───────────────┬──────────────┘
                               │ - Tesseract LSTM Engine (--oem 1)        │                       │
                               │ - Adaptive PSM 3/6 Multi-Pass Selection  │                       │
                               └────────────────────┬─────────────────────┘                       │
                                                    │                                             │
                                                    └──────────────────────┬──────────────────────┘
                                                                           │
                                                            ┌──────────────▼──────────────┐
                                                            │   OcrCache & OcrLogger      │
                                                            │   - SHA-256 disk cache      │
                                                            │   - storage/logs/ocr.log    │
                                                            └──────────────┬──────────────┘
                                                                           │
                                                            ┌──────────────▼──────────────┐
                                                            │      RosterParser.php       │
                                                            │  Tokenize Student Records   │
                                                            └─────────────────────────────┘
```

---

## Key Capabilities

1. **Dual-Engine Architecture with Automatic Fallback**:
   - Primary: FastAPI microservice with OpenCV preprocessing and PyPDFium2.
   - Fallback: Direct local execution of `D:\tesseract\tesseract.exe` via `LocalTesseractEngine.php`. If the Python microservice is offline, the PHP web application continues functioning without interruption.

2. **OpenCV Preprocessing Pipeline (`ocr_service/preprocessor.py`)**:
   - **EXIF Transpose**: Corrects smartphone camera orientation.
   - **Resolution Normalization**: Upscales images where text character stroke height is below Tesseract's optimal recognition threshold.
   - **CLAHE**: Contrast Limited Adaptive Histogram Equalization compensates for shadows, page gradients, and faint photocopy toner.
   - **Deskewing**: Analyzes text line contours using minimum area rectangles to detect document skew and un-rotates the document.
   - **Bilateral Filtering**: Denoises paper texture while keeping character edges razor-sharp.
   - **Adaptive Thresholding**: Generates non-destructive Otsu and adaptive binarization for multi-pass evaluations.

3. **Intelligent Tesseract Engine Configuration**:
   - `--oem 1`: Neural Nets LSTM engine for superior character recognition.
   - Multi-pass layout evaluation: Defaults to `--psm 3` for multi-column student rosters; automatically evaluates `--psm 6` (single block) if initial extraction yields sparse text.

4. **Zero-Poppler PDF Processing**:
   - Searchable/digital PDFs are extracted directly in pure PHP via `PdfTextExtractor.php`.
   - Scanned PDFs are rasterized at 300 DPI using `pypdfium2`, eliminating any external Poppler / `pdftoppm` installation requirement on Windows.

5. **Security & Data Isolation**:
   - Real MIME type verification via `finfo` and image structure check via `getimagesize()`.
   - Content-signature verification for PDFs (`%PDF-`).
   - Isolated temporary storage in `storage/tmp/` outside web root, protected by `.htaccess` and `web.config`.
   - Injection-safe execution using `escapeshellarg()` and `proc_open`.
   - User-safe error sanitization: system paths and secrets are never exposed to clients.

6. **Deduplication & Performance Caching**:
   - SHA-256 content hashing caches results in `storage/ocr_cache/`.
   - Duplicate uploads or re-evaluations return instantly (< 5ms).

---

## Configuration (`.env`)

```env
# OCR Engine Mode: 'auto' (service with local fallback), 'service' (microservice only), 'local' (local CLI only)
OCR_ENGINE=auto

# Microservice URL & Secret Key
OCR_SERVER_URL=http://localhost:8000/ocr
OCR_API_KEY=ads_ocr_secure_secret_key_2026

# Host Tesseract executable path
TESSERACT_PATH=D:\tesseract\tesseract.exe

# Layout & Engine Defaults
OCR_DEFAULT_LANG=eng
OCR_DEFAULT_PSM=3
OCR_DEFAULT_OEM=1

# Ingestion Constraints
OCR_MAX_FILE_SIZE_MB=10
OCR_MAX_PAGES=5
OCR_TIMEOUT_SECONDS=30

# Performance Caching
OCR_ENABLE_CACHE=true
OCR_CACHE_TTL=86400
```

---

## Running the Application

### 1. Starting the Microservice (Windows XAMPP)
Double-click `start_ocr.bat` in the repository root or run:
```powershell
cd d:\xampp\htdocs\ads_client
.\start_ocr.bat
```
The service will start on `http://127.0.0.1:8000`.

### 2. Checking Service Health
Open in your browser or run:
```powershell
d:\xampp\php\php.exe -r "echo file_get_contents('http://127.0.0.1:8000/health');"
```

### 3. Running the Automated PHP Test Suite
```powershell
d:\xampp\php\php.exe tests/test_ocr_pipeline.php
```

### 4. Running the Accuracy & Speed Benchmark
```powershell
python ocr_service/evaluate.py
```
