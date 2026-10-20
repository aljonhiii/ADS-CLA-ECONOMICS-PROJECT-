<?php
/**
 * Instructor — Finalize Grades
 * 
 * Computes weighted final grades for all students in a class,
 * evaluates Pass/Fail against the class passing threshold,
 * and records final grades with an audit log.
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

if ($class_id === 0 && !empty($my_classes)) {
    $class_id = (int)$my_classes[0]['class_id'];
}

if ($class_id > 0 && !instructor_owns_class($pdo, $class_id)) {
    set_flash('danger', 'Access denied.');
    header('Location: classes.php');
    exit;
}

$current_class = null;
foreach ($my_classes as $c) {
    if ((int)$c['class_id'] === $class_id) {
        $current_class = $c;
        break;
    }
}

$threshold = $current_class ? (float)$current_class['passing_threshold'] : 75.00;

// Fetch components
$components = [];
$total_weight = 0.0;
if ($class_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM grading_components WHERE class_id = ? ORDER BY component_id ASC');
    $stmt->execute([$class_id]);
    $components = $stmt->fetchAll();
    foreach ($components as $comp) {
        $total_weight += (float)$comp['weight_percentage'];
    }
}
$is_weight_valid = (abs($total_weight - 100.0) < 0.01);

// Fetch enrolled students
$students = [];
if ($class_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT sc.student_class_id, s.student_id, s.student_number, s.first_name, s.middle_name, s.last_name, s.yr_section,
                fg.final_grade_id, fg.final_grade AS saved_final_grade, fg.status AS saved_status, fg.finalized_at,
                u.username AS finalized_by_user
         FROM student_classes sc
         JOIN students s ON sc.student_id = s.student_id
         LEFT JOIN final_grades fg ON sc.student_class_id = fg.student_class_id
         LEFT JOIN users u ON fg.finalized_by = u.user_id
         WHERE sc.class_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll();
}

// Fetch existing scores
$scores = [];
if ($class_id > 0 && !empty($students)) {
    $sc_ids = array_column($students, 'student_class_id');
    $in_placeholders = implode(',', array_fill(0, count($sc_ids), '?'));
    $stmt = $pdo->prepare("SELECT student_class_id, component_id, score FROM grades WHERE student_class_id IN ($in_placeholders)");
    $stmt->execute($sc_ids);
    foreach ($stmt->fetchAll() as $row) {
        $scores[$row['student_class_id']][$row['component_id']] = (float)$row['score'];
    }
}

// Compute preview for each student
// Final Grade = sum( (score / max_score) * weight% )
$calculations = [];
$pass_count = 0;
$fail_count = 0;
$pending_count = 0;

foreach ($students as $st) {
    $sc_id = $st['student_class_id'];
    $all_present = true;
    $computed_total = 0.0;
    $comp_details = [];

    foreach ($components as $comp) {
        $cid = $comp['component_id'];
        $max = (float)$comp['maximum_score'];
        $weight = (float)$comp['weight_percentage'];

        if (isset($scores[$sc_id][$cid])) {
            $sc = $scores[$sc_id][$cid];
            $contribution = ($max > 0) ? ($sc / $max) * $weight : 0;
            $computed_total += $contribution;
            $comp_details[$cid] = [
                'score' => $sc,
                'max' => $max,
                'contribution' => $contribution,
                'missing' => false
            ];
        } else {
            $all_present = false;
            $comp_details[$cid] = [
                'score' => null,
                'max' => $max,
                'contribution' => 0,
                'missing' => true
            ];
        }
    }

    $computed_total = round($computed_total, 2);
    $computed_status = 'Pending';
    if ($is_weight_valid && $all_present && !empty($components)) {
        $computed_status = ($computed_total >= $threshold) ? 'Pass' : 'Fail';
    }

    if ($computed_status === 'Pass') $pass_count++;
    elseif ($computed_status === 'Fail') $fail_count++;
    else $pending_count++;

    $calculations[$sc_id] = [
        'student' => $st,
        'computed_grade' => $computed_total,
        'computed_status' => $computed_status,
        'all_present' => $all_present,
        'comp_details' => $comp_details
    ];
}

// Handle POST to finalize grades
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class_id > 0) {
    verify_csrf();

    if (!$is_weight_valid) {
        set_flash('danger', 'Cannot finalize grades: Component weights must total exactly 100%.');
        header("Location: finalize-grades.php?class_id=$class_id");
        exit;
    }

    $user = current_user();
    $finalized_records = 0;

    try {
        $pdo->beginTransaction();

        $stmt_upsert = $pdo->prepare(
            'INSERT INTO final_grades (student_class_id, final_grade, status, finalized_at, finalized_by)
             VALUES (?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE 
                final_grade = VALUES(final_grade), 
                status = VALUES(status), 
                finalized_at = NOW(), 
                finalized_by = VALUES(finalized_by)'
        );

        foreach ($calculations as $sc_id => $calc) {
            $final_val = $calc['all_present'] ? $calc['computed_grade'] : null;
            $status_val = $calc['computed_status'];

            $stmt_upsert->execute([
                $sc_id,
                $final_val,
                $status_val,
                $user['user_id']
            ]);
            $finalized_records++;
        }

        // Insert audit log
        $log_stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $log_stmt->execute([
            $user['user_id'],
            'FINALIZE_GRADES',
            "Finalized grades for class #{$class_id} ({$current_class['subject_code']}): {$finalized_records} students processed ({$pass_count} Passed, {$fail_count} Failed, {$pending_count} Pending). Threshold: {$threshold}%"
        ]);

        $pdo->commit();
        set_flash('success', "Final grades successfully committed and saved for {$finalized_records} students.");
        header("Location: finalize-grades.php?class_id=$class_id");
        exit;
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Finalize grades error: ' . $ex->getMessage());
        $debug_msg = (defined('APP_DEBUG') && APP_DEBUG) ? ': ' . $ex->getMessage() : '.';
        set_flash('danger', 'Error finalizing grades' . $debug_msg);
    }
}

$page_title = 'Finalize Grades';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col-md-7">
        <h3 class="fw-bold mb-1" style="color: var(--neutral-900);">Finalize Grades</h3>
        <p class="text-muted mb-0">Compute final scores, review Pass/Fail outcomes, and lock class grades.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex gap-2 justify-content-md-end">
        <?php if ($class_id > 0): ?>
            <a href="grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle">
                <i class="bi bi-pencil-square me-1"></i> Edit Scores
            </a>
            <a href="grading-components.php?class_id=<?php echo $class_id; ?>" class="btn btn-subtle">
                <i class="bi bi-sliders me-1"></i> Weights
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Class Selection Card -->
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="GET" action="finalize-grades.php" class="row g-3 align-items-center">
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
    <!-- Weight Validation Notice -->
    <?php if (!$is_weight_valid): ?>
        <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon-fill fs-5 text-danger"></i>
                <div>
                    <strong>Grading Weights Error:</strong> Components currently total <strong><?php echo number_format($total_weight, 1); ?>%</strong>. 
                    Weights must total exactly <strong>100.0%</strong> before grades can be finalized.
                </div>
            </div>
            <a href="grading-components.php?class_id=<?php echo $class_id; ?>" class="btn btn-sm btn-subtle">
                Configure Weights
            </a>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-card-title">Enrolled Students</div>
                        <div class="stat-card-value text-dark"><?php echo count($students); ?></div>
                        <span class="text-muted small">Passing threshold: <strong><?php echo number_format($threshold, 1); ?>%</strong></span>
                    </div>
                    <div class="stat-card-icon" style="background: var(--forest-50); color: var(--forest-700);">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-card-title">Passing</div>
                        <div class="stat-card-value text-success"><?php echo $pass_count; ?></div>
                        <span class="text-muted small">
                            <?php echo count($students) > 0 ? round(($pass_count / count($students)) * 100, 1) : 0; ?>% passing rate
                        </span>
                    </div>
                    <div class="stat-card-icon" style="background: #ecfdf5; color: #059669;">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-card-title">Failing</div>
                        <div class="stat-card-value text-danger"><?php echo $fail_count; ?></div>
                        <span class="text-muted small">Below <?php echo number_format($threshold, 1); ?>%</span>
                    </div>
                    <div class="stat-card-icon" style="background: #fef2f2; color: #dc2626;">
                        <i class="bi bi-x-circle"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-card-title">Pending / Incomplete</div>
                        <div class="stat-card-value text-warning"><?php echo $pending_count; ?></div>
                        <span class="text-muted small">Missing scores</span>
                    </div>
                    <div class="stat-card-icon" style="background: #fffbeb; color: #d97706;">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Final Grades Table -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h6 class="mb-0 fw-bold" style="color: var(--neutral-900);">
                    <i class="bi bi-award me-2 text-primary"></i>Final Grade Calculation Table
                </h6>
                <small class="text-muted">Formula: &Sigma; (Score &divide; Max Score &times; Weight %)</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="input-group input-group-sm" style="max-width: 250px;">
                    <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" data-table-search="finalizeGradesTable" class="form-control border-start-0" placeholder="Filter student...">
                </div>
                <?php if ($is_weight_valid && !empty($students)): ?>
                    <form method="POST" action="finalize-grades.php?class_id=<?php echo $class_id; ?>" onsubmit="return confirm('Are you sure you want to finalize and lock grades for this class?');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-forest px-3">
                            <i class="bi bi-lock me-1"></i> Finalize & Lock
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive table-responsive-sticky" style="max-height: 700px;">
            <table class="table table-hover align-middle mb-0" id="finalizeGradesTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Student Number</th>
                        <th>Student Name</th>
                        <?php foreach ($components as $comp): ?>
                            <th class="text-center" style="font-size: 0.85rem;">
                                <?php echo e($comp['component_name']); ?>
                                <div class="text-muted small">(<?php echo number_format($comp['weight_percentage'], 0); ?>%)</div>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-center">Computed Grade</th>
                        <th class="text-center">Calculated Status</th>
                        <th class="text-center">Saved Status</th>
                        <th>Last Finalized</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="<?php echo count($components) + 7; ?>" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-muted"></i>
                                No students enrolled in this class.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($calculations as $idx => $calc): 
                            $st = $calc['student'];
                        ?>
                            <tr>
                                <td class="text-muted"><?php echo $idx + 1; ?></td>
                                <td>
                                    <span class="badge bg-secondary font-monospace"><?php echo e($st['student_number']); ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold" style="color: var(--neutral-900);"><?php echo e($st['last_name'] . ', ' . $st['first_name'] . ' ' . $st['middle_name']); ?></div>
                                    <small class="text-muted"><?php echo e($st['yr_section']); ?></small>
                                </td>
                                <?php foreach ($components as $comp): 
                                    $cid = $comp['component_id'];
                                    $det = $calc['comp_details'][$cid];
                                ?>
                                    <td class="text-center">
                                        <?php if ($det['missing']): ?>
                                            <span class="badge bg-danger">Missing</span>
                                        <?php else: ?>
                                            <span class="fw-semibold" style="color: var(--neutral-900);"><?php echo number_format($det['score'], 1); ?></span>
                                            <small class="text-muted d-block" style="font-size: 0.75rem;">
                                                +<?php echo number_format($det['contribution'], 1); ?>%
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center">
                                    <?php if ($calc['all_present']): ?>
                                        <span class="fs-6 fw-bold <?php echo $calc['computed_grade'] >= $threshold ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($calc['computed_grade'], 2); ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Incomplete</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($calc['computed_status'] === 'Pass'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Pass</span>
                                    <?php elseif ($calc['computed_status'] === 'Fail'): ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Fail</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><i class="bi bi-clock me-1"></i>Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($st['saved_status'])): ?>
                                        <?php 
                                            $s_cls = $st['saved_status'] === 'Pass' ? 'bg-success' : ($st['saved_status'] === 'Fail' ? 'bg-danger' : 'bg-warning');
                                        ?>
                                        <span class="badge <?php echo $s_cls; ?>">
                                            <?php echo e($st['saved_status']); ?> (<?php echo number_format($st['saved_final_grade'], 1); ?>%)
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">Not saved</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?php if (!empty($st['finalized_at'])): ?>
                                        <div><i class="bi bi-calendar-event me-1"></i><?php echo date('M d, Y h:i A', strtotime($st['finalized_at'])); ?></div>
                                        <?php if (!empty($st['finalized_by_user'])): ?>
                                            <span class="text-muted small">by @<?php echo e($st['finalized_by_user']); ?></span>
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
        <div class="card-footer bg-white border-top d-flex flex-wrap justify-content-between align-items-center py-3 gap-2">
            <span class="text-muted small">
                <i class="bi bi-shield-check me-1 text-primary"></i> Finalizing updates the permanent <code>final_grades</code> table and records an audit log entry.
            </span>
            <?php if ($is_weight_valid && !empty($students)): ?>
                <form method="POST" action="finalize-grades.php?class_id=<?php echo $class_id; ?>" onsubmit="return confirm('Are you sure you want to finalize and lock grades for this class?');">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-forest px-4">
                        <i class="bi bi-lock me-1"></i> Finalize & Lock Grades
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
