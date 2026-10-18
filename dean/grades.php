<?php
/**
 * Dean — Gradebook Audit
 * 
 * Inspect class component scores, weighted final grades, and Pass/Fail performance.
 * Donezo light design system.
 */

$page_title = 'Gradebook Audit';
require_once __DIR__ . '/../includes/header.php';
require_role(['dean']);

$pdo = db_connect();

$class_id = (int)($_GET['class_id'] ?? 0);
$status_filter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

$classes_stmt = $pdo->query(
    'SELECT c.class_id, c.school_year, c.school_semester, c.passing_threshold,
            s.subject_code, s.subject_name,
            CONCAT(i.last_name, ", ", i.first_name) AS instructor_name
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     JOIN instructors i ON c.instructor_id = i.instructor_id
     ORDER BY c.school_year DESC, s.subject_code ASC'
);
$all_classes = $classes_stmt->fetchAll();

if ($class_id <= 0 && !empty($all_classes)) {
    $class_id = (int)$all_classes[0]['class_id'];
}

$selected_class = null;
foreach ($all_classes as $c) {
    if ((int)$c['class_id'] === $class_id) {
        $selected_class = $c;
        break;
    }
}

$components_stmt = $pdo->prepare('SELECT * FROM grading_components WHERE class_id = ? ORDER BY component_id ASC');
$components_stmt->execute([$class_id]);
$components = $components_stmt->fetchAll();

$students_sql = 'SELECT sc.student_class_id, st.student_id, st.student_number, st.first_name, st.middle_name, st.last_name, st.yr_section,
                        fg.final_grade, fg.status AS final_status, fg.finalized_at,
                        u_fin.username AS finalized_by_user
                 FROM student_classes sc
                 JOIN students st ON sc.student_id = st.student_id
                 LEFT JOIN final_grades fg ON sc.student_class_id = fg.student_class_id
                 LEFT JOIN users u_fin ON fg.finalized_by = u_fin.user_id
                 WHERE sc.class_id = ?';

$students_params = [$class_id];

if ($status_filter !== '') {
    $students_sql .= ' AND fg.status = ?';
    $students_params[] = $status_filter;
}

if ($search !== '') {
    $students_sql .= ' AND (st.student_number LIKE ? OR st.first_name LIKE ? OR st.last_name LIKE ?)';
    $term = "%$search%";
    $students_params[] = $term;
    $students_params[] = $term;
    $students_params[] = $term;
}

$students_sql .= ' ORDER BY st.last_name ASC, st.first_name ASC';

$stmt = $pdo->prepare($students_sql);
$stmt->execute($students_params);
$students = $stmt->fetchAll();

$raw_grades = [];
if ($class_id > 0 && !empty($students)) {
    $sc_ids = array_column($students, 'student_class_id');
    $in_placeholders = implode(',', array_fill(0, count($sc_ids), '?'));
    $stmt = $pdo->prepare("SELECT student_class_id, component_id, score FROM grades WHERE student_class_id IN ($in_placeholders)");
    $stmt->execute($sc_ids);
    foreach ($stmt->fetchAll() as $row) {
        $raw_grades[$row['student_class_id']][$row['component_id']] = (float)$row['score'];
    }
}

$total_enrolled = count($students);
$pass_count = 0;
$fail_count = 0;
$pending_count = 0;
foreach ($students as $st) {
    if ($st['final_status'] === 'Pass') $pass_count++;
    elseif ($st['final_status'] === 'Fail') $fail_count++;
    else $pending_count++;
}
$pass_rate = $total_enrolled > 0 ? round(($pass_count / $total_enrolled) * 100, 1) : 0;
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-award text-success me-1"></i>Gradebook Audit
        </h1>
        <p class="page-subtitle">Institutional inspection of component scores, computed final grades, and Pass/Fail standings.</p>
    </div>
    <div>
        <span class="badge bg-secondary px-3 py-2">
            Passing Threshold: <strong class="text-dark ms-1"><?php echo $selected_class ? number_format($selected_class['passing_threshold'], 1) : '75.0'; ?>%</strong>
        </span>
    </div>
</div>

