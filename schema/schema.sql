-- ============================================================
-- Academic Class, Attendance, and Grade Management System
-- Database Schema (MySQL / MariaDB — InnoDB)
-- ============================================================
-- Run this file FIRST to create the database and all tables.
-- Usage:
--   mysql -u root -p < schema.sql
--   OR import via phpMyAdmin
-- ============================================================

CREATE DATABASE IF NOT EXISTS academic_management_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE academic_management_db;

-- ============================================================
-- 1. USERS — Authentication, account status, and role management
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    user_id         INT             AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)     NOT NULL UNIQUE,
    email           VARCHAR(100)    DEFAULT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    role            ENUM('dean','instructor','student') NOT NULL,
    account_status  ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_users_role (role),
    INDEX idx_users_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. STUDENTS — Student profile records
-- ============================================================
CREATE TABLE IF NOT EXISTS students (
    student_id      INT             AUTO_INCREMENT PRIMARY KEY,
    user_id         INT             NOT NULL UNIQUE,
    student_number  VARCHAR(20)     NOT NULL UNIQUE,
    first_name      VARCHAR(50)     NOT NULL,
    middle_name     VARCHAR(50)     DEFAULT NULL,
    last_name       VARCHAR(50)     NOT NULL,
    yr_section      VARCHAR(20)     NOT NULL,

    CONSTRAINT fk_students_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    INDEX idx_students_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. INSTRUCTORS — Instructor profile records
-- ============================================================
CREATE TABLE IF NOT EXISTS instructors (
    instructor_id   INT             AUTO_INCREMENT PRIMARY KEY,
    user_id         INT             NOT NULL UNIQUE,
    first_name      VARCHAR(50)     NOT NULL,
    middle_name     VARCHAR(50)     DEFAULT NULL,
    last_name       VARCHAR(50)     NOT NULL,
    department      VARCHAR(100)    NOT NULL,

    CONSTRAINT fk_instructors_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    INDEX idx_instructors_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. SUBJECTS — Subject / course catalog
-- ============================================================
CREATE TABLE IF NOT EXISTS subjects (
    subject_id      INT             AUTO_INCREMENT PRIMARY KEY,
    subject_code    VARCHAR(20)     NOT NULL UNIQUE,
    subject_name    VARCHAR(100)    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. CLASSES — Offering of a subject by an instructor in a term
-- ============================================================
CREATE TABLE IF NOT EXISTS classes (
    class_id            INT             AUTO_INCREMENT PRIMARY KEY,
    subject_id          INT             NOT NULL,
    instructor_id       INT             NOT NULL,
    school_year         VARCHAR(9)      NOT NULL COMMENT 'e.g. 2025-2026',
    school_semester     ENUM('1st','2nd','Summer') NOT NULL,
    passing_threshold   DECIMAL(5,2)    NOT NULL DEFAULT 75.00 COMMENT 'Configurable passing mark',

    CONSTRAINT fk_classes_subject
        FOREIGN KEY (subject_id) REFERENCES subjects(subject_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_classes_instructor
        FOREIGN KEY (instructor_id) REFERENCES instructors(instructor_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    INDEX idx_classes_term (school_year, school_semester),
    INDEX idx_classes_subject (subject_id),
    INDEX idx_classes_instructor (instructor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. STUDENT_CLASSES — Enrollment junction (Student ↔ Class)
-- ============================================================
CREATE TABLE IF NOT EXISTS student_classes (
    student_class_id    INT     AUTO_INCREMENT PRIMARY KEY,
    student_id          INT     NOT NULL,
    class_id            INT     NOT NULL,

    CONSTRAINT fk_sc_student
        FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sc_class
        FOREIGN KEY (class_id) REFERENCES classes(class_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    UNIQUE KEY uk_student_class (student_id, class_id),
    INDEX idx_sc_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. CLASS_SESSIONS — Individual meeting dates for a class
-- ============================================================
CREATE TABLE IF NOT EXISTS class_sessions (
    session_id      INT         AUTO_INCREMENT PRIMARY KEY,
    class_id        INT         NOT NULL,
    session_date    DATE        NOT NULL,
    start_time      TIME        DEFAULT NULL,
    end_time        TIME        DEFAULT NULL,
    session_time    TIME        DEFAULT NULL,

    CONSTRAINT fk_sessions_class
        FOREIGN KEY (class_id) REFERENCES classes(class_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    INDEX idx_sessions_date (class_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. ATTENDANCE — Per-student, per-session attendance record
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id       INT         AUTO_INCREMENT PRIMARY KEY,
    student_class_id    INT         NOT NULL,
    session_id          INT         NOT NULL,
    status              ENUM('Present','Absent','Late','Excused') NOT NULL DEFAULT 'Present',
    recorded_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_att_student_class
        FOREIGN KEY (student_class_id) REFERENCES student_classes(student_class_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_att_session
        FOREIGN KEY (session_id) REFERENCES class_sessions(session_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    UNIQUE KEY uk_attendance (student_class_id, session_id),
    INDEX idx_att_session (session_id),
    INDEX idx_att_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. GRADING_COMPONENTS — Weight configuration per class
--    Examples: Quizzes 25%, Activities 25%, Exams 40%, Attendance 10%
-- ============================================================
CREATE TABLE IF NOT EXISTS grading_components (
    component_id        INT             AUTO_INCREMENT PRIMARY KEY,
    class_id            INT             NOT NULL,
    component_name      VARCHAR(50)     NOT NULL,
    weight_percentage   DECIMAL(5,2)    NOT NULL COMMENT 'Must total 100% per class',
    maximum_score       DECIMAL(10,2)   NOT NULL COMMENT 'Max possible raw score',

    CONSTRAINT fk_gc_class
        FOREIGN KEY (class_id) REFERENCES classes(class_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    INDEX idx_gc_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. GRADES — Individual student scores per grading component
-- ============================================================
CREATE TABLE IF NOT EXISTS grades (
    grade_id            INT             AUTO_INCREMENT PRIMARY KEY,
    student_class_id    INT             NOT NULL,
    component_id        INT             NOT NULL,
    score               DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    recorded_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_grades_sc
        FOREIGN KEY (student_class_id) REFERENCES student_classes(student_class_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_grades_component
        FOREIGN KEY (component_id) REFERENCES grading_components(component_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    UNIQUE KEY uk_grade (student_class_id, component_id),
    INDEX idx_grades_component (component_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. FINAL_GRADES — Calculated final grade and pass/fail status
-- ============================================================
CREATE TABLE IF NOT EXISTS final_grades (
    final_grade_id      INT             AUTO_INCREMENT PRIMARY KEY,
    student_class_id    INT             NOT NULL UNIQUE,
    final_grade         DECIMAL(5,2)    DEFAULT NULL,
    status              ENUM('Pass','Fail','Pending') NOT NULL DEFAULT 'Pending',
    finalized_at        TIMESTAMP       NULL DEFAULT NULL,
    finalized_by        INT             DEFAULT NULL COMMENT 'user_id of who finalized',

    CONSTRAINT fk_fg_sc
        FOREIGN KEY (student_class_id) REFERENCES student_classes(student_class_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fg_finalized_by
        FOREIGN KEY (finalized_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_fg_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. NOTIFICATIONS — Absence notifications for students
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    notification_id     INT             AUTO_INCREMENT PRIMARY KEY,
    student_id          INT             NOT NULL,
    attendance_id       INT             DEFAULT NULL,
    notification_type   VARCHAR(50)     NOT NULL DEFAULT 'absence',
    message             TEXT            NOT NULL,
    is_read             TINYINT(1)      NOT NULL DEFAULT 0,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notif_student
        FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_notif_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance(attendance_id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_notif_student (student_id),
    INDEX idx_notif_read (student_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. AUDIT_LOGS — Track important changes (grades, attendance)
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    log_id      INT             AUTO_INCREMENT PRIMARY KEY,
    user_id     INT             DEFAULT NULL,
    action      VARCHAR(100)    NOT NULL,
    details     TEXT            DEFAULT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_audit_action (action),
    INDEX idx_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
