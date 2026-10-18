<?php
/**
 * Instructor — Grade Entry Matrix
 * 
 * Matrix interface to enter and edit scores for each student
 * across all configured grading components of a selected class.
 */

require_once __DIR__ . '/../includes/permissions.php';
require_role(['instructor']);

$pdo = db_connect();
$instructor = get_current_instructor($pdo);

$class_id = (int)($_GET['class_id'] ?? 0);

// Fetch instructor's classes
$stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester, c.passing_threshold
     FROM classes c JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.instructor_id = ?
     ORDER BY c.school_year DESC, s.subject_code ASC'
);
$stmt->execute([$instructor['instructor_id']]);
$my_classes = $stmt->fetchAll();

// Default to first class if not selected
if ($class_id === 0 && !empty($my_classes)) {
    $class_id = (int)$my_classes[0]['class_id'];
}

// Validate ownership
if ($class_id > 0 && !instructor_owns_class($pdo, $class_id)) {
    set_flash('danger', 'Access denied.');
    header('Location: classes.php');
    exit;
}

// Selected class details
$current_class = null;
foreach ($my_classes as $c) {
    if ((int)$c['class_id'] === $class_id) {
        $current_class = $c;
        break;
    }
}

// Fetch grading components
$components = [];
$total_weight = 0.0;
if ($class_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM grading_components WHERE class_id = ? ORDER BY component_id ASC'
    );
    $stmt->execute([$class_id]);
    $components = $stmt->fetchAll();
    foreach ($components as $comp) {
        $total_weight += (float)$comp['weight_percentage'];
    }
}

// Fetch enrolled students
$students = [];
if ($class_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT sc.student_class_id, s.student_id, s.student_number, s.first_name, s.middle_name, s.last_name, s.yr_section,
                fg.final_grade, fg.status AS final_status, fg.finalized_at
         FROM student_classes sc
         JOIN students s ON sc.student_id = s.student_id
         LEFT JOIN final_grades fg ON sc.student_class_id = fg.student_class_id
         WHERE sc.class_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll();
}

