FROM python:3.11-slim

# Install system dependencies: Tesseract OCR engine, English language data, and Poppler utilities
RUN apt-get update && apt-get install -y --no-install-recommends \
    tesseract-ocr \
    tesseract-ocr-eng \
    poppler-utils \
    libgl1 \
    curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install Python requirements
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY app.py .

# Expose microservice port
EXPOSE 8000

ENV PORT=8000
ENV MAX_FILE_SIZE_MB=10
ENV MAX_PAGES=5

# Healthcheck
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
  CMD curl -f http://localhost:8000/health || exit 1

# Start FastAPI application with Uvicorn
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
