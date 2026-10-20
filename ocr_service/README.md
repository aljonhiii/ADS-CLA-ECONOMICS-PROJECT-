# Tesseract OCR Microservice & Deployment Guide

This microservice provides an Optical Character Recognition (OCR) API endpoint for the **Academic Management System**.

---

## Architecture Overview
- **Primary Website**: Hosted on **InfinityFree** (PHP 8.x + MySQL).
  - Handles web requests, auth, session state, UI rendering, and database transactions.
  - InfinityFree *cannot* run Tesseract directly.
  - Digital PDFs with selectable text are extracted directly inside pure PHP via `PdfTextExtractor.php`.
- **OCR Server**: Hosted on an external Linux machine, container, or cloud service (e.g. Render, Railway, Hugging Face Spaces, or a $4/mo Linux VPS).
  - Handles heavy OCR image analysis and scanned PDF rasterization using **Tesseract OCR** and **Poppler**.
  - Securely communicates with InfinityFree over HTTPS with the `X-OCR-API-KEY` secret header.

---

## 1. Free Cloud Deployment (Recommended: Render.com)

1. Create a free account at [Render.com](https://render.com).
2. Click **New +** > **Web Service**.
3. Choose **Deploy existing image from a registry** OR connect your GitHub repository pointing to the `ocr_service` folder.
   - If using GitHub with Docker:
     - Root Directory: `ocr_service`
     - Runtime: **Docker**
   - Environment Variables:
     - `OCR_API_KEY`: `your_random_secret_token_here_2026`
     - `PORT`: `8000`
4. Click **Create Web Service**.
5. Once deployed, Render will provide a free HTTPS URL:
   `https://ams-ocr-service.onrender.com`
6. Copy this URL and set it in your InfinityFree `.env` file:
   ```env
   OCR_SERVER_URL=https://ams-ocr-service.onrender.com/ocr
   OCR_API_KEY=your_random_secret_token_here_2026
   ```

---

## 2. Docker Deployment (Any Linux VPS or Server)

Run directly on any VPS (Ubuntu, Debian, CentOS) with Docker:

```bash
cd ocr_service
docker build -t ams-ocr-service .
docker run -d -p 8000:8000 \
  -e OCR_API_KEY="ads_ocr_secure_secret_key_2026" \
  --name ams_ocr ams-ocr-service
```

Test health:
```bash
curl http://localhost:8000/health
```

---

## 3. Local Development on Windows (Testing with XAMPP)

1. Ensure Python 3.10+ is installed (`python --version`).
2. Open PowerShell in `ocr_service/`:
   ```powershell
   cd d:\xampp\htdocs\ads_client\ocr_service
   pip install -r requirements.txt
   ```
3. Run the microservice locally:
   ```powershell
   python -m uvicorn app:app --port 8000 --reload
   ```
4. Verify local endpoint in your browser:
   [http://localhost:8000/health](http://localhost:8000/health)

---

## 4. InfinityFree PHP Deployment Checklist

On InfinityFree:
1. Upload your PHP codebase to `htdocs/`.
2. Edit `.env` in the root:
   ```env
   OCR_SERVER_URL=https://your-ocr-server.onrender.com/ocr
   OCR_API_KEY=your_random_secret_token_here_2026
   OCR_MAX_FILE_SIZE_MB=5
   OCR_MAX_PAGES=5
   OCR_TIMEOUT_SECONDS=30
   ```
3. Test uploading both digital PDFs and scanned images.
