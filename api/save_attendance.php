<?php
/**
 * AJAX API: Save Attendance Record
 * 
 * Auto-saves individual or bulk attendance records in real time,
 * creates absence notifications, and returns refreshed KPI metrics
 * (Present, Absent, Late, Excused counts and rate %) without reloading.
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

$action = $input_data['action'] ?? 'single';
$session_id = (int)($input_data['session_id'] ?? 0);

if ($session_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session ID.']);
    exit;
}

// Verify that the instructor owns the session
$sess_stmt = $pdo->prepare(
    'SELECT cs.session_id, cs.class_id, cs.session_date, s.subject_code
     FROM class_sessions cs
     JOIN classes c ON cs.class_id = c.class_id
     JOIN subjects s ON c.subject_id = s.subject_id
     WHERE cs.session_id = ? AND c.instructor_id = ?'
);
$sess_stmt->execute([$session_id, $instructor['instructor_id']]);
$session_info = $sess_stmt->fetch();

if (!$session_info) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: session not assigned to you.']);
    exit;
}

$class_id   = (int)$session_info['class_id'];
$sess_date  = $session_info['session_date'];
$subj_code  = $session_info['subject_code'];

try {
    $pdo->beginTransaction();

    $stmt_check = $pdo->prepare(
        'SELECT attendance_id, status FROM attendance WHERE student_class_id = ? AND session_id = ?'
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

    if ($action === 'mark_all_present') {
        // Fetch all enrolled students for this class
        $sc_stmt = $pdo->prepare('SELECT student_class_id FROM student_classes WHERE class_id = ?');
        $sc_stmt->execute([$class_id]);
        $all_sc = $sc_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($all_sc as $sc_id) {
            $stmt_check->execute([$sc_id, $session_id]);
            $existing = $stmt_check->fetch();
            if ($existing) {
                if ($existing['status'] !== 'Present') {
                    $stmt_update->execute(['Present', $existing['attendance_id']]);
                }
            } else {
                $stmt_insert->execute([$sc_id, $session_id, 'Present']);
            }
        }
    } else {
        // Single student update
        $student_class_id = (int)($input_data['student_class_id'] ?? 0);
        $status = trim($input_data['status'] ?? '');

        if ($student_class_id <= 0 || !in_array($status, ['Present', 'Absent', 'Late', 'Excused'], true)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid student or status parameter.']);
            exit;
        }

        $stmt_check->execute([$student_class_id, $session_id]);
        $existing = $stmt_check->fetch();

        if ($existing) {
            if ($existing['status'] !== $status) {
                $stmt_update->execute([$status, $existing['attendance_id']]);

                // Create notification if status changed to Absent
                if ($status === 'Absent') {
                    $student_lookup->execute([$student_class_id]);
                    $st_id = $student_lookup->fetchColumn();
                    if ($st_id) {
                        $msg = "You were marked Absent for {$subj_code} on {$sess_date}.";
                        $notif_stmt->execute([$st_id, $existing['attendance_id'], 'absence', $msg]);
                    }
                }
            }
        } else {
            $stmt_insert->execute([$student_class_id, $session_id, $status]);
            $new_att_id = $pdo->lastInsertId();

            if ($status === 'Absent') {
                $student_lookup->execute([$student_class_id]);
                $st_id = $student_lookup->fetchColumn();
                if ($st_id) {
                    $msg = "You were marked Absent for {$subj_code} on {$sess_date}.";
                    $notif_stmt->execute([$st_id, $new_att_id, 'absence', $msg]);
                }
            }
        }
    }

    // Recalculate session KPI stats
    $kpi_stmt = $pdo->prepare(
        'SELECT 
            COUNT(*) AS total_count,
            SUM(CASE WHEN a.status = "Present" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN a.status = "Absent" THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN a.status = "Late" THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN a.status = "Excused" THEN 1 ELSE 0 END) AS excused_count
         FROM attendance a
         JOIN student_classes sc ON a.student_class_id = sc.student_class_id
         WHERE a.session_id = ? AND sc.class_id = ?'
    );
    $kpi_stmt->execute([$session_id, $class_id]);
    $kpis = $kpi_stmt->fetch();

    $tot = (int)($kpis['total_count'] ?? 0);
    $pres = (int)($kpis['present_count'] ?? 0);
    $abs = (int)($kpis['absent_count'] ?? 0);
    $late = (int)($kpis['late_count'] ?? 0);
    $exc = (int)($kpis['excused_count'] ?? 0);
    $rate = $tot > 0 ? round((($pres + $late) / $tot) * 100, 1) : 0;

    $pdo->commit();

    echo json_encode([
        'success'         => true,
        'message'         => ($action === 'mark_all_present') ? 'All students marked Present.' : 'Attendance updated.',
        'session_id'      => $session_id,
        'present_count'   => $pres,
        'absent_count'    => $abs,
        'late_count'      => $late,
        'excused_count'   => $exc,
        'total_count'     => $tot,
        'attendance_rate' => $rate
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
