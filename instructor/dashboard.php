<?php
/**
 * Instructor Dashboard
 * 
 * Displays summary statistics for the logged-in instructor:
 * assigned classes, total students, scheduled sessions, and pending grade entries.
 * Styled in the Donezo visual theme.
 */

$page_title = 'Faculty Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_role(['instructor']);

$pdo = db_connect();
$instructor = get_current_instructor($pdo);
$instructor_id = $instructor['instructor_id'];

// Total assigned classes
$stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE instructor_id = ?');
$stmt->execute([$instructor_id]);
$total_classes = $stmt->fetchColumn();

// Total enrolled students across all classes
$stmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT sc.student_id)
     FROM student_classes sc
     JOIN classes c ON sc.class_id = c.class_id
     WHERE c.instructor_id = ?'
);
$stmt->execute([$instructor_id]);
$total_students = $stmt->fetchColumn();

// Today's sessions
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM class_sessions cs
     JOIN classes c ON cs.class_id = c.class_id
     WHERE c.instructor_id = ? AND cs.session_date = CURDATE()'
);
$stmt->execute([$instructor_id]);
$todays_sessions = $stmt->fetchColumn();

// Un-finalized grades count
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM final_grades fg
     JOIN student_classes sc ON fg.student_class_id = sc.student_class_id
     JOIN classes c ON sc.class_id = c.class_id
     WHERE c.instructor_id = ? AND fg.status = ?'
);
$stmt->execute([$instructor_id, 'Pending']);
$pending_grades = $stmt->fetchColumn();

// Recent classes with details
$stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester,
            (SELECT COUNT(*) FROM student_classes sc2 WHERE sc2.class_id = c.class_id) AS enrolled
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.instructor_id = ?
     ORDER BY c.school_year DESC, c.class_id DESC
     LIMIT 5'
);
$stmt->execute([$instructor_id]);
$recent_classes = $stmt->fetchAll();
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-speedometer2 text-success me-1"></i>Faculty Dashboard
        </h1>
        <p class="page-subtitle">Welcome back, Professor <?php echo e($instructor['last_name']); ?>! Here is your academic schedule and grading overview.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="<?php echo APP_BASE; ?>/instructor/sessions.php" class="btn btn-sm btn-subtle">
            <i class="bi bi-calendar-plus me-1"></i> New Session
        </a>
        <a href="<?php echo APP_BASE; ?>/instructor/grades.php" class="btn btn-sm btn-forest">
            <i class="bi bi-pencil-square me-1"></i> Enter Grades
        </a>
    </div>
</div>

<!-- Stat Cards (Donezo Style: 1 Hero Forest Card + 3 Clean White Cards) -->
<div class="row g-3 mb-4">
    <!-- Hero Card: My Classes -->
    <div class="col-sm-6 col-xl-3">
        <a href="classes.php" class="text-decoration-none d-block h-100">
            <div class="stat-card stat-card-hero">
                <div class="stat-card-header">
                    <span class="stat-card-title">MY CLASSES</span>
                    <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </div>
                <div class="stat-card-value"><?php echo $total_classes; ?></div>
                <div class="stat-card-footer">
                    <span class="trend-pill up">Active</span>
                    <span>Assigned teaching courses</span>
                </div>
            </div>
        </a>
    </div>

    <!-- Total Students Card -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">TOTAL STUDENTS</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value text-dark"><?php echo number_format($total_students); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill up">Enrolled</span>
                <span>Across all course sections</span>
            </div>
        </div>
    </div>

    <!-- Today's Sessions Card -->
    <div class="col-sm-6 col-xl-3">
        <a href="sessions.php" class="text-decoration-none d-block h-100">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">TODAY'S SESSIONS</span>
                    <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </div>
                <div class="stat-card-value text-dark"><?php echo $todays_sessions; ?></div>
                <div class="stat-card-footer">
                    <span class="badge bg-primary">Scheduled Today</span>
                    <span><?php echo date('M d, Y'); ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- Pending Grades Card -->
    <div class="col-sm-6 col-xl-3">
        <a href="finalize-grades.php" class="text-decoration-none d-block h-100">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">PENDING GRADES</span>
                    <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </div>
                <div class="stat-card-value text-dark"><?php echo $pending_grades; ?></div>
                <div class="stat-card-footer">
                    <span class="<?php echo $pending_grades > 0 ? 'badge bg-warning' : 'trend-pill up'; ?>">
                        <?php echo $pending_grades > 0 ? 'Action Required' : '✓ All Locked'; ?>
                    </span>
                    <span>Unfinalized student marks</span>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Recent Classes Table (Clean Light Data Table) -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building me-2 text-success"></i>Assigned Classes</span>
        <a href="<?php echo APP_BASE; ?>/instructor/classes.php" class="btn btn-sm btn-subtle">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Subject Code</th>
                    <th>Subject Title</th>
                    <th>Academic Term</th>
                    <th>Students Enrolled</th>
                    <th class="text-center">Quick Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_classes)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-subtle"></i>
                            No classes currently assigned.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_classes as $cls): ?>
                        <tr>
                            <td>
                                <span class="badge bg-primary fs-6 px-3 py-1 font-monospace">
                                    <?php echo e($cls['subject_code']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($cls['subject_name']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo e($cls['school_year'] . ' — ' . $cls['school_semester']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-dark"><?php echo $cls['enrolled']; ?></span>
                                <span class="text-muted small">students</span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?php echo APP_BASE; ?>/instructor/attendance.php?class_id=<?php echo $cls['class_id']; ?>"
                                       class="btn btn-subtle" title="Take Attendance">
                                        <i class="bi bi-calendar-check text-success me-1"></i> Attendance
                                    </a>
                                    <a href="<?php echo APP_BASE; ?>/instructor/grades.php?class_id=<?php echo $cls['class_id']; ?>"
                                       class="btn btn-subtle" title="Enter Grades">
                                        <i class="bi bi-pencil-square text-dark me-1"></i> Grades
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
