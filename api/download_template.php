<?php
/**
 * API: Download Sample Student Roster Template
 * 
 * Provides a clean, UTF-8 CSV template pre-formatted with standard columns
 * for batch student enrollment. Compatible with Microsoft Excel, Google Sheets, and LibreOffice.
 */

require_once __DIR__ . '/../includes/permissions.php';
require_role(['instructor', 'dean']);

$filename = "student_roster_template.csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Output UTF-8 BOM for Microsoft Excel auto-encoding detection
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['Student Number', 'Last Name', 'First Name', 'Middle Name', 'Year & Section', 'Email']);

// Example sample rows
fputcsv($output, ['2024-04520', 'Casiraya', 'Ralph', 'Kevin', 'ACT-AD', 'ralph.casiraya@school.edu']);
fputcsv($output, ['2024-04619', 'Suaili', 'Halipa', 'G. JR', 'ACT-AD', 'halipa.suaili@school.edu']);
fputcsv($output, ['2024-03474', 'Samson', 'Sheikh', 'Al-Faez J.', 'ACT-AD', 'sheikh.samson@school.edu']);
fputcsv($output, ['2022-01414', 'Salih', 'Ashley', 'Facunla', 'ACT-AD', 'ashley.salih@school.edu']);
fputcsv($output, ['2024-03759', 'Dela Cruz', 'Eugane', 'W.', 'ACT-AD', 'eugane.delacruz@school.edu']);

fclose($output);
exit;
