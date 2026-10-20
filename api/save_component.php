<?php
/**
 * AJAX API: Save/Update/Delete Grading Component
 * 
 * Configures component weights and maximum scores dynamically,
 * recalculates total class weights, and returns updated components in JSON.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/permissions.php';

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

$action = $input_data['action'] ?? '';
$class_id = (int)($input_data['class_id'] ?? 0);

if ($class_id <= 0 || !instructor_owns_class($pdo, $class_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: class not assigned to you.']);
    exit;
}

try {
    if ($action === 'add') {
        $name   = trim($input_data['component_name'] ?? '');
        $weight = (float)($input_data['weight_percentage'] ?? 0);
        $max    = (float)($input_data['maximum_score'] ?? 0);

        if (empty($name) || $weight <= 0 || $max <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'All fields are required. Weight and Max Score must be positive.']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO grading_components (class_id, component_name, weight_percentage, maximum_score) VALUES (?, ?, ?, ?)');
        $stmt->execute([$class_id, $name, $weight, $max]);
        $new_id = $pdo->lastInsertId();

    } elseif ($action === 'delete') {
        $component_id = (int)($input_data['component_id'] ?? 0);

        // Check if grades exist for this component
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM grades WHERE component_id = ?');
        $stmt->execute([$component_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Cannot delete component: student scores have already been recorded.']);
            exit;
        }

        $stmt = $pdo->prepare('DELETE FROM grading_components WHERE component_id = ? AND class_id = ?');
        $stmt->execute([$component_id, $class_id]);

    } elseif ($action === 'update_weights') {
        $weights = $input_data['weights'] ?? [];
        if (is_array($weights)) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE grading_components SET weight_percentage = ? WHERE component_id = ? AND class_id = ?');
            foreach ($weights as $cid => $wt) {
                $stmt->execute([(float)$wt, (int)$cid, $class_id]);
            }
            $pdo->commit();
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    // Return refreshed components list and total weight
    $stmt = $pdo->prepare('SELECT * FROM grading_components WHERE class_id = ? ORDER BY component_id ASC');
    $stmt->execute([$class_id]);
    $components = $stmt->fetchAll();

    $total_weight = 0.0;
    foreach ($components as $c) {
        $total_weight += (float)$c['weight_percentage'];
    }

    echo json_encode([
        'success'      => true,
        'message'      => 'Grading components updated.',
        'total_weight' => round($total_weight, 2),
        'is_valid'     => (abs($total_weight - 100.0) < 0.01),
        'components'   => $components
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
