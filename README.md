# Academic Class, Attendance, Grade Management & Universal Document Ingestion System

A modern, role-based academic portal built with **PHP 8+**, **MySQL / MariaDB (InnoDB)**, **Bootstrap 5**, and a dedicated **Python OCR & Document Processing Microservice** (FastAPI, OpenCV, PyPDFium2, PyPDF, Tesseract).

---

## 📋 Table of Contents
1. [Key Features & Architecture](#-key-features--architecture)
2. [Prerequisites Checklist](#-prerequisites-checklist)
3. [Step-by-Step Environment Setup Guide](#-step-by-step-environment-setup-guide)
   - [Step 1: Clone the Repository](#step-1-clone-the-repository)
   - [Step 2: Database Initialization](#step-2-database-initialization)
   - [Step 3: Environment Configuration (.env)](#step-3-environment-configuration-env)
   - [Step 4: Install Tesseract OCR (Windows)](#step-4-install-tesseract-ocr-windows)
   - [Step 5: Setup & Start the Python OCR Microservice](#step-5-setup--start-the-python-ocr-microservice)
   - [Step 6: Launch Web Application](#step-6-launch-web-application)
4. [Default Credentials](#-default-credentials)
5. [Document Ingestion & Export Capabilities](#-document-ingestion--export-capabilities)
6. [Troubleshooting & Common Fixes](#-troubleshooting--common-fixes)
7. [Security & Deployment Architecture](#-security--deployment-architecture)

---

## 🌟 Key Features & Architecture

- **Strict Role Separation**:
  - **Dean / Admin**: Campus-wide analytics, faculty load, subject directory, enrollment summaries, attendance audits, and gradebook compliance oversight.
  - **Faculty / Instructor**: Class roster ingestion, scheduling meeting sessions, 1-click attendance taking, grading weight configuration (validating to 100%), matrix score entry, automated final grade calculations, and student pass/fail determination.
- **Universal Multi-Format Student Ingestion**:
  - **Excel (`.xlsx`, `.xls`) & CSV**: Native pure-PHP OpenXML parsing with zero Composer or third-party dependencies (works instantly on local XAMPP and InfinityFree shared hosting). Sub-millisecond execution (~0.6ms) bypassing OCR.
  - **Digital PDFs**: Layout-aware table extraction supporting both horizontal rows and multi-line vertical blocks.
  - **Scanned PDFs & Photos**: Microservice pipeline utilizing OpenCV adaptive thresholding, deskewing, Otsu binarization, and Tesseract multi-pass OCR.
  - **Live Database Candidate Enrichment**: Cross-references uploaded rosters against existing student records in MySQL, flagging "New", "Existing", and "Already Enrolled" before committing.
- **Two-Way Data Exports**:
  - Download official sample CSV roster template.
  - Export live enrolled class rosters to Excel/CSV with UTF-8 BOM.
  - Export class gradebook matrices (raw scores, weighted averages, and pass/fail remarks).
  - Export class attendance performance reports.

---

## 🧰 Prerequisites Checklist

Ensure your development machine has the following installed:

| Tool | Recommended Version | Download Link | Notes |
|------|---------------------|---------------|-------|
| **XAMPP** | 8.1, 8.2, or 8.3 | [Apache Friends](https://www.apachefriends.org/) | Provides Apache, MySQL/MariaDB, and PHP 8+ |
| **Python** | 3.10, 3.11, or 3.12 | [Python.org](https://www.python.org/downloads/) | **Must check "Add Python to PATH"** during installation |
| **Tesseract OCR** | 5.3+ (64-bit) | [UB-Mannheim Tesseract](https://github.com/UB-Mannheim/tesseract/wiki) | Required for scanned PDF/image OCR |
| **Git** | 2.x+ | [Git for Windows](https://git-scm.com/download/win) | For cloning and version control |

---

## 🛠️ Step-by-Step Environment Setup Guide

### Step 1: Clone the Repository
Clone the project directly into your XAMPP web root directory (`htdocs`):

```bash
# Open PowerShell or Command Prompt
cd C:\xampp\htdocs

# Clone the repository
git clone https://github.com/aljonhiii/ADS-CLA-ECONOMICS-PROJECT-.git ads_client

# Enter project directory
cd ads_client
```

> **Note**: If your XAMPP is installed on `D:\xampp`, use `D:\xampp\htdocs\ads_client` instead.

---

### Step 2: Database Initialization

1. Open the **XAMPP Control Panel** and click **Start** next to **Apache** and **MySQL**.
2. Create the database and import the initial schema and seed data.

#### Option A: Via Command Prompt / PowerShell (Fastest)
```powershell
# Open MySQL CLI (adjust path if XAMPP is on D:)
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS academic_management_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Import schema (13 InnoDB tables with foreign keys and indexes)
C:\xampp\mysql\bin\mysql.exe -u root academic_management_db < schema\schema.sql

# Import seed data (dean, instructors, subjects, sample classes)
C:\xampp\mysql\bin\mysql.exe -u root academic_management_db < schema\seed.sql
```

#### Option B: Via phpMyAdmin Web UI
1. Navigate to [http://localhost/phpmyadmin](http://localhost/phpmyadmin) in your browser.
2. Click **New** in the left sidebar and create a database named `academic_management_db` (Collation: `utf8mb4_unicode_ci`).
3. Select `academic_management_db`, navigate to the **Import** tab, choose `schema/schema.sql`, and click **Go**.
4. Repeat the import for `schema/seed.sql`.

---

### Step 3: Environment Configuration (`.env`)

In the project root (`ads_client`), copy `.env.example` to create your active `.env` file:

```powershell
# In PowerShell:
Copy-Item .env.example .env
```

Open `.env` in your text editor (VS Code, Notepad, etc.) and configure the settings for your local machine:

```ini
# ─── Application Settings ──────────────────────────────────────
APP_DEBUG=true
APP_BASE_PATH=/ads_client
APP_SECURE_COOKIES=false

# ─── Database Credentials ─────────────────────────────────────
DB_HOST=localhost
DB_PORT=3306
DB_NAME=academic_management_db
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

# ─── OCR Engine Configuration ─────────────────────────────────
# Options: 'auto' (tries service, falls back to local), 'service', 'local'
OCR_ENGINE=auto

# Python OCR microservice URL
OCR_SERVER_URL=http://127.0.0.1:8000/ocr
OCR_API_KEY=ads_ocr_secure_secret_key_2026

# Path to your local tesseract.exe binary (see Step 4 below)
# Default standard 64-bit installer location:
TESSERACT_PATH=C:\Program Files\Tesseract-OCR\tesseract.exe
# If installed on D:\ drive, set:
# TESSERACT_PATH=D:\tesseract\tesseract.exe

# OCR Constraints & Quality
OCR_DEFAULT_LANG=eng
OCR_DEFAULT_PSM=3
OCR_DEFAULT_OEM=1
OCR_MAX_FILE_SIZE_MB=15
OCR_MAX_PAGES=10
OCR_TIMEOUT_SECONDS=30

# Performance Caching (SHA-256 duplicate upload reuse)
OCR_ENABLE_CACHE=true
OCR_CACHE_TTL=86400
```

---

### Step 4: Install Tesseract OCR (Windows)

1. Download the latest 64-bit installer from **UB-Mannheim**:
   👉 [tesseract-ocr-w64-setup-5.x.x.exe](https://github.com/UB-Mannheim/tesseract/wiki)
2. Run the installer:
   - Accept default destination: `C:\Program Files\Tesseract-OCR` (or `D:\tesseract`).
   - In the components step, ensure **English** language data is checked.
3. Test that Tesseract works by running in PowerShell:
   ```powershell
   & "C:\Program Files\Tesseract-OCR\tesseract.exe" --version
   ```
4. Verify that `TESSERACT_PATH` in your `.env` matches the location where you installed it.

---

### Step 5: Setup & Start the Python OCR Microservice

The microservice handles PDF layout extraction, OpenCV image preprocessing, and Tesseract execution.

#### Option A: One-Click Startup (Recommended)
Simply double-click:
```
start_ocr.bat
```
*(Or run `.\start_ocr.bat` in your terminal)*

The script will automatically:
1. Detect your Python interpreter.
2. Auto-detect your Tesseract installation in `C:\Program Files` or `D:\tesseract`.
3. Auto-install any missing dependencies from `ocr_service/requirements.txt` (`fastapi`, `uvicorn`, `opencv-python`, `pypdfium2`, `pypdf`, `pytesseract`).
4. Start the FastAPI server on **`http://127.0.0.1:8000`**.

#### Option B: Manual Virtual Environment Setup
If you prefer managing your virtual environment manually:
```powershell
cd ocr_service

# Create virtual environment
python -m venv venv

# Activate virtual environment
.\venv\Scripts\Activate.ps1

# Upgrade pip and install dependencies
pip install --upgrade pip
pip install -r requirements.txt

# Start the microservice with auto-reload
python -m uvicorn app:app --host 0.0.0.0 --port 8000 --reload
```

#### Verifying the OCR Microservice:
Open your browser and navigate to:
[http://127.0.0.1:8000/health](http://127.0.0.1:8000/health)

You should see:
```json
{
  "status": "healthy",
  "service": "Academic OCR Microservice",
  "tesseract_detected": true,
  "tesseract_path": "C:\\Program Files\\Tesseract-OCR\\tesseract.exe"
}
```

> ⚠️ **Important**: Keep the `start_ocr.bat` terminal window **open** while using image or scanned PDF roster uploads.

---

### Step 6: Launch Web Application

Open your web browser and navigate to:
```
http://localhost/ads_client/
```

Log in using one of the seeded accounts listed below.

---

## 🔑 Default Credentials

All seeded test accounts use the default password: **`password123`**

| Role | Username | Password | Purpose |
|------|----------|----------|---------|
| **Dean (Admin)** | `dean` | `password123` | Institutional oversight, faculty directory, audit logs |
| **Instructor (Faculty)** | `prof_smith` | `password123` | Computer Science Instructor (Classes: CS101, CS102) |
| **Instructor (Faculty)** | `prof_johnson` | `password123` | IT Department Instructor (Classes: IT201, IT202) |

---

## 📄 Document Ingestion & Export Capabilities

### 1. Ingesting Student Rosters (`/instructor/students.php?class_id=X`)
Navigate to **My Classes** $\rightarrow$ select a class $\rightarrow$ **Students / Roster** $\rightarrow$ **Ingest via OCR / Spreadsheet**:

- **Excel (`.xlsx`, `.xls`, `.csv`) Upload**:
  - Drag and drop your `.xlsx` or `.csv` file.
  - Automatically identifies column headers (`Student Number`, `Last Name`, `First Name`, `Middle Name`, `Year & Section`, `Email`).
  - Processes instantly in ~0.6ms without needing the OCR server.
- **PDF Upload**:
  - Automatically extracts digital tables with layout preservation.
  - Supports both single-row tables and multi-line sequential blocks.
- **Scanned Documents & Photos (`.png`, `.jpg`, `.webp`)**:
  - Automatic deskewing, noise removal, and contrast enhancement.
- **Verification Screen**:
  - Review candidates with status tags (**New Student**, **Existing Student**, or **Already Enrolled**).
  - Edit student details inline or click **Re-parse** on edited text.
  - Check/uncheck individual students before clicking **Import Selected Students**.

### 2. Two-Way Data Exports
- **Download CSV Template**: Click **"Download Template (.csv)"** on the Ingestion tab to get a pre-formatted template with standard headers.
- **Export Enrolled Roster**: Click **"Export Excel"** on the **Enrolled Students** tab to download the official enrolled student list.
- **Export Grades Matrix**: On **Grades Matrix**, click **"Export Grades (Excel)"** to download student term scores, averages, and remarks.
- **Export Attendance**: On **Attendance**, click **"Export Excel"** to download student session attendance percentages.

---

## ❓ Troubleshooting & Common Fixes

### 1. `Could not reach OCR microservice (Connection refused)`
- **Cause**: The Python FastAPI server is not running on port 8000.
- **Fix**: Run `start_ocr.bat` in the project root and ensure the window remains open. Check that `http://127.0.0.1:8000/health` returns `healthy`.
- **Note**: Spreadsheets (`.xlsx`, `.csv`) do NOT require the microservice and will continue to work even if port 8000 is closed.

### 2. `Document text was extracted, but no student records were identified`
- **Cause**: The uploaded PDF or image text could not be matched to standard student numbers or name patterns.
- **Fix**:
  - Ensure the document contains student IDs in standard formats (e.g. `2024-04520`, `24-0123-456`, or numeric IDs).
  - Use the built-in raw text textarea directly in the browser to adjust or paste student records, then click **"Re-parse"**.
  - For tabular rosters, ensure headers contain recognizable keywords (`Student Number`, `Last Name`, `First Name`).

### 3. `Tesseract binary not found on host`
- **Cause**: Tesseract is not installed or `TESSERACT_PATH` in `.env` points to the wrong directory.
- **Fix**: Open `.env` and verify `TESSERACT_PATH`. Typical locations:
  - `C:\Program Files\Tesseract-OCR\tesseract.exe`
  - `D:\tesseract\tesseract.exe`

### 4. `Undefined variable: pdo` or Database Connection Failed
- **Cause**: Database credentials in `.env` do not match your MySQL setup, or MySQL is stopped.
- **Fix**: Check XAMPP Control Panel to confirm MySQL is green. Check that `DB_NAME=academic_management_db`, `DB_USER=root`, and `DB_PASS=` are correct in `.env`.

### 5. Infinite Loading or CSRF Token Expired
- **Cause**: Session expired or cookies blocked.
- **Fix**: Refresh the page, log in again, and ensure `APP_BASE_PATH=/ads_client` is accurately set in `.env`.

---

## 🔒 Security & Deployment Architecture

- **Role-Based Access Control**: Pages guarded by `require_role()` rejecting unauthorized roles with HTTP 403.
- **CSRF Protection**: All POST operations require a synchronized session token validated via `verify_csrf()`.
- **SQL Injection Prevention**: 100% prepared PDO statements with bound parameters across all models and handlers.
- **XSS Defense**: Strict HTML escaping via the `e()` helper function across all dynamic views.
- **Password Hashing**: Passwords stored using PHP's `password_hash()` (bcrypt).
- **MIME & Magic Byte Validation**: Ingestion validates true file signatures via `finfo` and image dimension checks, rejecting disguised executable payloads.
- **Directory Protection**: `storage/` directory guarded with `.htaccess` and `web.config` preventing direct URL script execution.

---

## 👥 Contributors & Support

Developed for the **Academic Management & Class Economics System**.
For questions or issues, please check the [Troubleshooting](#-troubleshooting--common-fixes) section or open a GitHub Issue in the repository.
