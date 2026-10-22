<?php
/**
 * Instructor — Record Attendance
 * 
 * Matrix interface for marking student attendance per session.
 * Donezo light design system with bulk shortcut and status accents.
 */

require_once __DIR__ . '/../includes/permissions.php';
require_role(['instructor']);

$pdo = db_connect();
$instructor = get_current_instructor($pdo);

// Fetch classes assigned to this instructor
$stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name
     FROM classes c JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.instructor_id = ?
     ORDER BY c.school_year DESC, s.subject_code ASC'
);
$stmt->execute([$instructor['instructor_id']]);
$my_classes = $stmt->fetchAll();

// Selected class & session
$class_id = (int)($_GET['class_id'] ?? ($my_classes[0]['class_id'] ?? 0));
$session_id = (int)($_GET['session_id'] ?? 0);

// Verify ownership
if ($class_id > 0 && !instructor_owns_class($pdo, $class_id)) {
    set_flash('danger', 'You do not have permission to manage this class.');
    header('Location: classes.php');
    exit;
}

// Fetch sessions for the selected class
$sessions_list = [];
if ($class_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT session_id, session_date, session_time, start_time, end_time
         FROM class_sessions
         WHERE class_id = ?
         ORDER BY session_date DESC, start_time DESC, session_time DESC'
    );
    $stmt->execute([$class_id]);
    $sessions_list = $stmt->fetchAll();

    // Default to most recent session if none selected
    if ($session_id === 0 && !empty($sessions_list)) {
        $session_id = (int)$sessions_list[0]['session_id'];
    }
}

// Handle attendance save form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class_id > 0 && $session_id > 0) {
    verify_csrf();
    $statuses = $_POST['status'] ?? [];

    $pdo->beginTransaction();
    try {
        $stmt_check = $pdo->prepare(
            'SELECT attendance_id, status FROM attendance
             WHERE student_class_id = ? AND session_id = ?'
        );
        $stmt_insert = $pdo->prepare(
            'INSERT INTO attendance (student_class_id, session_id, status) VALUES (?, ?, ?)'
        );
        $stmt_update = $pdo->prepare(
            'UPDATE attendance SET status = ?, recorded_at = NOW() WHERE attendance_id = ?'
        );

        $notif_stmt = $pdo->prepare(
            'INSERT INTO notifications (student_id, attendance_id, notification_type, message) VALUES (?, ?, ?, ?)'
        );
        $student_lookup = $pdo->prepare('SELECT student_id FROM student_classes WHERE student_class_id = ?');

        // Session info for notification message
        $sess_stmt = $pdo->prepare('SELECT session_date FROM class_sessions WHERE session_id = ?');
        $sess_stmt->execute([$session_id]);
        $sess_date = $sess_stmt->fetchColumn() ?: date('Y-m-d');

        $cls_stmt = $pdo->prepare('SELECT s.subject_code FROM classes c JOIN subjects s ON c.subject_id = s.subject_id WHERE c.class_id = ?');
        $cls_stmt->execute([$class_id]);
        $subj_code = $cls_stmt->fetchColumn() ?: 'Subject';

        foreach ($statuses as $sc_id => $st) {
            $sc_id = (int)$sc_id;
            if (!in_array($st, ['Present', 'Absent', 'Late', 'Excused'], true)) {
                continue;
            }

            $stmt_check->execute([$sc_id, $session_id]);
            $existing = $stmt_check->fetch();

            if ($existing) {
                if ($existing['status'] !== $st) {
                    $stmt_update->execute([$st, $existing['attendance_id']]);

                    // Send notification if newly marked Absent
                    if ($st === 'Absent') {
                        $student_lookup->execute([$sc_id]);
                        $sid = (int)$student_lookup->fetchColumn();
                        if ($sid > 0) {
                            $dup_check = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE attendance_id = ?');
                            $dup_check->execute([$existing['attendance_id']]);
                            if ((int)$dup_check->fetchColumn() === 0) {
                                $notif_stmt->execute([
                                    $sid,
                                    $existing['attendance_id'],
                                    'absence',
                                    "You were marked Absent for $subj_code on $sess_date."
                                ]);
                            }
                        }
                    }
                }
            } else {
                $stmt_insert->execute([$sc_id, $session_id, $st]);
                $new_att_id = (int)$pdo->lastInsertId();

                // Send notification on new Absent
                if ($st === 'Absent') {
                    $student_lookup->execute([$sc_id]);
                    $sid = (int)$student_lookup->fetchColumn();
                    if ($sid > 0) {
                        $notif_stmt->execute([
                            $sid,
                            $new_att_id,
                            'absence',
                            "You were marked Absent for $subj_code on $sess_date."
                        ]);
                    }
                }
            }
        }

        // Audit log
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $stmt->execute([
            current_user()['user_id'],
            'ATTENDANCE_RECORDED',
            "Recorded attendance for class_id=$class_id, session_id=$session_id"
        ]);

        $pdo->commit();
        set_flash('success', 'Attendance saved successfully.');
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Attendance save error: ' . $e->getMessage());
        $debug_msg = (defined('APP_DEBUG') && APP_DEBUG) ? ': ' . $e->getMessage() : '.';
        set_flash('danger', 'An error occurred while saving attendance' . $debug_msg);
    }

    header("Location: attendance.php?class_id=$class_id&session_id=$session_id");
    exit;
}

