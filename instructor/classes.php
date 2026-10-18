<?php
/**
 * Instructor — My Classes
 * 
 * Lists all classes assigned to the logged-in instructor
 * with subject info, term, enrollment count, and setup status.
 * Donezo light design system.
 */

$page_title = 'My Classes';
require_once __DIR__ . '/../includes/header.php';
require_role(['instructor']);

$pdo = db_connect();
$instructor = get_current_instructor($pdo);

// Fetch all assigned classes
$stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester,
            c.passing_threshold,
            (SELECT COUNT(*) FROM student_classes sc WHERE sc.class_id = c.class_id) AS enrolled,
            (SELECT COUNT(*) FROM class_sessions cs WHERE cs.class_id = c.class_id) AS sessions,
            (SELECT COUNT(*) FROM grading_components gc WHERE gc.class_id = c.class_id) AS components,
            (SELECT COALESCE(SUM(gc2.weight_percentage), 0) FROM grading_components gc2 WHERE gc2.class_id = c.class_id) AS total_weight
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.instructor_id = ?
     ORDER BY c.school_year DESC, c.school_semester DESC, s.subject_code ASC'
);
$stmt->execute([$instructor['instructor_id']]);
$classes = $stmt->fetchAll();
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-building text-success me-1"></i>My Assigned Classes
        </h1>
        <p class="page-subtitle">Manage curriculum courses, student enrollment rosters, and syllabus parameters.</p>
    </div>
    <div>
        <span class="badge bg-secondary px-3 py-2">
            Active Classes: <strong class="text-dark ms-1"><?php echo count($classes); ?></strong>
        </span>
    </div>
</div>

<!-- Class Roster Table (Clean Light Data Table) -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="classesTable">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Subject Code</th>
                    <th>Subject Title</th>
                    <th>Term</th>
                    <th class="text-center">Enrolled</th>
                    <th class="text-center">Sessions</th>
                    <th class="text-center">Grading Setup</th>
                    <th class="text-center">Pass Mark</th>
                    <th class="text-center">Quick Management</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($classes)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-subtle"></i>
                            No classes assigned to your faculty profile.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($classes as $i => $cls): ?>
                        <?php
                        $weight_ok = abs($cls['total_weight'] - 100) < 0.01;
                        ?>
                        <tr>
                            <td class="text-muted small"><?php echo $i + 1; ?></td>
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
                            <td class="text-center">
                                <span class="badge bg-primary"><?php echo $cls['enrolled']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $cls['sessions']; ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($weight_ok): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>100% Ready</span>
                                <?php else: ?>
                                    <span class="badge bg-warning"><?php echo number_format($cls['total_weight'], 1); ?>% Setup</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold text-dark"><?php echo number_format($cls['passing_threshold'], 1); ?>%</span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="sessions.php?class_id=<?php echo $cls['class_id']; ?>" class="btn btn-subtle" title="Sessions"><i class="bi bi-calendar-event text-dark"></i></a>
                                    <a href="attendance.php?class_id=<?php echo $cls['class_id']; ?>" class="btn btn-subtle" title="Attendance"><i class="bi bi-calendar-check text-success"></i></a>
                                    <a href="grading-components.php?class_id=<?php echo $cls['class_id']; ?>" class="btn btn-subtle" title="Weights"><i class="bi bi-sliders text-dark"></i></a>
                                    <a href="grades.php?class_id=<?php echo $cls['class_id']; ?>" class="btn btn-subtle" title="Enter Scores"><i class="bi bi-pencil-square text-dark"></i></a>
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
