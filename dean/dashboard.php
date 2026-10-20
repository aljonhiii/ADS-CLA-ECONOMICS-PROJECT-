<?php
/**
 * Dean — Executive Dashboard
 * 
 * High-level overview of institution metrics: total enrolled students,
 * active classes, faculty count, and campus-wide passing rates.
 * Styled in the Donezo executive dashboard visual system.
 */

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_role(['dean']);

$pdo = db_connect();

// 1. Total Students
$stmt = $pdo->query('SELECT COUNT(*) FROM students');
$total_students = (int)$stmt->fetchColumn();

// 2. Total Faculty
$stmt = $pdo->query('SELECT COUNT(*) FROM instructors');
$total_instructors = (int)$stmt->fetchColumn();

// 3. Total Classes
$stmt = $pdo->query('SELECT COUNT(*) FROM classes');
$total_classes = (int)$stmt->fetchColumn();

// 4. Passing Rate Across All Finalized Grades
$stmt = $pdo->query(
    "SELECT 
        COUNT(*) AS total_finalized,
        SUM(CASE WHEN status = 'Pass' THEN 1 ELSE 0 END) AS passed_count,
        SUM(CASE WHEN status = 'Fail' THEN 1 ELSE 0 END) AS failed_count,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count
     FROM final_grades"
);
$grade_stats = $stmt->fetch();
$total_finalized = (int)($grade_stats['total_finalized'] ?? 0);
$passed_count = (int)($grade_stats['passed_count'] ?? 0);
$failed_count = (int)($grade_stats['failed_count'] ?? 0);
$pending_count = (int)($grade_stats['pending_count'] ?? 0);
$passing_rate = $total_finalized > 0 ? round(($passed_count / $total_finalized) * 100, 1) : 0;

// 5. Recent Classes Overview
$stmt = $pdo->query(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester,
            CONCAT(i.last_name, ", ", i.first_name) AS instructor_name,
            COUNT(DISTINCT sc.student_id) AS student_count,
            COUNT(DISTINCT cs.session_id) AS session_count
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     JOIN instructors i ON c.instructor_id = i.instructor_id
     LEFT JOIN student_classes sc ON c.class_id = sc.class_id
     LEFT JOIN class_sessions cs ON c.class_id = cs.class_id
     GROUP BY c.class_id
     ORDER BY c.school_year DESC, s.subject_code ASC
     LIMIT 5'
);
$recent_classes = $stmt->fetchAll();
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-speedometer2 text-success me-1"></i>Executive Dashboard
        </h1>
        <p class="page-subtitle">Institutional overview of classes, faculty workload, enrollment numbers, and grade performance.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary px-3 py-2">
            <i class="bi bi-shield-check me-1"></i> Admin Oversight
        </span>
        <a href="<?php echo APP_BASE; ?>/dean/grades.php" class="btn btn-sm btn-forest">
            <i class="bi bi-award me-1"></i> Grade Reports
        </a>
    </div>
</div>

<!-- Key Stat Cards (Donezo Style: 1 Hero Forest Card + 3 Clean White Cards) -->
<div class="row g-3 mb-4">
    <!-- Hero Card: Total Students -->
    <div class="col-sm-6 col-xl-3">
        <a href="students.php" class="text-decoration-none d-block h-100">
            <div class="stat-card stat-card-hero">
                <div class="stat-card-header">
                    <span class="stat-card-title">TOTAL STUDENTS</span>
                    <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </div>
                <div class="stat-card-value"><?php echo number_format($total_students); ?></div>
                <div class="stat-card-footer">
                    <span class="trend-pill up">↑ Active</span>
                    <span>Enrolled this academic year</span>
                </div>
            </div>
        </a>
    </div>

    <!-- Card 2: Faculty Members -->
    <div class="col-sm-6 col-xl-3">
        <a href="instructors.php" class="text-decoration-none d-block h-100">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">FACULTY MEMBERS</span>
                    <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </div>
                <div class="stat-card-value"><?php echo number_format($total_instructors); ?></div>
                <div class="stat-card-footer">
                    <span class="trend-pill up">Teaching</span>
                    <span>Active instructors assigned</span>
                </div>
            </div>
        </a>
    </div>

    <!-- Card 3: Active Classes -->
    <div class="col-sm-6 col-xl-3">
        <a href="classes.php" class="text-decoration-none d-block h-100">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">ACTIVE CLASSES</span>
                    <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </div>
                <div class="stat-card-value"><?php echo number_format($total_classes); ?></div>
                <div class="stat-card-footer">
                    <span class="trend-pill up">Offered</span>
                    <span>Class sections this term</span>
                </div>
            </div>
        </a>
    </div>

    <!-- Card 4: Campus Pass Rate -->
    <div class="col-sm-6 col-xl-3">
        <a href="grades.php" class="text-decoration-none d-block h-100">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">CAMPUS PASS RATE</span>
                    <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </div>
                <div class="stat-card-value text-dark"><?php echo $passing_rate; ?>%</div>
                <div class="stat-card-footer">
                    <span class="<?php echo $passing_rate >= 75 ? 'trend-pill up' : 'trend-pill down'; ?>">
                        <?php echo $passing_rate >= 75 ? '↑ High' : '↓ Low'; ?>
                    </span>
                    <span><?php echo $passed_count; ?> Passed / <?php echo $failed_count; ?> Failed</span>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Active Classes Summary Table (Clean Light Table) -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-building me-2 text-success"></i>Active Class Offerings</span>
                <a href="classes.php" class="btn btn-sm btn-subtle">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Term</th>
                            <th>Instructor</th>
                            <th class="text-center">Students</th>
                            <th class="text-center">Sessions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_classes)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No classes on record.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_classes as $rc): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo e($rc['subject_code']); ?></div>
                                        <small class="text-muted"><?php echo e($rc['subject_name']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo e($rc['school_year']); ?> <?php echo e($rc['school_semester']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-secondary fw-medium"><?php echo e($rc['instructor_name']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary rounded-pill"><?php echo $rc['student_count']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary rounded-pill"><?php echo $rc['session_count']; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
                            </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