// Fetch existing scores: [student_class_id][component_id] => score
$existing_grades = [];
if ($class_id > 0 && !empty($students)) {
    $sc_ids = array_column($students, 'student_class_id');
    $in_placeholders = implode(',', array_fill(0, count($sc_ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT student_class_id, component_id, score FROM grades WHERE student_class_id IN ($in_placeholders)"
    );
    $stmt->execute($sc_ids);
    foreach ($stmt->fetchAll() as $row) {
        $existing_grades[$row['student_class_id']][$row['component_id']] = (float)$row['score'];
    }
}

// Handle save POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class_id > 0) {
    verify_csrf();
    $scores_input = $_POST['scores'] ?? [];
    $errors = [];
    $saved_count = 0;

    // Component max score map
    $comp_max = [];
    foreach ($components as $c) {
        $comp_max[$c['component_id']] = (float)$c['maximum_score'];
    }

    // Valid student_class_ids for this class
    $valid_sc_ids = array_flip(array_column($students, 'student_class_id'));

    try {
        $pdo->beginTransaction();

        $stmt_upsert = $pdo->prepare(
            'INSERT INTO grades (student_class_id, component_id, score)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = CURRENT_TIMESTAMP'
        );

        $stmt_delete = $pdo->prepare(
            'DELETE FROM grades WHERE student_class_id = ? AND component_id = ?'
        );

        foreach ($scores_input as $sc_id => $comp_scores) {
            $sc_id = (int)$sc_id;
            if (!isset($valid_sc_ids[$sc_id])) continue;

            foreach ($comp_scores as $comp_id => $val) {
                $comp_id = (int)$comp_id;
                if (!isset($comp_max[$comp_id])) continue;

                $val = trim((string)$val);
                if ($val === '') {
                    // Empty score - delete record if existed
                    $stmt_delete->execute([$sc_id, $comp_id]);
                } else {
                    $num_val = (float)$val;
                    $max = $comp_max[$comp_id];
                    if ($num_val < 0 || $num_val > $max) {
                        $errors[] = "Score {$num_val} exceeds range [0 - {$max}] for component ID {$comp_id}.";
                        continue;
                    }
                    $stmt_upsert->execute([$sc_id, $comp_id, $num_val]);
                    $saved_count++;
                }
            }
        }

        if (!empty($errors)) {
            $pdo->rollBack();
            set_flash('danger', 'Validation failed: ' . implode('<br>', array_map('e', array_slice($errors, 0, 5))));
        } else {
            // Log audit
            $user = current_user();
            $log_stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
            $log_stmt->execute([
                $user['user_id'],
                'GRADE_ENTRY',
                "Updated grade scores for class #{$class_id} ({$current_class['subject_code']})"
            ]);

            $pdo->commit();
            set_flash('success', "Grades saved successfully ({$saved_count} scores recorded/updated).");
            header("Location: grades.php?class_id=$class_id");
            exit;
        }
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Grade save error: ' . $ex->getMessage());
        $debug_msg = (defined('APP_DEBUG') && APP_DEBUG) ? ': ' . $ex->getMessage() : '.';
        set_flash('danger', 'Failed to save grades' . $debug_msg);
    }
}

// Check if any student already has finalized grades
$has_finalized = false;
foreach ($students as $st) {
    if (!empty($st['finalized_at'])) {
        $has_finalized = true;
        break;
    }
}

$page_title = 'Enter Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col-md-6">
        <h3 class="fw-bold mb-1" style="color: var(--neutral-900);">Enter Grades</h3>
        <p class="text-muted mb-0">Record component scores for enrolled students.</p>
    </div>
    <div class="col-md-6 text-md-end mt-3 mt-md-0 d-flex gap-2 justify-content-md-end">
        <?php if ($class_id > 0): ?>
            <a href="grading-components.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle">
                <i class="bi bi-sliders me-1"></i> Configure Weights
            </a>
            <a href="finalize-grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle">
                <i class="bi bi-check-circle me-1"></i> Finalize Grades
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Class Selection Card -->
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="GET" action="grades.php" class="row g-3 align-items-center">
            <div class="col-auto">
                <label for="classSelect" class="form-label fw-bold mb-0 text-muted small text-uppercase">Select Class</label>
            </div>
            <div class="col-md-7">
                <select id="classSelect" name="class_id" class="form-select" onchange="this.form.submit()">
                    <?php if (empty($my_classes)): ?>
                        <option value="">No classes assigned</option>
                    <?php else: ?>
                        <?php foreach ($my_classes as $c): ?>
                            <option value="<?php echo $c['class_id']; ?>" <?php echo ($c['class_id'] == $class_id) ? 'selected' : ''; ?>>
                                <?php echo e($c['subject_code']); ?> — <?php echo e($c['subject_name']); ?> (<?php echo e($c['school_year']); ?> <?php echo e($c['school_semester']); ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($class_id > 0): ?>
    <!-- Warnings / Status Badges -->
    <?php if (abs($total_weight - 100.0) > 0.01): ?>
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-5 text-warning"></i>
                <div>
                    <strong>Grading Weights Incomplete:</strong> Current total is <strong><?php echo number_format($total_weight, 1); ?>%</strong>. 
                    Weights must total exactly 100.0% before final grades can be calculated.
                </div>
            </div>
            <a href="grading-components.php?class_id=<?php echo $class_id; ?>" class="btn btn-sm btn-subtle">
                Fix Weights
            </a>
        </div>
    <?php endif; ?>

    <?php if ($has_finalized): ?>
        <div class="alert alert-info border-0 shadow-sm d-flex align-items-center mb-4">
            <i class="bi bi-info-circle-fill me-2 fs-5 text-info"></i>
            <div>
                <strong>Notice:</strong> Some or all students in this class already have finalized grades. 
                If you update component scores, remember to visit <a href="finalize-grades.php?class_id=<?php echo $class_id; ?>" class="alert-link text-decoration-underline">Finalize Grades</a> to recalculate.
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($components)): ?>
        <div class="card border-0 shadow-sm p-5 text-center">
            <div class="stat-card-icon mx-auto mb-3" style="background: var(--forest-50); color: var(--forest-700); width: 64px; height: 64px;">
                <i class="bi bi-sliders fs-2"></i>
            </div>
            <h5 class="fw-bold" style="color: var(--neutral-900);">No Grading Components Configured</h5>
            <p class="text-muted mb-3">You must add grading components (e.g., Quizzes, Midterms, Final Exam) before entering scores.</p>
            <div>
                <a href="grading-components.php?class_id=<?php echo $class_id; ?>" class="btn btn-forest">
                    <i class="bi bi-plus-circle me-1"></i> Add Grading Components
                </a>
            </div>
        </div>
    <?php elseif (empty($students)): ?>
        <div class="card border-0 shadow-sm p-5 text-center">
            <div class="stat-card-icon mx-auto mb-3" style="background: var(--forest-50); color: var(--forest-700); width: 64px; height: 64px;">
                <i class="bi bi-people fs-2"></i>
            </div>
            <h5 class="fw-bold" style="color: var(--neutral-900);">No Students Enrolled</h5>
            <p class="text-muted mb-0">There are no students enrolled in this class yet.</p>
        </div>
    <?php else: ?>
        <!-- Grades Matrix Form -->
        <form method="POST" action="grades.php?class_id=<?php echo $class_id; ?>" id="gradesMatrixForm">
            <?php echo csrf_field(); ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 fw-bold" style="color: var(--neutral-900);">
                            <i class="bi bi-table me-2 text-primary"></i>Grade Entry Matrix
                        </h6>
                        <small class="text-muted">
                            <?php echo count($students); ?> Enrolled Students &bull; <?php echo count($components); ?> Components
                        </small>
                    </div>
                    <button type="submit" class="btn btn-forest">
                        <i class="bi bi-check-lg me-1"></i> Save All Grades
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="min-width: 140px;">Student Number</th>
                                <th style="min-width: 200px;">Student Name</th>
                                <th style="min-width: 110px;">Yr & Sec</th>
                                <?php foreach ($components as $comp): ?>
                                    <th class="text-center" style="min-width: 150px;">
                                        <div class="fw-bold" style="color: var(--neutral-900);"><?php echo e($comp['component_name']); ?></div>
                                        <span class="badge bg-primary me-1"><?php echo number_format($comp['weight_percentage'], 1); ?>%</span>
                                        <small class="text-muted d-block mt-1">Max: <?php echo number_format($comp['maximum_score'], 1); ?></small>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-center" style="min-width: 130px;">Final Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $idx => $st): ?>
                                <tr>
                                    <td class="text-muted"><?php echo $idx + 1; ?></td>
                                    <td>
                                        <span class="badge bg-secondary font-monospace">
                                            <?php echo e($st['student_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold" style="color: var(--neutral-900);">
                                            <?php echo e($st['last_name'] . ', ' . $st['first_name'] . ' ' . $st['middle_name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo e($st['yr_section']); ?>
                                        </span>
                                    </td>
                                    <?php foreach ($components as $comp): 
                                        $sc_id = $st['student_class_id'];
                                        $c_id = $comp['component_id'];
                                        $val = isset($existing_grades[$sc_id][$c_id]) ? $existing_grades[$sc_id][$c_id] : '';
                                        $max = (float)$comp['maximum_score'];
                                    ?>
                                        <td class="text-center p-2">
                                            <input type="number" 
                                                   name="scores[<?php echo $sc_id; ?>][<?php echo $c_id; ?>]" 
                                                   class="form-control form-control-sm text-center fw-bold score-input"
                                                   step="0.01" 
                                                   min="0" 
                                                   max="<?php echo $max; ?>" 
                                                   value="<?php echo ($val !== '') ? e($val) : ''; ?>"
                                                   placeholder="0 - <?php echo $max; ?>"
                                                   data-max="<?php echo $max; ?>">
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center">
                                        <?php if (!empty($st['final_status'])): ?>
                                            <?php 
                                                $badge_cls = $st['final_status'] === 'Pass' ? 'bg-success' : ($st['final_status'] === 'Fail' ? 'bg-danger' : 'bg-warning');
                                            ?>
                                            <span class="badge <?php echo $badge_cls; ?>">
                                                <?php echo e($st['final_status']); ?> (<?php echo number_format($st['final_grade'], 1); ?>%)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Unfinalized</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white border-top d-flex flex-wrap justify-content-between align-items-center py-3 gap-2">
                    <span class="text-muted small">
                        <i class="bi bi-lightbulb me-1 text-primary"></i> Tip: Leave a cell blank if the score is not yet graded. Max values are validated on submission.
                    </span>
                    <button type="submit" class="btn btn-forest px-4">
                        <i class="bi bi-check-lg me-1"></i> Save All Grades
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.score-input');
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            const max = parseFloat(this.getAttribute('data-max'));
            const val = parseFloat(this.value);
            if (val > max) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
