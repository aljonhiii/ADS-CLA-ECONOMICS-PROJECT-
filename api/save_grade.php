<?php
/**
 * AJAX API: Save Individual Grade Score
 * 
 * Auto-saves a single student's score for a specific grading component,
 * validates against maximum score limits, dynamically recalculates the
 * student's cumulative grade, and returns updated metrics in JSON.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/permissions.php';

// Ensure instructor is logged in
if (!is_logged_in() || current_user()['role'] !== 'instructor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

verify_csrf();

$pdo = db_connect();
$instructor = get_current_instructor($pdo);
if (!$instructor) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Instructor profile not found.']);
    exit;
}

// Read parameters (supports JSON and form-urlencoded POST)
$raw_input = file_get_contents('php://input');
$input_data = [];
if (!empty($raw_input)) {
    $decoded = json_decode($raw_input, true);
    if (is_array($decoded)) {
        $input_data = $decoded;
    }
}
if (empty($input_data)) {
    $input_data = $_POST;
}

$student_class_id = (int)($input_data['student_class_id'] ?? 0);
$component_id     = (int)($input_data['component_id'] ?? 0);
$raw_score_val    = $input_data['score'] ?? null;

if ($student_class_id <= 0 || $component_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid student enrollment or component ID.']);
    exit;
}

// Verify that the instructor owns the class for this student_class_id and component_id
$stmt = $pdo->prepare(
    'SELECT sc.class_id, c.passing_threshold, gc.maximum_score, gc.weight_percentage
     FROM student_classes sc
     JOIN classes c ON sc.class_id = c.class_id
     JOIN grading_components gc ON gc.component_id = ? AND gc.class_id = c.class_id
     WHERE sc.student_class_id = ? AND c.instructor_id = ?'
);
$stmt->execute([$component_id, $student_class_id, $instructor['instructor_id']]);
$class_info = $stmt->fetch();

if (!$class_info) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: class not assigned to you.']);
    exit;
}

$class_id    = (int)$class_info['class_id'];
$max_score   = (float)$class_info['maximum_score'];
$threshold   = (float)$class_info['passing_threshold'];

try {
    $pdo->beginTransaction();

    $new_score = null;
    if ($raw_score_val === '' || $raw_score_val === null) {
        // Clear / remove score
        $del = $pdo->prepare('DELETE FROM grades WHERE student_class_id = ? AND component_id = ?');
        $del->execute([$student_class_id, $component_id]);
    } else {
        $num_val = (float)$raw_score_val;
        if ($num_val < 0 || $num_val > $max_score) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => "Score {$num_val} exceeds valid range (0 - {$max_score})."
            ]);
            exit;
        }

        $upsert = $pdo->prepare(
            'INSERT INTO grades (student_class_id, component_id, score)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = CURRENT_TIMESTAMP'
        );
        $upsert->execute([$student_class_id, $component_id, $num_val]);
        $new_score = $num_val;
    }

    // Recalculate this student's weighted grade across all components
    $comp_stmt = $pdo->prepare(
        'SELECT gc.component_id, gc.weight_percentage, gc.maximum_score, g.score
         FROM grading_components gc
         LEFT JOIN grades g ON gc.component_id = g.component_id AND g.student_class_id = ?
         WHERE gc.class_id = ?
         ORDER BY gc.component_id ASC'
    );
    $comp_stmt->execute([$student_class_id, $class_id]);
    $all_components = $comp_stmt->fetchAll();

    $student_total_weighted = 0.0;
    $all_graded = true;
    $has_any_score = false;

    foreach ($all_components as $comp) {
        $c_max = (float)$comp['maximum_score'];
        $c_wt  = (float)$comp['weight_percentage'];
        if ($comp['score'] !== null) {
            $has_any_score = true;
            $contribution = ($c_max > 0) ? (((float)$comp['score']) / $c_max) * $c_wt : 0;
            $student_total_weighted += $contribution;
        } else {
            $all_graded = false;
        }
    }

    $student_total_weighted = round($student_total_weighted, 2);
    $status = 'Pending';
    if ($all_graded && !empty($all_components)) {
        $status = ($student_total_weighted >= $threshold) ? 'Pass' : 'Fail';
    } elseif ($has_any_score) {
        $status = 'In Progress';
    }

    $pdo->commit();

    echo json_encode([
        'success'          => true,
        'message'          => 'Score updated successfully.',
        'student_class_id' => $student_class_id,
        'component_id'     => $component_id,
        'score'            => $new_score,
        'student_total'    => $student_total_weighted,
        'status'           => $status,
        'all_graded'       => $all_graded
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
