<?php
/**
 * Instructor — Class Sessions
 * 
 * Manage meeting dates and schedules for assigned classes.
 * Donezo light design system.
 */

require_once __DIR__ . '/../includes/permissions.php';
require_role(['instructor']);

$pdo = db_connect();
$instructor = get_current_instructor($pdo);

// Fetch instructor's classes for the selector
$stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.instructor_id = ?
     ORDER BY c.school_year DESC, s.subject_code ASC'
);
$stmt->execute([$instructor['instructor_id']]);
$my_classes = $stmt->fetchAll();

// Selected class (defaults to first assigned class)
$class_id = (int)($_GET['class_id'] ?? ($my_classes[0]['class_id'] ?? 0));

// Verify ownership
if ($class_id > 0 && !instructor_owns_class($pdo, $class_id)) {
    set_flash('danger', 'You do not have permission to manage sessions for this class.');
    header('Location: classes.php');
    exit;
}

// Handle form submissions (Add / Delete session)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' && $class_id > 0) {
        $session_date = trim($_POST['session_date'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '') ?: null;
        $end_time = trim($_POST['end_time'] ?? '') ?: null;

        if (empty($session_date)) {
            set_flash('danger', 'Session date is required.');
        } elseif ($start_time && $end_time && $end_time <= $start_time) {
            set_flash('danger', 'End time must be later than start time.');
        } else {
            // Keep session_time in sync with start_time for backwards compatibility
            $session_time = $start_time;
            $stmt = $pdo->prepare(
                'INSERT INTO class_sessions (class_id, session_date, session_time, start_time, end_time) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$class_id, $session_date, $session_time, $start_time, $end_time]);
            set_flash('success', 'Class session added successfully.');
        }
        header("Location: sessions.php?class_id=$class_id");
        exit;
    }

    if ($action === 'delete') {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE session_id = ?');
        $stmt->execute([$session_id]);
        if ($stmt->fetchColumn() > 0) {
            set_flash('warning', 'Cannot delete session — student attendance records already exist.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM class_sessions WHERE session_id = ? AND class_id = ?');
            $stmt->execute([$session_id, $class_id]);
            set_flash('success', 'Session deleted successfully.');
        }
        header("Location: sessions.php?class_id=$class_id");
        exit;
    }
}

// Fetch sessions for the selected class
$sessions = [];
$class_info = null;
if ($class_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT c.*, s.subject_code, s.subject_name
         FROM classes c JOIN subjects s ON c.subject_id = s.subject_id
         WHERE c.class_id = ?'
    );
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT cs.*,
                (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.session_id) AS attendance_count
         FROM class_sessions cs
         WHERE cs.class_id = ?
         ORDER BY cs.session_date DESC, cs.start_time DESC, cs.session_time DESC'
    );
    $stmt->execute([$class_id]);
    $sessions = $stmt->fetchAll();
}

$page_title = 'Class Sessions';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-calendar-event text-success me-1"></i>Class Sessions
        </h1>
        <p class="page-subtitle">Schedule upcoming class meeting dates, times, and manage attendance sessions.</p>
    </div>
    <div>
        <?php if ($class_info): ?>
            <span class="badge bg-primary px-3 py-2">
                Selected: <strong><?php echo e($class_info['subject_code']); ?></strong>
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Class Selector Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-9">
                <label class="form-label small text-muted mb-1">Select Course Section</label>
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choose a class —</option>
                    <?php foreach ($my_classes as $cls): ?>
                        <option value="<?php echo $cls['class_id']; ?>"
                                <?php echo $cls['class_id'] == $class_id ? 'selected' : ''; ?>>
                            <?php echo e($cls['subject_code'] . ' — ' . $cls['subject_name'] . ' (' . $cls['school_year'] . ' ' . $cls['school_semester'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-subtle w-100">
                    <i class="bi bi-arrow-repeat me-1"></i> Switch Class
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($class_id > 0 && $class_info): ?>
    <!-- Add Session Form Card -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-plus-circle me-2 text-success"></i>Schedule New Meeting Session</span>
            <small class="text-muted"><?php echo e($class_info['subject_code']); ?> — <?php echo e($class_info['subject_name']); ?></small>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add">
                <?php echo csrf_field(); ?>
                <div class="col-md-4">
                    <label class="form-label">Session Date <span class="text-danger">*</span></label>
                    <input type="date" name="session_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Start Time (optional)</label>
                    <input type="time" name="start_time" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Time (optional)</label>
                    <input type="time" name="end_time" class="form-control">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-forest w-100">
                        <i class="bi bi-calendar-plus me-1"></i> Add Session
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Sessions List (Clean Light Data Table) -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2 text-success"></i>Session History & Attendance</span>
            <span class="badge bg-secondary"><?php echo count($sessions); ?> total sessions</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Session Date</th>
                        <th style="min-width: 160px;">Time (Start – End)</th>
                        <th>Attendance Recorded</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-x fs-2 d-block mb-2 text-subtle"></i>
                                No sessions scheduled for this class yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sessions as $i => $sess): ?>
                            <tr>
                                <td class="text-muted small"><?php echo $i + 1; ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo date('M d, Y', strtotime($sess['session_date'])); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php 
                                        $st = $sess['start_time'] ?? $sess['session_time'] ?? null;
                                        $et = $sess['end_time'] ?? null;
                                        if ($st && $et) {
                                            echo date('h:i A', strtotime($st)) . ' – ' . date('h:i A', strtotime($et));
                                        } elseif ($st) {
                                            echo date('h:i A', strtotime($st));
                                        } else {
                                            echo 'Regular Schedule';
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($sess['attendance_count'] > 0): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle me-1"></i><?php echo $sess['attendance_count']; ?> Records Logged
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">
                                            <i class="bi bi-clock me-1"></i>Not recorded
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="attendance.php?class_id=<?php echo $class_id; ?>&session_id=<?php echo $sess['session_id']; ?>"
                                           class="btn btn-subtle" title="Record Attendance">
                                            <i class="bi bi-calendar-check text-success me-1"></i> Record
                                        </a>
                                        <?php if ($sess['attendance_count'] == 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this session?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="session_id" value="<?php echo $sess['session_id']; ?>">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-subtle text-danger" title="Delete Session">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($class_id === 0): ?>
    <div class="card p-5 text-center text-muted">
        <i class="bi bi-arrow-up-circle fs-1 d-block mb-3 text-success"></i>
        <h5>Select a Course Section</h5>
        <p class="small text-muted mb-0">Please choose an active course above to view and schedule sessions.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