// Fetch enrolled students with existing attendance for this session
$students = [];
if ($class_id > 0 && $session_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT sc.student_class_id, st.student_number, st.first_name, st.middle_name, st.last_name,
                a.status AS current_status
         FROM student_classes sc
         JOIN students st ON sc.student_id = st.student_id
         LEFT JOIN attendance a ON a.student_class_id = sc.student_class_id AND a.session_id = ?
         WHERE sc.class_id = ?
         ORDER BY st.last_name, st.first_name'
    );
    $stmt->execute([$session_id, $class_id]);
    $students = $stmt->fetchAll();
}

$class_info = null;
if ($class_id > 0) {
    $stmt = $pdo->prepare('SELECT c.*, s.subject_code, s.subject_name FROM classes c JOIN subjects s ON c.subject_id = s.subject_id WHERE c.class_id = ?');
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch();
}

$session_info = null;
if ($session_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM class_sessions WHERE session_id = ?');
    $stmt->execute([$session_id]);
    $session_info = $stmt->fetch();
}

$page_title = 'Record Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-calendar-check text-success me-1"></i>Record Attendance
        </h1>
        <p class="page-subtitle">Mark student attendance per session. Absence alerts are sent automatically.</p>
    </div>
    <div>
        <?php if ($session_info): ?>
            <span class="badge bg-secondary px-3 py-2">
                Session: <strong class="text-dark ms-1"><?php 
                    echo date('M d, Y', strtotime($session_info['session_date']));
                    $st = $session_info['start_time'] ?? $session_info['session_time'] ?? null;
                    $et = $session_info['end_time'] ?? null;
                    if ($st && $et) {
                        echo ' (' . date('h:i A', strtotime($st)) . ' – ' . date('h:i A', strtotime($et)) . ')';
                    } elseif ($st) {
                        echo ' (' . date('h:i A', strtotime($st)) . ')';
                    }
                ?></strong>
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Class & Session Selectors Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-<?php echo $class_id > 0 ? '5' : '6'; ?>">
                <label class="form-label small text-muted mb-1">Class Section</label>
                <select name="class_id" class="form-select" onchange="this.form.querySelector('[name=session_id]').value=''; this.form.submit();">
                    <option value="">— Choose a class —</option>
                    <?php foreach ($my_classes as $cls): ?>
                        <option value="<?php echo $cls['class_id']; ?>" <?php echo $cls['class_id'] == $class_id ? 'selected' : ''; ?>>
                            <?php echo e($cls['subject_code'] . ' — ' . $cls['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-<?php echo $class_id > 0 ? '5' : '6'; ?>">
                <label class="form-label small text-muted mb-1">Meeting Date / Session</label>
                <select name="session_id" class="form-select" onchange="this.form.submit();" <?php echo empty($sessions_list) ? 'disabled' : ''; ?>>
                    <option value="">— Choose a session —</option>
                    <?php foreach ($sessions_list as $sess): ?>
                        <option value="<?php echo $sess['session_id']; ?>" <?php echo $sess['session_id'] == $session_id ? 'selected' : ''; ?>>
                            <?php 
                            $date_label = date('M d, Y', strtotime($sess['session_date']));
                            $st = $sess['start_time'] ?? $sess['session_time'] ?? null;
                            $et = $sess['end_time'] ?? null;
                            $time_label = '';
                            if ($st && $et) {
                                $time_label = ' (' . date('h:i A', strtotime($st)) . ' – ' . date('h:i A', strtotime($et)) . ')';
                            } elseif ($st) {
                                $time_label = ' at ' . date('h:i A', strtotime($st));
                            }
                            echo e($date_label . $time_label);
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($class_id > 0): ?>
                <div class="col-md-2">
                    <a href="../api/export_attendance.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle text-success w-100" title="Export attendance records to Excel / CSV">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i> Export Excel
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php 
// Calculate initial session KPI metrics
$pres_cnt = 0; $abs_cnt = 0; $late_cnt = 0; $exc_cnt = 0;
foreach ($students as $stu) {
    $st = $stu['current_status'] ?? '';
    if ($st === 'Present') $pres_cnt++;
    elseif ($st === 'Absent') $abs_cnt++;
    elseif ($st === 'Late') $late_cnt++;
    elseif ($st === 'Excused') $exc_cnt++;
}
$tot_stu = count($students);
$att_rate = $tot_stu > 0 ? round((($pres_cnt + $late_cnt) / $tot_stu) * 100, 1) : 0;
?>

<?php if ($class_id > 0 && $session_id > 0 && !empty($students)): ?>
    <!-- Live Session Attendance KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <span class="text-muted small fw-semibold text-uppercase">Present</span>
                <h3 class="fw-bold mt-1 mb-0 text-success" id="metricPresent"><?php echo $pres_cnt; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <span class="text-muted small fw-semibold text-uppercase">Absent</span>
                <h3 class="fw-bold mt-1 mb-0 text-danger" id="metricAbsent"><?php echo $abs_cnt; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <span class="text-muted small fw-semibold text-uppercase">Late</span>
                <h3 class="fw-bold mt-1 mb-0 text-warning" id="metricLate"><?php echo $late_cnt; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <span class="text-muted small fw-semibold text-uppercase">Excused</span>
                <h3 class="fw-bold mt-1 mb-0 text-info" id="metricExcused"><?php echo $exc_cnt; ?></h3>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-dark text-white">
                <div>
                    <span class="text-white-50 small fw-semibold text-uppercase">Session Attendance Rate</span>
                    <h3 class="fw-bold mt-1 mb-0 text-white" id="metricRate"><?php echo $att_rate; ?>%</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Rapid Roll-Call Shortcut Helper Bar (UI/UX Pro Max) -->
    <div class="ams-shortcut-helper mb-3">
        <div class="d-flex align-items-center gap-1 fw-bold text-dark">
            <i class="bi bi-lightning-charge text-success me-1"></i> Rapid Roll-Call Mode:
        </div>
        <div>
            <span class="ams-kbd">P</span> or <span class="ams-kbd">1</span> Present
        </div>
        <div>
            <span class="ams-kbd">A</span> or <span class="ams-kbd">2</span> Absent
        </div>
        <div>
            <span class="ams-kbd">L</span> or <span class="ams-kbd">3</span> Late
        </div>
        <div>
            <span class="ams-kbd">E</span> or <span class="ams-kbd">4</span> Excused
        </div>
        <div>
            <span class="ams-kbd">↓</span> <span class="ams-kbd">↑</span> Navigate Student
        </div>
    </div>

    <!-- Student Roster Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-dark fs-6"><i class="bi bi-people me-2 text-success"></i>Student Roster</span>
                <span class="badge bg-secondary" data-count-for="attendanceRosterTable"><?php echo count($students); ?> enrolled</span>
                <div class="att-summary d-none d-md-flex align-items-center gap-1" id="attendanceTally" aria-live="polite">
                    <span class="badge bg-success">Present <strong class="ms-1" data-count="Present"><?php echo $pres_cnt; ?></strong></span>
                    <span class="badge bg-danger">Absent <strong class="ms-1" data-count="Absent"><?php echo $abs_cnt; ?></strong></span>
                    <span class="badge bg-warning">Late <strong class="ms-1" data-count="Late"><?php echo $late_cnt; ?></strong></span>
                    <span class="badge bg-info">Excused <strong class="ms-1" data-count="Excused"><?php echo $exc_cnt; ?></strong></span>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="input-group input-group-sm" style="max-width: 220px;">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control form-control-sm border-start-0" placeholder="Filter student..." data-table-search="attendanceRosterTable">
                </div>
                <button type="button" class="btn btn-sm btn-subtle fw-semibold px-3" id="markAllPresent" data-session-id="<?php echo $session_id; ?>">
                    <i class="bi bi-check-all text-success me-1 fs-6"></i> Mark All Present
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="table-responsive table-responsive-sticky">
                    <table class="table table-hover align-middle mb-0" id="attendanceRosterTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="min-width: 140px;">Student Number</th>
                                <th style="min-width: 220px;">Student Full Name</th>
                                <th class="text-center" style="min-width: 320px;">Attendance Status (Click or Press Key)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $i => $stu): 
                                $sc_id = (int)$stu['student_class_id'];
                                $cur_st = $stu['current_status'] ?? '';
                                $s_name = $stu['last_name'] . ', ' . $stu['first_name'];
                            ?>
                                <tr data-attendance-row="true">
                                    <td class="text-muted small"><?php echo $i + 1; ?></td>
                                    <td>
                                        <span class="font-monospace fw-bold text-dark"><?php echo e($stu['student_number']); ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark">
                                            <?php echo e($stu['last_name'] . ', ' . $stu['first_name'] . ($stu['middle_name'] ? ' ' . $stu['middle_name'] : '')); ?>
                                        </div>
                                    </td>
                                    <td class="text-center py-2">
                                        <!-- Interactive Real-time Pills -->
                                        <div class="attendance-pills-group" data-sc-id="<?php echo $sc_id; ?>">
                                            <?php foreach (['Present', 'Absent', 'Late', 'Excused'] as $status): 
                                                $is_active = ($cur_st === $status);
                                                $icon = match($status) {
                                                    'Present' => 'bi-check-lg',
                                                    'Absent'  => 'bi-x-lg',
                                                    'Late'    => 'bi-clock',
                                                    'Excused' => 'bi-info-circle',
                                                };
                                            ?>
                                                <button type="button" 
                                                        class="attendance-pill-btn <?php echo $is_active ? 'active' : ''; ?>"
                                                        data-sc-id="<?php echo $sc_id; ?>"
                                                        data-session-id="<?php echo $session_id; ?>"
                                                        data-status="<?php echo $status; ?>"
                                                        data-student-name="<?php echo e($s_name); ?>"
                                                        title="Mark <?php echo $status; ?>">
                                                    <i class="bi <?php echo $icon; ?>"></i> <?php echo $status; ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>

                                        <!-- Hidden Radio inputs for form submission -->
                                        <div style="display:none;">
                                            <?php foreach (['Present', 'Absent', 'Late', 'Excused'] as $status): ?>
                                                <input type="radio"
                                                       name="status[<?php echo $sc_id; ?>]"
                                                       value="<?php echo $status; ?>"
                                                       <?php echo ($cur_st === $status) ? 'checked' : ''; ?>>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-top bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i> Make your selections above using the status buttons or keyboard shortcuts, then click <strong>Save Attendance</strong>.</small>
                    <button type="submit" class="btn btn-forest px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($class_id > 0 && $session_id > 0 && empty($students)): ?>
    <div class="card p-5 text-center text-muted">
        <i class="bi bi-people fs-1 d-block mb-3 text-subtle"></i>
        <h5>No Students Enrolled</h5>
        <p class="small text-muted mb-0">No active student enrollments found in this course section.</p>
    </div>
<?php elseif ($class_id > 0 && $session_id === 0): ?>
    <div class="card p-5 text-center text-muted">
        <i class="bi bi-calendar-event fs-1 d-block mb-3 text-success"></i>
        <h5>Select or Schedule a Session</h5>
        <p class="small text-muted mb-0">Choose an existing class meeting session above to take attendance, or create one in Class Sessions.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
