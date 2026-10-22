<?php
/**
 * API: Export Enrolled Class Roster to Excel / CSV
 * 
 * Securely streams the class roster as a UTF-8 CSV spreadsheet compatible with Microsoft Excel.
 */

require_once __DIR__ . '/../includes/permissions.php';
require_role(['instructor', 'dean']);

$pdo = db_connect();
$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id <= 0) {
    http_response_code(400);
    die('Invalid class ID specified.');
}

// Check authorization
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
    'SELECT s.student_number, s.last_name, s.first_name, s.middle_name, s.yr_section, s.email, sc.enrolled_at
     FROM student_classes sc
     JOIN students s ON sc.student_id = s.student_id
     WHERE sc.class_id = ?
     ORDER BY s.last_name ASC, s.first_name ASC'
);
$s_stmt->execute([$class_id]);
$students = $s_stmt->fetchAll();

$safeSubject = preg_replace('/[^A-Za-z0-9_\-]/', '_', $class['subject_code']);
$filename = "Roster_{$safeSubject}_Class{$class_id}_" . date('Ymd') . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM for Microsoft Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Document Metadata Headers
fputcsv($out, ['Course / Subject:', $class['subject_code'] . ' - ' . $class['subject_name']]);
fputcsv($out, ['School Year / Term:', $class['school_year'] . ' (' . $class['school_semester'] . ')']);
fputcsv($out, ['Total Enrolled:', count($students)]);
fputcsv($out, ['Export Date:', date('Y-m-d H:i:s')]);
fputcsv($out, []); // Blank line

// Column headers
fputcsv($out, ['#', 'Student Number', 'Last Name', 'First Name', 'Middle Name', 'Year & Section', 'Email', 'Enrolled Date']);

foreach ($students as $idx => $st) {
    fputcsv($out, [
        $idx + 1,
        $st['student_number'],
        $st['last_name'],
        $st['first_name'],
        $st['middle_name'] ?? '',
        $st['yr_section'],
        $st['email'] ?? '',
        $st['enrolled_at'] ?? ''
    ]);
}

fclose($out);
exit;
