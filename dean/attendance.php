<?php
/**
 * Dean — Attendance Audit
 * 
 * Institution-wide attendance monitoring, compliance metrics, and log inspection.
 * Donezo light design system.
 */

$page_title = 'Attendance Audit';
require_once __DIR__ . '/../includes/header.php';
require_role(['dean']);

$pdo = db_connect();

$class_id = (int)($_GET['class_id'] ?? 0);
$status_filter = trim($_GET['status'] ?? '');
$date_filter = trim($_GET['date'] ?? '');

$classes_stmt = $pdo->query(
    'SELECT c.class_id, s.subject_code, s.subject_name,
            CONCAT(i.last_name, ", ", i.first_name) AS instructor_name
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     JOIN instructors i ON c.instructor_id = i.instructor_id
     ORDER BY s.subject_code ASC'
);
$all_classes = $classes_stmt->fetchAll();

$sql = 'SELECT a.attendance_id, a.status, a.recorded_at,
               cs.session_date, cs.session_time, cs.start_time, cs.end_time,
               s.subject_code, s.subject_name,
               CONCAT(i.last_name, ", ", i.first_name) AS instructor_name,
               st.student_number, st.first_name, st.last_name, st.yr_section
        FROM attendance a
        JOIN class_sessions cs ON a.session_id = cs.session_id
        JOIN classes c ON cs.class_id = c.class_id
        JOIN subjects s ON c.subject_id = s.subject_id
        JOIN instructors i ON c.instructor_id = i.instructor_id
        JOIN student_classes sc ON a.student_class_id = sc.student_class_id
        JOIN students st ON sc.student_id = st.student_id
        WHERE 1=1';

$params = [];

if ($class_id > 0) {
    $sql .= ' AND c.class_id = ?';
    $params[] = $class_id;
}

if ($status_filter !== '') {
    $sql .= ' AND a.status = ?';
    $params[] = $status_filter;
}

if ($date_filter !== '') {
    $sql .= ' AND cs.session_date = ?';
    $params[] = $date_filter;
}

$sql .= ' ORDER BY cs.session_date DESC, a.recorded_at DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$summary_sql = 'SELECT 
    COUNT(*) AS total_count,
    SUM(CASE WHEN a.status = "Present" THEN 1 ELSE 0 END) AS present_count,
    SUM(CASE WHEN a.status = "Absent" THEN 1 ELSE 0 END) AS absent_count,
    SUM(CASE WHEN a.status = "Late" THEN 1 ELSE 0 END) AS late_count,
    SUM(CASE WHEN a.status = "Excused" THEN 1 ELSE 0 END) AS excused_count
FROM attendance a
JOIN class_sessions cs ON a.session_id = cs.session_id
JOIN classes c ON cs.class_id = c.class_id
WHERE 1=1';

$summary_params = [];
if ($class_id > 0) {
    $summary_sql .= ' AND c.class_id = ?';
    $summary_params[] = $class_id;
}
if ($date_filter !== '') {
    $summary_sql .= ' AND cs.session_date = ?';
    $summary_params[] = $date_filter;
}

$stmt_sum = $pdo->prepare($summary_sql);
$stmt_sum->execute($summary_params);
$stats = $stmt_sum->fetch();

$total = (int)($stats['total_count'] ?? 0);
$present = (int)($stats['present_count'] ?? 0);
$absent = (int)($stats['absent_count'] ?? 0);
$late = (int)($stats['late_count'] ?? 0);
$excused = (int)($stats['excused_count'] ?? 0);
$att_rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0;
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-calendar-check text-success me-1"></i>Attendance Audit
        </h1>
        <p class="page-subtitle">Cross-departmental attendance log, session compliance monitor, and institutional presence rate.</p>
    </div>
    <div>
        <span class="badge bg-secondary px-3 py-2">
            Records Shown: <strong class="text-dark ms-1"><?php echo count($records); ?></strong>
        </span>
    </div>
</div>