<!-- Class Filter Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="grades.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($all_classes as $c): ?>
                        <option value="<?php echo $c['class_id']; ?>" <?php echo ($class_id === (int)$c['class_id']) ? 'selected' : ''; ?>>
                            <?php echo e($c['subject_code']); ?> — <?php echo e($c['subject_name']); ?> (<?php echo e($c['instructor_name']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="Pass" <?php echo ($status_filter === 'Pass') ? 'selected' : ''; ?>>Passed</option>
                    <option value="Fail" <?php echo ($status_filter === 'Fail') ? 'selected' : ''; ?>>Failed</option>
                    <option value="Pending" <?php echo ($status_filter === 'Pending') ? 'selected' : ''; ?>>Pending / Unfinalized</option>
                </select>
            </div>
            <div class="col-md-4">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="Search student name / #..." value="<?php echo e($search); ?>">
                    <button type="submit" class="btn btn-forest"><i class="bi bi-search"></i></button>
                    <?php if ($status_filter !== '' || $search !== ''): ?>
                        <a href="grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle">Reset</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- KPI Metrics for Selected Class (Donezo Style) -->
<div class="row g-3 mb-4">
    <!-- Hero Card: Pass Rate -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card stat-card-hero">
            <div class="stat-card-header">
                <span class="stat-card-title">CLASS PASS RATE</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value"><?php echo $pass_rate; ?>%</div>
            <div class="stat-card-footer">
                <span class="trend-pill up">Finalized</span>
                <span><?php echo $pass_count; ?> Passed / <?php echo $fail_count; ?> Failed</span>
            </div>
        </div>
    </div>

    <!-- Total Enrolled Card -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">TOTAL ENROLLED</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value text-dark"><?php echo $total_enrolled; ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill up">Active</span>
                <span><?php echo e($selected_class['subject_code'] ?? ''); ?> (<?php echo e($selected_class['school_year'] ?? ''); ?>)</span>
            </div>
        </div>
    </div>

    <!-- Failed Count Card -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">BELOW THRESHOLD</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value text-dark"><?php echo $fail_count; ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill down">Failed</span>
                <span>Under <?php echo number_format($selected_class['passing_threshold'] ?? 75, 0); ?>% passing score</span>
            </div>
        </div>
    </div>

    <!-- Pending Count Card -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">PENDING STATUS</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value text-dark"><?php echo $pending_count; ?></div>
            <div class="stat-card-footer">
                <span class="badge bg-warning">Pending</span>
                <span>Scores not locked yet</span>
            </div>
        </div>
    </div>
</div>

<!-- Gradebook Table (Clean Light Data Table) -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Student Number</th>
                    <th>Student Name</th>
                    <th>Yr & Sec</th>
                    <?php foreach ($components as $comp): ?>
                        <th class="text-center" style="font-size: 0.75rem;">
                            <?php echo e($comp['component_name']); ?>
                            <div class="text-muted small fw-normal">(<?php echo number_format($comp['weight_percentage'], 0); ?>% - Max <?php echo number_format($comp['maximum_score'], 0); ?>)</div>
                        </th>
                    <?php endforeach; ?>
                    <th class="text-center">Final Grade</th>
                    <th class="text-center">Status</th>
                    <th>Finalized By & Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="<?php echo count($components) + 7; ?>" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-subtle"></i>
                            No student records found matching this filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $idx => $st): 
                        $sc_id = $st['student_class_id'];
                    ?>
                        <tr>
                            <td class="text-muted small"><?php echo $idx + 1; ?></td>
                            <td>
                                <span class="font-monospace fw-bold text-dark"><?php echo e($st['student_number']); ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($st['last_name'] . ', ' . $st['first_name'] . ' ' . $st['middle_name']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo e($st['yr_section']); ?></span>
                            </td>
                            <?php foreach ($components as $comp): 
                                $cid = $comp['component_id'];
                                $score = isset($raw_grades[$sc_id][$cid]) ? $raw_grades[$sc_id][$cid] : null;
                            ?>
                                <td class="text-center">
                                    <?php if ($score !== null): ?>
                                        <span class="fw-semibold text-dark"><?php echo number_format($score, 1); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <?php if ($st['final_grade'] !== null): ?>
                                    <span class="fs-6 fw-bold <?php echo $st['final_grade'] >= ($selected_class['passing_threshold'] ?? 75) ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($st['final_grade'], 2); ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($st['final_status'] === 'Pass'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Pass</span>
                                <?php elseif ($st['final_status'] === 'Fail'): ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Fail</span>
                                <?php else: ?>
                                    <span class="badge bg-warning"><i class="bi bi-clock me-1"></i>Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php if (!empty($st['finalized_at'])): ?>
                                    <div><i class="bi bi-calendar-event me-1"></i><?php echo date('M d, Y h:i A', strtotime($st['finalized_at'])); ?></div>
                                    <?php if (!empty($st['finalized_by_user'])): ?>
                                        <span class="text-secondary small">by @<?php echo e($st['finalized_by_user']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
