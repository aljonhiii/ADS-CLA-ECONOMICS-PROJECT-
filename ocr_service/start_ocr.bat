@echo off
title Academic Management System - OCR Microservice
echo ===================================================================
echo   Academic Management System - OCR Microservice (Port 8000)
echo ===================================================================
echo.

echo [*] Checking Python...
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Python is not found in your PATH.
    echo Please install Python 3.10+ and add it to your system PATH.
    pause
    exit /b 1
)

echo [*] Starting OCR FastAPI service on http://127.0.0.1:8000 ...
echo [*] Press CTRL+C at any time to stop the server.
echo.

python -m uvicorn app:app --host 0.0.0.0 --port 8000 --reload

pause
