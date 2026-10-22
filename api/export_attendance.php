<?php
/**
 * API: Export Class Attendance Sheet to Excel / CSV
 * 
 * Securely streams student attendance records and percentage summaries as a spreadsheet.
 */

require_once __DIR__ . '/../includes/permissions.php';
require_role(['instructor', 'dean']);

$pdo = db_connect();
$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id <= 0) {
    http_response_code(400);
    die('Invalid class ID specified.');
}

if (current_user()['role'] === 'instructor' && !instructor_owns_class($pdo, $class_id)) {
    http_response_code(403);
    die('Access denied.');
}

// Fetch class info
$c_stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.class_id = ?'
);
$c_stmt->execute([$class_id]);
$class = $c_stmt->fetch();

if (!$class) {
    http_response_code(404);
    die('Class not found.');
}

// Fetch enrolled students
$s_stmt = $pdo->prepare(
    'SELECT s.student_id, s.student_number, s.last_name, s.first_name, s.middle_name, s.yr_section
     FROM student_classes sc
     JOIN students s ON sc.student_id = s.student_id
     WHERE sc.class_id = ?
     ORDER BY s.last_name ASC, s.first_name ASC'
);
$s_stmt->execute([$class_id]);
$students = $s_stmt->fetchAll();

// Fetch attendance totals per student
$att_stmt = $pdo->prepare(
    'SELECT student_id,
            SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN status = "excused" THEN 1 ELSE 0 END) AS excused_count,
            COUNT(*) AS total_sessions
     FROM attendance
     WHERE class_id = ?
     GROUP BY student_id'
);
$att_stmt->execute([$class_id]);
$attRecords = $att_stmt->fetchAll();

$attMap = [];
foreach ($attRecords as $ar) {
    $attMap[(int)$ar['student_id']] = $ar;
}

$safeSubject = preg_replace('/[^A-Za-z0-9_\-]/', '_', $class['subject_code']);
$filename = "Attendance_{$safeSubject}_Class{$class_id}_" . date('Ymd') . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM

$out = fopen('php://output', 'w');

fputcsv($out, ['Course / Subject:', $class['subject_code'] . ' - ' . $class['subject_name']]);
fputcsv($out, ['School Year / Term:', $class['school_year'] . ' (' . $class['school_semester'] . ')']);
fputcsv($out, ['Export Date:', date('Y-m-d H:i:s')]);
fputcsv($out, []); // Blank line

fputcsv($out, ['#', 'Student Number', 'Student Name', 'Year & Section', 'Present', 'Late', 'Absent', 'Excused', 'Total Sessions', 'Attendance Rate (%)']);

foreach ($students as $idx => $st) {
    $sid = (int)$st['student_id'];
    $fullName = $st['last_name'] . ', ' . $st['first_name'] . ($st['middle_name'] ? ' ' . $st['middle_name'] : '');
    
    $present = (int)($attMap[$sid]['present_count'] ?? 0);
    $late    = (int)($attMap[$sid]['late_count'] ?? 0);
    $absent  = (int)($attMap[$sid]['absent_count'] ?? 0);
    $excused = (int)($attMap[$sid]['excused_count'] ?? 0);
    $total   = (int)($attMap[$sid]['total_sessions'] ?? 0);

    // Rate: (Present + 0.5 * Late + Excused) / Total
    $rate = ($total > 0) ? round((($present + ($late * 0.5) + $excused) / $total) * 100, 1) : 100.0;

    fputcsv($out, [
        $idx + 1,
        $st['student_number'],
        $fullName,
        $st['yr_section'],
        $present,
        $late,
        $absent,
        $excused,
        $total,
        $rate . '%'
    ]);
}

fclose($out);
exit;
