<?php
/**
 * Dean — Class Offerings
 * 
 * Inspect class assignments, instructor departments, grading setup readiness, and audits.
 * Donezo light design system.
 */

$page_title = 'Class Offerings';
require_once __DIR__ . '/../includes/header.php';
require_role(['dean']);

$pdo = db_connect();

$year_filter = trim($_GET['school_year'] ?? '');
$sem_filter  = trim($_GET['school_semester'] ?? '');

$year_stmt = $pdo->query('SELECT DISTINCT school_year FROM classes ORDER BY school_year DESC');
$available_years = $year_stmt->fetchAll(PDO::FETCH_COLUMN);

$query = 'SELECT c.class_id, c.school_year, c.school_semester, c.passing_threshold,
                 s.subject_code, s.subject_name,
                 i.first_name, i.last_name, i.department,
                 COUNT(DISTINCT sc.student_id) AS student_count,
                 COUNT(DISTINCT cs.session_id) AS session_count,
                 COALESCE(SUM(gc.weight_percentage), 0) AS total_weight
          FROM classes c
          JOIN subjects s ON c.subject_id = s.subject_id
          JOIN instructors i ON c.instructor_id = i.instructor_id
          LEFT JOIN student_classes sc ON c.class_id = sc.class_id
          LEFT JOIN class_sessions cs ON c.class_id = cs.class_id
          LEFT JOIN grading_components gc ON c.class_id = gc.class_id
          WHERE 1=1';

$params = [];

if ($year_filter !== '') {
    $query .= ' AND c.school_year = ?';
    $params[] = $year_filter;
}

if ($sem_filter !== '') {
    $query .= ' AND c.school_semester = ?';
    $params[] = $sem_filter;
}

$query .= ' GROUP BY c.class_id ORDER BY c.school_year DESC, c.school_semester ASC, s.subject_code ASC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$classes = $stmt->fetchAll();
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-building text-success me-1"></i>Class Offerings
        </h1>
        <p class="page-subtitle">Inspect scheduled class sections, faculty assignments, student load, and grading readiness.</p>
    </div>
    <div>
        <span class="badge bg-secondary px-3 py-2">
            Total Classes: <strong class="text-dark ms-1"><?php echo count($classes); ?></strong>
        </span>
    </div>
</div>

<!-- Term Filters Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="classes.php" class="row g-2 align-items-center">
            <div class="col-md-4">
                <select name="school_year" class="form-select">
                    <option value="">All Academic Years</option>
                    <?php foreach ($available_years as $yr): ?>
                        <option value="<?php echo e($yr); ?>" <?php echo ($year_filter === $yr) ? 'selected' : ''; ?>>
                            <?php echo e($yr); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <select name="school_semester" class="form-select">
                    <option value="">All Semesters</option>
                    <option value="1st" <?php echo ($sem_filter === '1st') ? 'selected' : ''; ?>>1st Semester</option>
                    <option value="2nd" <?php echo ($sem_filter === '2nd') ? 'selected' : ''; ?>>2nd Semester</option>
                    <option value="Summer" <?php echo ($sem_filter === 'Summer') ? 'selected' : ''; ?>>Summer</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-forest flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <?php if ($year_filter !== '' || $sem_filter !== ''): ?>
                    <a href="classes.php" class="btn btn-subtle">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Class Table (Clean Light Data Table) -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Subject</th>
                    <th>Term</th>
                    <th>Instructor</th>
                    <th class="text-center">Threshold</th>
                    <th class="text-center">Enrollment</th>
                    <th class="text-center">Sessions</th>
                    <th class="text-center">Grading Setup</th>
                    <th class="text-center">Audit Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($classes)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-subtle"></i>
                            No classes found for the selected term.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($classes as $idx => $cls): 
                        $weight = (float)$cls['total_weight'];
                        $is_setup_ready = (abs($weight - 100.0) < 0.01);
                    ?>
                        <tr>
                            <td class="text-muted small"><?php echo $idx + 1; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($cls['subject_code']); ?></div>
                                <small class="text-muted"><?php echo e($cls['subject_name']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo e($cls['school_year']); ?> <?php echo e($cls['school_semester']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo e($cls['last_name'] . ', ' . $cls['first_name']); ?></div>
                                <small class="text-muted"><?php echo e($cls['department']); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold text-dark"><?php echo number_format($cls['passing_threshold'], 1); ?>%</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary">
                                    <?php echo $cls['student_count']; ?> Students
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary">
                                    <?php echo $cls['session_count']; ?> Sessions
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($is_setup_ready): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle me-1"></i>100% Ready
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning">
                                        <?php echo number_format($weight, 1); ?>% Incomplete
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="attendance.php?class_id=<?php echo $cls['class_id']; ?>" class="btn btn-subtle btn-sm" title="View Attendance Audit">
                                        <i class="bi bi-calendar-check text-success"></i>
                                    </a>
                                    <a href="grades.php?class_id=<?php echo $cls['class_id']; ?>" class="btn btn-subtle btn-sm" title="View Grades Audit">
                                        <i class="bi bi-award text-dark"></i>
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
