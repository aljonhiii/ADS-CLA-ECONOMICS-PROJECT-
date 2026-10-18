<?php
/**
 * Instructor — Grading Components Configuration
 * 
 * Configure grading components (Quizzes, Activities, Exams, Attendance)
 * with weight percentages and maximum scores per class.
 * Weights must total 100% for a complete setup.
 */

require_once __DIR__ . '/../includes/permissions.php';
require_role(['instructor']);

$pdo = db_connect();
$instructor = get_current_instructor($pdo);

$class_id = (int)($_GET['class_id'] ?? 0);

// Fetch instructor's classes
$stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester
     FROM classes c JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.instructor_id = ?
     ORDER BY c.school_year DESC, s.subject_code ASC'
);
$stmt->execute([$instructor['instructor_id']]);
$my_classes = $stmt->fetchAll();

if ($class_id > 0 && !instructor_owns_class($pdo, $class_id)) {
    set_flash('danger', 'Access denied.');
    header('Location: grading-components.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class_id > 0) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['component_name'] ?? '');
        $weight = (float)($_POST['weight_percentage'] ?? 0);
        $max    = (float)($_POST['maximum_score'] ?? 0);

        if (empty($name) || $weight <= 0 || $max <= 0) {
            set_flash('danger', 'All fields are required. Weight and max score must be positive.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO grading_components (class_id, component_name, weight_percentage, maximum_score)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$class_id, $name, $weight, $max]);
            set_flash('success', 'Component "' . $name . '" added.');
        }
        header("Location: grading-components.php?class_id=$class_id");
        exit;
    }

    if ($action === 'delete') {
        $component_id = (int)($_POST['component_id'] ?? 0);
        // Check for existing grades
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM grades WHERE component_id = ?');
        $stmt->execute([$component_id]);
        if ($stmt->fetchColumn() > 0) {
            set_flash('warning', 'Cannot delete — grades have been recorded for this component.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM grading_components WHERE component_id = ? AND class_id = ?');
            $stmt->execute([$component_id, $class_id]);
            set_flash('success', 'Component deleted.');
        }
        header("Location: grading-components.php?class_id=$class_id");
        exit;
    }

    if ($action === 'update') {
        $component_id = (int)($_POST['component_id'] ?? 0);
        $name   = trim($_POST['component_name'] ?? '');
        $weight = (float)($_POST['weight_percentage'] ?? 0);
        $max    = (float)($_POST['maximum_score'] ?? 0);

        if (empty($name) || $weight <= 0 || $max <= 0) {
            set_flash('danger', 'All fields are required.');
        } else {
            $stmt = $pdo->prepare(
                'UPDATE grading_components SET component_name = ?, weight_percentage = ?, maximum_score = ?
                 WHERE component_id = ? AND class_id = ?'
            );
            $stmt->execute([$name, $weight, $max, $component_id, $class_id]);
            set_flash('success', 'Component updated.');
        }
        header("Location: grading-components.php?class_id=$class_id");
        exit;
    }
}

// Fetch components
$components = [];
$total_weight = 0;
$class_info = null;
if ($class_id > 0) {
    $stmt = $pdo->prepare('SELECT c.*, s.subject_code, s.subject_name FROM classes c JOIN subjects s ON c.subject_id = s.subject_id WHERE c.class_id = ?');
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM grading_components WHERE class_id = ? ORDER BY component_id');
    $stmt->execute([$class_id]);
    $components = $stmt->fetchAll();

    foreach ($components as $comp) {
        $total_weight += $comp['weight_percentage'];
    }
}
$weight_valid = abs($total_weight - 100) < 0.01;
$page_title = 'Grading Components';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col-md-7">
        <h3 class="fw-bold mb-1" style="color: var(--neutral-900);">Grading Components</h3>
        <p class="text-muted mb-0">Configure grading weights for each class. Weights must total exactly 100%.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex gap-2 justify-content-md-end">
        <?php if ($class_id > 0): ?>
            <a href="grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle">
                <i class="bi bi-pencil-square me-1"></i> Enter Grades
            </a>
            <a href="finalize-grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle">
                <i class="bi bi-check-circle me-1"></i> Finalize Grades
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Class Selector -->
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-auto">
                <label class="form-label fw-bold mb-0 text-muted small text-uppercase">Select Class</label>
            </div>
            <div class="col-md-7">
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choose a class —</option>
                    <?php foreach ($my_classes as $cls): ?>
                        <option value="<?php echo $cls['class_id']; ?>" <?php echo $cls['class_id'] == $class_id ? 'selected' : ''; ?>>
                            <?php echo e($cls['subject_code'] . ' — ' . $cls['subject_name'] . ' (' . $cls['school_year'] . ' ' . $cls['school_semester'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($class_id > 0 && $class_info): ?>
    <!-- Weight Summary Card -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-card-icon" style="background: <?php echo $weight_valid ? 'var(--forest-50)' : '#fef2f2'; ?>; color: <?php echo $weight_valid ? 'var(--forest-700)' : '#dc2626'; ?>;">
                    <i class="bi <?php echo $weight_valid ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Total Configured Weight</div>
                    <div class="d-flex align-items-baseline gap-2 mt-1">
                        <span id="weightTotal" class="fs-4 fw-bold <?php echo $weight_valid ? 'text-success' : 'text-danger'; ?>">
                            <?php echo number_format($total_weight, 2); ?>%
                        </span>
                        <span id="weightStatus" class="<?php echo $weight_valid ? 'badge bg-success' : 'badge bg-danger'; ?>">
                            <?php echo $weight_valid ? '✓ Valid (100%)' : '✗ Must equal 100%'; ?>
                        </span>
                    </div>
                </div>
            </div>
            <span class="badge bg-primary px-3 py-2 fs-6">
                <i class="bi bi-book me-1"></i><?php echo e($class_info['subject_code']); ?> &bull; <?php echo e($class_info['school_year'] . ' ' . $class_info['school_semester']); ?>
            </span>
        </div>
    </div>

    <!-- Add Component Form -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
            <h6 class="mb-0 fw-bold" style="color: var(--neutral-900);">
                <i class="bi bi-plus-circle me-2 text-primary"></i>Add Grading Component
            </h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add">
                <?php echo csrf_field(); ?>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Component Name</label>
                    <input type="text" name="component_name" class="form-control" placeholder="e.g. Quizzes, Midterm Exam" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Weight (%)</label>
                    <input type="number" name="weight_percentage" class="form-control weight-input" step="0.01" min="0.01" max="100" placeholder="25.00" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Max Score</label>
                    <input type="number" name="maximum_score" class="form-control" step="0.01" min="1" placeholder="100.00" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-forest w-100">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Components Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold" style="color: var(--neutral-900);">
                <i class="bi bi-list-ul me-2 text-primary"></i>Configured Components
            </h6>
            <span class="badge bg-secondary"><?php echo count($components); ?> defined</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th>Component Name</th>
                            <th>Weight Percentage</th>
                            <th>Maximum Score</th>
                            <th class="text-end" style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($components)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-1 d-block mb-2 text-muted"></i>
                                    No grading components configured yet. Add your first component above.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($components as $i => $comp): ?>
                                <tr>
                                    <td class="text-muted"><?php echo $i + 1; ?></td>
                                    <td>
                                        <div class="fw-bold" style="color: var(--neutral-900);"><?php echo e($comp['component_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary fs-6"><?php echo number_format($comp['weight_percentage'], 2); ?>%</span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-muted"><?php echo number_format($comp['maximum_score'], 2); ?> pts</span>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete component &quot;<?php echo e($comp['component_name']); ?>&quot;?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="component_id" value="<?php echo $comp['component_id']; ?>">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" class="btn btn-sm btn-subtle text-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($class_id === 0): ?>
    <div class="card border-0 shadow-sm p-5 text-center">
        <div class="stat-card-icon mx-auto mb-3" style="background: var(--forest-50); color: var(--forest-700); width: 64px; height: 64px;">
            <i class="bi bi-sliders fs-2"></i>
        </div>
        <h5 class="fw-bold" style="color: var(--neutral-900);">Select a Class</h5>
        <p class="text-muted mb-0">Choose a class from the dropdown above to view and configure its grading components.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
