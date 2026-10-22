@echo off
title Academic Management System - OCR Microservice
echo ===================================================================
echo   Academic Management System - OCR Microservice (Port 8000)
echo ===================================================================
echo.

cd /d "%~dp0ocr_service"

echo [*] Checking Python...
python --version >nul 2>&1
if %errorlevel% neq 0 (
    if exist "D:\Anaconda\python.exe" (
        echo [*] Auto-detected Python in D:\Anaconda
        set "PATH=D:\Anaconda;D:\Anaconda\Scripts;%PATH%"
    ) else if exist "%LOCALAPPDATA%\Programs\Python\Python311\python.exe" (
        echo [*] Auto-detected Python in LocalAppData
        set "PATH=%LOCALAPPDATA%\Programs\Python\Python311;%PATH%"
    ) else if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" (
        echo [*] Auto-detected Python in LocalAppData
        set "PATH=%LOCALAPPDATA%\Programs\Python\Python312;%PATH%"
    ) else if exist "C:\ProgramData\anaconda3\python.exe" (
        echo [*] Auto-detected Python in C:\ProgramData\anaconda3
        set "PATH=C:\ProgramData\anaconda3;C:\ProgramData\anaconda3\Scripts;%PATH%"
    ) else (
        echo [ERROR] Python is not found in your system PATH.
        echo Please ensure Python 3.10+ is installed and accessible.
        pause
        exit /b 1
    )
)

python --version

:: Auto-detect Tesseract in D:\tesseract or Program Files
if exist "D:\tesseract\tesseract.exe" (
    echo [*] Found Tesseract in D:\tesseract
    set "PATH=D:\tesseract;%PATH%"
    set "TESSDATA_PREFIX=D:\tesseract\tessdata"
) else if exist "C:\Program Files\Tesseract-OCR\tesseract.exe" (
    echo [*] Found Tesseract in C:\Program Files\Tesseract-OCR
    set "PATH=C:\Program Files\Tesseract-OCR;%PATH%"
    set "TESSDATA_PREFIX=C:\Program Files\Tesseract-OCR\tessdata"
)

:: Verify critical Python dependencies
python -c "import cv2, pypdfium2, fastapi, pytesseract" >nul 2>&1
if %errorlevel% neq 0 (
    echo [*] Installing or updating required Python packages (OpenCV, PyPDFium2, FastAPI)...
    python -m pip install -r requirements.txt
)

echo.
echo [*] ===============================================================
echo [*] OCR Microservice is starting on http://127.0.0.1:8000
echo [*] Keep this window OPEN while running document uploads.
echo [*] Press CTRL+C at any time to stop the server.
echo [*] ===============================================================
echo.

python -m uvicorn app:app --host 0.0.0.0 --port 8000 --reload

pause
