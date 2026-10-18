# Academic Class, Attendance, and Grade Management System

A modern, role-based academic portal developed with **PHP 8+**, **MySQL / MariaDB (InnoDB)**, and **Bootstrap 5**, tailored for deployment on **XAMPP**.

---

## 🚀 Key Highlights & Role Capabilities

The system implements strict separation of duties across two primary academic roles:

### 1. 🎓 Dean / Admin (Institutional Read-Only Oversight)
- **Executive Dashboard**: Campus-wide metrics including total students, faculty headcount, active class offerings, and aggregate pass rates.
- **Student Directory**: Searchable registry by student number, name, or academic section with class enrollment counts.
- **Subject Catalog**: Comprehensive listing of curriculum offerings and class distribution.
- **Faculty Directory**: Instructor profiles by academic department, active course loads, and student counts.
- **Class Offerings**: Term-filtered directory (school year and semester) tracking enrollment and grading readiness.
- **Attendance Audit**: Cross-departmental attendance log filterable by class, date, and status (Present, Absent, Late, Excused).
- **Gradebook Audit**: Campus gradebook viewer showing raw component marks, calculated final averages, Pass/Fail statuses, and audit timestamps.

### 2. 👨‍🏫 Instructor / Faculty (Class, Attendance & Grade Management)
- **Instructor Dashboard**: Summary of assigned classes, total students taught, scheduled sessions, and pending grade calculations.
- **My Classes**: Assigned subjects with configurable passing threshold (default `75.00%`).
- **Class Sessions**: CRUD scheduling for session dates, start times, and end times with delete-protection for sessions with attendance records.
- **Attendance Recording**: Interactive roster with quick "Mark All Present", real-time absence tracking, and status logging.
- **Grading Components Configuration**: Weight configuration (e.g. Quizzes, Midterms, Final Exam, Attendance). Validates that weights total **100%**.
- **Grade Entry Matrix**: Matrix table to enter/update raw student scores with client-side and server-side range validation (`0` to `maximum_score`).
- **Finalize Grades**: Automated weighted grade calculation:
  $$\text{Final Grade} = \sum \left( \frac{\text{Score}_i}{\text{MaxScore}_i} \times \text{WeightPercentage}_i \right)$$
  Evaluates Pass vs Fail against class passing threshold and commits records with an audit trail in `audit_logs`.

> [!NOTE]
> **Student Records Management**: Student profiles and class enrollments are maintained directly in the database for faculty attendance recording, grading matrix evaluation, and administrative oversight. The web portal itself is exclusively accessible by Faculty and Admin accounts.

---

## 🗄️ Database Architecture

The schema is organized in the `schema/` folder:
- [schema.sql](file:///d:/xampp/htdocs/ads_client/schema/schema.sql): Complete DDL containing **13 InnoDB tables** with strict foreign keys, indexes, and unique constraints.
- [seed.sql](file:///d:/xampp/htdocs/ads_client/schema/seed.sql): Pre-loaded test data with bcrypt-hashed passwords.

### Tables Overview
1. `users` — Authentication credentials & role assignment (`dean`, `instructor`, `student`).
2. `students` — Student profiles linked to `users`.
3. `instructors` — Faculty profiles linked to `users`.
4. `subjects` — Course catalog.
5. `classes` — Course offerings per school year & semester with passing threshold.
6. `student_classes` — Student class enrollments.
7. `class_sessions` — Class meeting schedules.
8. `attendance` — Per-student session attendance records.
9. `grading_components` — Class grading weights (totaling 100%).
10. `grades` — Raw component scores.
11. `final_grades` — Computed final grade averages and Pass/Fail statuses.
12. `notifications` — Student absence alerts.
13. `audit_logs` — System change logs.

---

## ⚙️ Installation & Setup (XAMPP)

### Step 1: Start XAMPP Services
Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.

### Step 2: Create Database & Import Schema
You can import the schema via **phpMyAdmin** (`http://localhost/phpmyadmin`) or via the MySQL CLI:

```bash
# Open command prompt or PowerShell
cd d:\xampp\mysql\bin

# Create the database and import schema
.\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS academic_management_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
.\mysql.exe -u root academic_management_db < d:\xampp\htdocs\ads_client\schema\schema.sql
.\mysql.exe -u root academic_management_db < d:\xampp\htdocs\ads_client\schema\seed.sql
```

### Step 3: Environment Configuration
Copy `.env.example` to `.env` (if not already created) and update your settings if needed:
```env
APP_DEBUG=true
APP_BASE_PATH=/ads_client
DB_HOST=localhost
DB_PORT=3306
DB_NAME=academic_management_db
DB_USER=root
DB_PASS=
```

### Step 4: Access Application
Navigate to:
```
http://localhost/ads_client/
```

---

## 🔑 Default Login Credentials

All seeded accounts use the password: `password123`

| Role | Username | Password | Notes |
|------|----------|----------|-------|
| **Dean (Admin)** | `dean` | `password123` | Institutional administrative oversight |
| **Faculty (Instructor)** | `prof_smith` | `password123` | Computer Science Department |
| **Faculty (Instructor)** | `prof_johnson` | `password123` | Information Technology Department |

> [!TIP]
> Student accounts (`student1`–`student5`) exist in the database solely as student records for faculty grade entry, attendance taking, and dean auditing. They are blocked from signing into the portal.

---

## 🔒 Security Implementations

- **Role-Based Access Control**: Pages guarded by `require_role()` rejecting unauthorized roles with HTTP 403.
- **CSRF Protection**: All POST operations require a synchronized session token validated via `verify_csrf()`.
- **SQL Injection Prevention**: 100% prepared PDO statements with bound parameters.
- **XSS Defense**: Strict HTML escaping via the `e()` helper function across all dynamic views.
- **Password Hashing**: Secure passwords stored using PHP's `password_hash()` (bcrypt).
- **Session Hardening**: `httponly` session cookies with `samesite=Lax` and session regeneration on authentication.
