<?php
/**
 * API: Export Class Grade Matrix to Excel / CSV
 * 
 * Securely streams student grades across all components as a spreadsheet compatible with Excel.
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
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester, c.passing_threshold
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

// Fetch components
$comp_stmt = $pdo->prepare('SELECT * FROM grading_components WHERE class_id = ? ORDER BY component_id ASC');
$comp_stmt->execute([$class_id]);
$components = $comp_stmt->fetchAll();

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

// Fetch all recorded grades for this class
$g_stmt = $pdo->prepare(
    'SELECT g.student_id, g.component_id, g.score
     FROM grades g
     JOIN grading_components gc ON g.component_id = gc.component_id
     WHERE gc.class_id = ?'
);
$g_stmt->execute([$class_id]);
$gradeRecords = $g_stmt->fetchAll();

$gradesMatrix = [];
foreach ($gradeRecords as $gr) {
    $gradesMatrix[(int)$gr['student_id']][(int)$gr['component_id']] = (float)$gr['score'];
}

$safeSubject = preg_replace('/[^A-Za-z0-9_\-]/', '_', $class['subject_code']);
$filename = "Grades_{$safeSubject}_Class{$class_id}_" . date('Ymd') . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM

$out = fopen('php://output', 'w');

fputcsv($out, ['Course / Subject:', $class['subject_code'] . ' - ' . $class['subject_name']]);
fputcsv($out, ['School Year / Term:', $class['school_year'] . ' (' . $class['school_semester'] . ')']);
fputcsv($out, ['Passing Threshold:', $class['passing_threshold'] . '%']);
fputcsv($out, ['Export Date:', date('Y-m-d H:i:s')]);
fputcsv($out, []); // Blank line

// Headers row
$headerRow = ['#', 'Student Number', 'Student Name', 'Year & Section'];
foreach ($components as $c) {
    $headerRow[] = $c['component_name'] . ' (' . $c['weight_percentage'] . '%, Max ' . $c['max_score'] . ')';
}
$headerRow[] = 'Weighted Average (%)';
$headerRow[] = 'Status';

fputcsv($out, $headerRow);

$threshold = (float)$class['passing_threshold'];

foreach ($students as $idx => $st) {
    $sid = (int)$st['student_id'];
    $fullName = $st['last_name'] . ', ' . $st['first_name'] . ($st['middle_name'] ? ' ' . $st['middle_name'] : '');
    
    $row = [
        $idx + 1,
        $st['student_number'],
        $fullName,
        $st['yr_section']
    ];

    $weightedSum = 0.0;
    $totalWeightScored = 0.0;

    foreach ($components as $c) {
        $cid = (int)$c['component_id'];
        $maxScore = (float)$c['max_score'] ?: 100.0;
        $weight = (float)$c['weight_percentage'];

        if (isset($gradesMatrix[$sid][$cid])) {
            $score = $gradesMatrix[$sid][$cid];
            $row[] = number_format($score, 2);
            $percentage = ($maxScore > 0) ? ($score / $maxScore) * 100.0 : 0.0;
            $weightedSum += ($percentage * ($weight / 100.0));
            $totalWeightScored += $weight;
        } else {
            $row[] = '-';
        }
    }

    $finalAverage = $totalWeightScored > 0 ? $weightedSum : 0.0;
    $row[] = number_format($finalAverage, 2) . '%';
    $row[] = ($finalAverage >= $threshold) ? 'PASSED' : 'FAILED';

    fputcsv($out, $row);
}

fclose($out);
exit;
