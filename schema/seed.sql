-- ============================================================
-- Academic Class, Attendance, and Grade Management System
-- Seed Data — Fictional test records
-- ============================================================
-- Run this file AFTER schema.sql to populate test data.
-- All passwords are: password123
-- ============================================================

USE academic_management_db;

-- ============================================================
-- USERS (passwords hashed with PHP password_hash — bcrypt)
-- ⚠️ DEVELOPMENT / LOCAL TESTING ONLY — DO NOT USE IN PRODUCTION
-- All test accounts use password: password123
-- Hash generated via: password_hash('password123', PASSWORD_BCRYPT)
-- ============================================================
-- ⚠️ NOTE: Portal login access is strictly restricted to Faculty and Admin.
-- Student accounts (student1-student5) link student profiles for instructor
-- grading, attendance tracking, and dean directory records. Students are blocked from login.
INSERT INTO users (username, email, password_hash, role, account_status) VALUES
('dean',         'dean@academic.edu',         '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'dean',       'active'),
('prof_smith',   'smith@academic.edu',        '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'instructor', 'active'),
('prof_johnson', 'johnson@academic.edu',      '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'instructor', 'active'),
('student1',     'student1@academic.edu',     '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'student',    'active'),
('student2',     'student2@academic.edu',     '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'student',    'active'),
('student3',     'student3@academic.edu',     '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'student',    'active'),
('student4',     'student4@academic.edu',     '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'student',    'active'),
('student5',     'student5@academic.edu',     '$2y$10$ook.gtAlx9y7bfgY/QQUvOfQ.5CyXaXWip4phigVha0XraYme4.Yu', 'student',    'active');

-- ============================================================
-- INSTRUCTORS
-- ============================================================
INSERT INTO instructors (user_id, first_name, middle_name, last_name, department) VALUES
(2, 'John',    'A.',  'Smith',   'Computer Science'),
(3, 'Maria',   'B.',  'Johnson', 'Information Technology');

-- ============================================================
-- STUDENTS
-- ============================================================
INSERT INTO students (user_id, student_number, first_name, middle_name, last_name, yr_section) VALUES
(4, '2024-0001', 'Alice',   'M.',  'Garcia',    '3-A'),
(5, '2024-0002', 'Bob',     NULL,  'Reyes',     '3-A'),
(6, '2024-0003', 'Carlos',  'D.',  'Santos',    '3-B'),
(7, '2024-0004', 'Diana',   'E.',  'Cruz',      '3-B'),
(8, '2024-0005', 'Edward',  NULL,  'Fernandez', '3-A');

-- ============================================================
-- SUBJECTS
-- ============================================================
INSERT INTO subjects (subject_code, subject_name) VALUES
('CS101', 'Database Systems'),
('CS102', 'Data Structures and Algorithms'),
('IT201', 'Web Development');

-- ============================================================
-- CLASSES (2 classes for the current semester)
-- ============================================================
INSERT INTO classes (subject_id, instructor_id, school_year, school_semester, passing_threshold) VALUES
(1, 1, '2025-2026', '1st', 75.00),   -- CS101 taught by Prof. Smith
(2, 1, '2025-2026', '1st', 75.00),   -- CS102 taught by Prof. Smith
(3, 2, '2025-2026', '1st', 75.00);   -- IT201 taught by Prof. Johnson

-- ============================================================
-- STUDENT_CLASSES (Enrollments)
-- Class 1 (CS101): students 1,2,3,5
-- Class 2 (CS102): students 1,2,4
-- Class 3 (IT201): students 3,4,5
-- ============================================================
INSERT INTO student_classes (student_id, class_id) VALUES
(1, 1), (2, 1), (3, 1), (5, 1),   -- CS101 enrollees
(1, 2), (2, 2), (4, 2),           -- CS102 enrollees
(3, 3), (4, 3), (5, 3);           -- IT201 enrollees

-- ============================================================
-- CLASS_SESSIONS (sample sessions for each class)
-- ============================================================
INSERT INTO class_sessions (class_id, session_date, start_time, end_time, session_time) VALUES
-- CS101 sessions (08:00 AM - 09:30 AM)
(1, '2025-09-01', '08:00:00', '09:30:00', '08:00:00'),
(1, '2025-09-03', '08:00:00', '09:30:00', '08:00:00'),
(1, '2025-09-05', '08:00:00', '09:30:00', '08:00:00'),
(1, '2025-09-08', '08:00:00', '09:30:00', '08:00:00'),
(1, '2025-09-10', '08:00:00', '09:30:00', '08:00:00'),
-- CS102 sessions (10:00 AM - 11:30 AM)
(2, '2025-09-01', '10:00:00', '11:30:00', '10:00:00'),
(2, '2025-09-03', '10:00:00', '11:30:00', '10:00:00'),
(2, '2025-09-05', '10:00:00', '11:30:00', '10:00:00'),
-- IT201 sessions (01:00 PM - 02:30 PM)
(3, '2025-09-02', '13:00:00', '14:30:00', '13:00:00'),
(3, '2025-09-04', '13:00:00', '14:30:00', '13:00:00'),
(3, '2025-09-09', '13:00:00', '14:30:00', '13:00:00');

-- ============================================================
-- GRADING_COMPONENTS (weights MUST total 100% per class)
-- ============================================================
-- CS101 components
INSERT INTO grading_components (class_id, component_name, weight_percentage, maximum_score) VALUES
(1, 'Quizzes',     25.00, 100.00),
(1, 'Activities',  25.00, 100.00),
(1, 'Exams',       40.00, 100.00),
(1, 'Attendance',  10.00, 100.00);

-- CS102 components
INSERT INTO grading_components (class_id, component_name, weight_percentage, maximum_score) VALUES
(2, 'Quizzes',     30.00, 100.00),
(2, 'Activities',  20.00, 100.00),
(2, 'Exams',       40.00, 100.00),
(2, 'Attendance',  10.00, 100.00);

-- IT201 components
INSERT INTO grading_components (class_id, component_name, weight_percentage, maximum_score) VALUES
(3, 'Quizzes',     20.00,  50.00),
(3, 'Activities',  30.00, 100.00),
(3, 'Exams',       40.00, 100.00),
(3, 'Attendance',  10.00, 100.00);

-- ============================================================
-- ATTENDANCE (sample records for CS101, sessions 1 & 2)
-- student_class_id references: 1=Alice/CS101, 2=Bob/CS101,
--   3=Carlos/CS101, 4=Edward/CS101
-- ============================================================
INSERT INTO attendance (student_class_id, session_id, status) VALUES
-- Session 1 (Sep 1)
(1, 1, 'Present'),   -- Alice
(2, 1, 'Present'),   -- Bob
(3, 1, 'Absent'),    -- Carlos
(4, 1, 'Present'),   -- Edward
-- Session 2 (Sep 3)
(1, 2, 'Present'),   -- Alice
(2, 2, 'Late'),      -- Bob
(3, 2, 'Present'),   -- Carlos
(4, 2, 'Excused');   -- Edward

-- ============================================================
-- GRADES (sample scores for CS101 — Quizzes component_id=1)
-- ============================================================
INSERT INTO grades (student_class_id, component_id, score) VALUES
(1, 1, 88.00),   -- Alice Quiz score
(2, 1, 75.00),   -- Bob Quiz score
(3, 1, 62.00),   -- Carlos Quiz score
(4, 1, 91.00);   -- Edward Quiz score

-- ============================================================
-- FINAL_GRADES (one sample — pending)
-- ============================================================
INSERT INTO final_grades (student_class_id, final_grade, status) VALUES
(1, NULL, 'Pending'),   -- Alice in CS101 — not yet finalized
(2, NULL, 'Pending'),   -- Bob in CS101
(3, NULL, 'Pending'),   -- Carlos in CS101
(4, NULL, 'Pending');   -- Edward in CS101

-- ============================================================
-- NOTIFICATIONS (absence notification for Carlos, session 1)
-- ============================================================
INSERT INTO notifications (student_id, attendance_id, notification_type, message, is_read) VALUES
(3, 3, 'absence', 'You were marked absent for CS101 — Database Systems on September 1, 2025.', 0);

-- ============================================================
-- AUDIT_LOGS (sample entry)
-- ============================================================
INSERT INTO audit_logs (user_id, action, details) VALUES
(1, 'SEED_DATA', 'Initial test data loaded for development and testing.');