<!-- Attendance KPI Cards (Donezo Style) -->
<div class="row g-3 mb-4">
    <!-- Hero Card: Total Records & Rate -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card stat-card-hero">
            <div class="stat-card-header">
                <span class="stat-card-title">ATTENDANCE RATE</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value"><?php echo $att_rate; ?>%</div>
            <div class="stat-card-footer">
                <span class="trend-pill up">Overall</span>
                <span><?php echo number_format($total); ?> logged entries</span>
            </div>
        </div>
    </div>

    <!-- Present Card -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">PRESENT</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value text-dark"><?php echo number_format($present); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill up">Present</span>
                <span><?php echo $total > 0 ? round(($present / $total) * 100, 1) : 0; ?>% of total</span>
            </div>
        </div>
    </div>

    <!-- Absences Card -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">ABSENCES</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value text-dark"><?php echo number_format($absent); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill down">Absent</span>
                <span><?php echo $total > 0 ? round(($absent / $total) * 100, 1) : 0; ?>% absence rate</span>
            </div>
        </div>
    </div>

    <!-- Late & Excused Card -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">LATE & EXCUSED</span>
                <span class="stat-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="stat-card-value text-dark"><?php echo number_format($late + $excused); ?></div>
            <div class="stat-card-footer">
                <span class="badge bg-warning"><?php echo $late; ?> Late</span>
                <span>&bull; <?php echo $excused; ?> Excused</span>
            </div>
        </div>
    </div>
</div>

<!-- Filters Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="attendance.php" class="row g-2 align-items-center">
            <div class="col-md-4">
                <select name="class_id" class="form-select">
                    <option value="">All Classes</option>
                    <?php foreach ($all_classes as $c): ?>
                        <option value="<?php echo $c['class_id']; ?>" <?php echo ($class_id === (int)$c['class_id']) ? 'selected' : ''; ?>>
                            <?php echo e($c['subject_code']); ?> — <?php echo e($c['subject_name']); ?> (<?php echo e($c['instructor_name']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="Present" <?php echo ($status_filter === 'Present') ? 'selected' : ''; ?>>Present</option>
                    <option value="Absent" <?php echo ($status_filter === 'Absent') ? 'selected' : ''; ?>>Absent</option>
                    <option value="Late" <?php echo ($status_filter === 'Late') ? 'selected' : ''; ?>>Late</option>
                    <option value="Excused" <?php echo ($status_filter === 'Excused') ? 'selected' : ''; ?>>Excused</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date" class="form-control" value="<?php echo e($date_filter); ?>">
            </div>
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-start-0" placeholder="Live filter student/subject..." data-table-search="deanAttendanceTable">
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-forest flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <?php if ($class_id > 0 || $status_filter !== '' || $date_filter !== ''): ?>
                    <a href="attendance.php" class="btn btn-subtle">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Records Table (Clean Light Data Table) -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive table-responsive-sticky">
        <table class="table table-hover align-middle mb-0" id="deanAttendanceTable">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Date & Time</th>
                    <th>Subject</th>
                    <th>Instructor</th>
                    <th>Student Number</th>
                    <th>Student Name</th>
                    <th>Yr & Sec</th>
                    <th class="text-center">Status</th>
                    <th>Recorded At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-calendar-x fs-2 d-block mb-2 text-subtle"></i>
                            No attendance records match your filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $idx => $r): ?>
                        <tr>
                            <td class="text-muted small"><?php echo $idx + 1; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo date('M d, Y', strtotime($r['session_date'])); ?></div>
                                <small class="text-muted"><?php 
                                $st = $r['start_time'] ?? $r['session_time'] ?? null;
                                $et = $r['end_time'] ?? null;
                                if ($st && $et) {
                                    echo date('h:i A', strtotime($st)) . ' – ' . date('h:i A', strtotime($et));
                                } elseif ($st) {
                                    echo date('h:i A', strtotime($st));
                                } else {
                                    echo 'Regular';
                                }
                                ?></small>
                            </td>
                            <td>
                                <span class="badge bg-primary"><?php echo e($r['subject_code']); ?></span>
                            </td>
                            <td><span class="text-secondary small"><?php echo e($r['instructor_name']); ?></span></td>
                            <td>
                                <span class="font-monospace fw-bold text-dark"><?php echo e($r['student_number']); ?></span>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo e($r['last_name'] . ', ' . $r['first_name']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo e($r['yr_section']); ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($r['status'] === 'Present'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Present</span>
                                <?php elseif ($r['status'] === 'Absent'): ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Absent</span>
                                <?php elseif ($r['status'] === 'Late'): ?>
                                    <span class="badge bg-warning"><i class="bi bi-clock me-1"></i>Late</span>
                                <?php else: ?>
                                    <span class="badge bg-info"><i class="bi bi-info-circle me-1"></i>Excused</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo date('M d, H:i', strtotime($r['recorded_at'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
