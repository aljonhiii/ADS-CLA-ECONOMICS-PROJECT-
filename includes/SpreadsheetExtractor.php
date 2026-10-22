<?php
/**
 * Native Spreadsheet Extractor (Excel .xlsx & Delimited .csv)
 * 
 * Provides pure-PHP parsing of OpenXML spreadsheets (.xlsx) and CSV files (.csv)
 * with zero external dependencies (uses PHP's built-in ZipArchive and SimpleXML).
 * 
 * Extracts tabular student rosters, detects column headers, maps student fields,
 * and formats candidate records for immediate preview and database enrollment.
 */

class SpreadsheetExtractor {

    /**
     * Extract structured data and text from an uploaded spreadsheet file (.xlsx, .xls, .csv).
     *
     * @param string $filePath     Path to uploaded temporary file.
     * @param string $originalName Original filename.
     * @return array{
     *   success: bool,
     *   source: 'spreadsheet',
     *   text: string,
     *   rows: array,
     *   candidates?: array,
     *   filename: string,
     *   detected_section?: string,
     *   error?: string
     * }
     */
    public static function extract(string $filePath, string $originalName): array {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!is_file($filePath) || !is_readable($filePath)) {
            return [
                'success' => false,
                'source' => 'spreadsheet',
                'text' => '',
                'rows' => [],
                'filename' => $originalName,
                'error' => 'Spreadsheet file could not be read from temporary storage.'
            ];
        }

        try {
            if ($ext === 'csv') {
                $rows = self::parseCsv($filePath);
            } elseif ($ext === 'xlsx' || $ext === 'xls') {
                // Try OpenXML ZipArchive parser first
                $rows = self::parseXlsx($filePath);
            } else {
                return [
                    'success' => false,
                    'source' => 'spreadsheet',
                    'text' => '',
                    'rows' => [],
                    'filename' => $originalName,
                    'error' => "Unsupported spreadsheet format '{$ext}'. Please upload an .xlsx or .csv file."
                ];
            }

            if (empty($rows)) {
                return [
                    'success' => false,
                    'source' => 'spreadsheet',
                    'text' => '',
                    'rows' => [],
                    'filename' => $originalName,
                    'error' => 'The uploaded spreadsheet appears to be empty.'
                ];
            }

            // Build reconstructed plain text for preview and manual re-parse
            $textLines = [];
            foreach ($rows as $r) {
                $cleanCells = array_filter(array_map('trim', $r), fn($v) => $v !== '');
                if (!empty($cleanCells)) {
                    $textLines[] = implode("\t", $cleanCells);
                }
            }
            $rawText = implode("\n", $textLines);

            // Attempt structured mapping from table headers
            $analysis = self::analyzeAndMapRows($rows);

            return [
                'success' => true,
                'source' => 'spreadsheet',
                'text' => $rawText,
                'rows' => $rows,
                'candidates' => $analysis['candidates'],
                'detected_section' => $analysis['detected_section'],
                'filename' => $originalName,
                'message' => "Extracted " . count($analysis['candidates']) . " student rows from spreadsheet."
            ];

        } catch (Throwable $e) {
            error_log("SpreadsheetExtractor error: " . $e->getMessage());
            return [
                'success' => false,
                'source' => 'spreadsheet',
                'text' => '',
                'rows' => [],
                'filename' => $originalName,
                'error' => 'Spreadsheet parsing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Parse OpenXML (.xlsx) file using native ZipArchive and SimpleXML.
     */
    public static function parseXlsx(string $filePath): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required to read .xlsx files.');
        }

        $zip = new ZipArchive();
        $status = $zip->open($filePath);
        if ($status !== true) {
            // Check if file is actually a CSV saved with .xlsx/.xls extension
            $csvFallback = self::parseCsv($filePath);
            if (!empty($csvFallback)) {
                return $csvFallback;
            }
            throw new RuntimeException('Could not open file as valid OpenXML zip archive (Code ' . (int)$status . ').');
        }

        // 1. Read Shared Strings (xl/sharedStrings.xml)
        $sharedStrings = [];
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXml !== false) {
            $xml = @simplexml_load_string($stringsXml);
            if ($xml !== false && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        // Rich text run: concatenate <r><t> parts
                        $tParts = [];
                        foreach ($si->r as $r) {
                            $tParts[] = (string)$r->t;
                        }
                        $sharedStrings[] = implode('', $tParts);
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Read Primary Worksheet (xl/worksheets/sheet1.xml)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            // Try finding any worksheet in xl/worksheets/
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $stat['name'])) {
                    $sheetXml = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('No worksheet data found in .xlsx archive.');
        }

        $sheet = @simplexml_load_string($sheetXml);
        if ($sheet === false || !isset($sheet->sheetData)) {
            throw new RuntimeException('Invalid or unreadable worksheet XML structure.');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rowCells = [];
            $maxColIdx = 0;

            foreach ($row->c as $c) {
                $ref = (string)$c['r']; // e.g. "A1", "B2"
                $colIndex = self::columnLetterToIndex($ref);
                $type = (string)$c['t']; // e.g. "s" (shared string), "inlineStr", or "" (number/formula)

                $val = '';
                if ($type === 's') {
                    $idx = (int)$c->v;
                    $val = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                } elseif (isset($c->v)) {
                    $val = (string)$c->v;
                }

                $rowCells[$colIndex] = trim($val);
                if ($colIndex > $maxColIdx) {
                    $maxColIdx = $colIndex;
                }
            }

            // Fill empty cells to preserve column positions
            $denseRow = [];
            for ($col = 0; $col <= $maxColIdx; $col++) {
                $denseRow[] = $rowCells[$col] ?? '';
            }

            // Only add if row has at least one non-empty cell
            if (array_filter($denseRow, fn($v) => $v !== '') !== []) {
                $rows[] = $denseRow;
            }
        }

        return $rows;
    }

    /**
     * Parse CSV file with automatic delimiter detection and UTF-8 BOM removal.
     */
    public static function parseCsv(string $filePath): array {
        $content = @file_get_contents($filePath);
        if ($content === false || strlen($content) === 0) {
            return [];
        }

        // Strip UTF-8 BOM if present
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // Detect delimiter: count commas, semicolons, and tabs in first few lines
        $firstLines = array_slice(explode("\n", $content), 0, 5);
        $sample = implode("\n", $firstLines);

        $delimiters = [',', ';', "\t"];
        $bestDelim = ',';
        $maxCount = 0;
        foreach ($delimiters as $d) {
            $count = substr_count($sample, $d);
            if ($count > $maxCount) {
                $maxCount = $count;
                $bestDelim = $d;
            }
        }

        $rows = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        while (($data = fgetcsv($stream, 0, $bestDelim)) !== false) {
            $cleanRow = array_map('trim', $data);
            if (array_filter($cleanRow, fn($v) => $v !== '') !== []) {
                $rows[] = $cleanRow;
            }
        }

        fclose($stream);
        return $rows;
    }

    /**
     * Analyze extracted rows: Find headers, map student columns, or extract raw candidates.
     */
    private static function analyzeAndMapRows(array $rows): array {
        $headerRowIdx = -1;
        $colMap = [
            'student_number' => -1,
            'last_name' => -1,
            'first_name' => -1,
            'middle_name' => -1,
            'full_name' => -1,
            'yr_section' => -1,
            'email' => -1
        ];

        $detectedSection = '';

        // Scan first 5 rows for column header keywords and section title
        foreach (array_slice($rows, 0, 6) as $rIdx => $row) {
            $joined = strtolower(implode(' ', $row));

            // Check for section title like "Section: ACT-AD" or "Grade 8 - Valencia"
            if (preg_match('/(?:section|grade|class|yr\s*&\s*sec)\s*[:=]\s*([A-Za-z0-9\-\s]{2,20})/i', $joined, $sm)) {
                $candSec = trim($sm[1]);
                if (!in_array(strtolower($candSec), ['email', 'name', 'student', 'number'], true)) {
                    $detectedSection = $candSec;
                }
            }

            $matches = 0;
            $tempMap = $colMap;

            foreach ($row as $cIdx => $cell) {
                $norm = strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-zA-Z0-9]/', ' ', $cell))));

                if (in_array($norm, ['student number', 'student no', 'student id', 'id number', 'lrn', 'studentid', 'idno', 'id'], true)) {
                    $tempMap['student_number'] = $cIdx;
                    $matches++;
                } elseif (in_array($norm, ['last name', 'lastname', 'surname', 'family name'], true)) {
                    $tempMap['last_name'] = $cIdx;
                    $matches++;
                } elseif (in_array($norm, ['first name', 'firstname', 'given name'], true)) {
                    $tempMap['first_name'] = $cIdx;
                    $matches++;
                } elseif (in_array($norm, ['middle name', 'middlename', 'middle initial', 'mi'], true)) {
                    $tempMap['middle_name'] = $cIdx;
                    $matches++;
                } elseif (in_array($norm, ['full name', 'name', 'student name', 'fullname'], true)) {
                    $tempMap['full_name'] = $cIdx;
                    $matches++;
                } elseif (in_array($norm, ['year section', 'year and section', 'yr sec', 'yr and sec', 'section', 'class', 'grade'], true)) {
                    $tempMap['yr_section'] = $cIdx;
                    $matches++;
                } elseif (in_array($norm, ['email', 'email address'], true)) {
                    $tempMap['email'] = $cIdx;
                }
            }

            if ($matches >= 2) {
                $headerRowIdx = $rIdx;
                $colMap = $tempMap;
                break;
            }
        }

        $candidates = [];

        // If standard table headers were found, map column cells directly
        if ($headerRowIdx !== -1) {
            $dataRows = array_slice($rows, $headerRowIdx + 1);
            $seq = 1;

            foreach ($dataRows as $row) {
                $sNum = $colMap['student_number'] !== -1 ? ($row[$colMap['student_number']] ?? '') : '';
                $lName = $colMap['last_name'] !== -1 ? ($row[$colMap['last_name']] ?? '') : '';
                $fName = $colMap['first_name'] !== -1 ? ($row[$colMap['first_name']] ?? '') : '';
                $mName = $colMap['middle_name'] !== -1 ? ($row[$colMap['middle_name']] ?? '') : '';
                $sec = $colMap['yr_section'] !== -1 ? ($row[$colMap['yr_section']] ?? '') : '';
                $email = $colMap['email'] !== -1 ? ($row[$colMap['email']] ?? '') : '';

                // Handle single "Full Name" column
                if (($lName === '' || $fName === '') && $colMap['full_name'] !== -1 && !empty($row[$colMap['full_name']])) {
                    $fullName = trim($row[$colMap['full_name']]);
                    $split = self::splitFullName($fullName);
                    $lName = $split['last_name'];
                    $fName = $split['first_name'];
                    $mName = $split['middle_name'];
                }

                // If student number is missing, generate sequential placeholder
                if (empty($sNum) && (!empty($fName) || !empty($lName))) {
                    $sNum = sprintf('%s-%04d', date('Y'), $seq++);
                }

                if (!empty($sNum) && (!empty($lName) || !empty($fName))) {
                    $candidates[] = [
                        'student_number' => trim($sNum),
                        'last_name' => ucwords(strtolower(trim($lName))),
                        'first_name' => ucwords(strtolower(trim($fName))),
                        'middle_name' => ucwords(strtolower(trim($mName, ' .'))),
                        'yr_section' => trim($sec) ?: ($detectedSection ?: '1-A'),
                        'email' => $email ?: strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fName) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $lName) . '@school.edu')
                    ];
                }
            }
        } else {
            // Fallback: If no headers were found, scan each row for student ID or names
            $seq = 1;
            foreach ($rows as $row) {
                $joined = implode(' ', array_filter($row));
                if (empty($joined)) continue;
                if (preg_match('/(academic\s*year|semester|subject:|course:|student\s*list)/i', $joined)) continue;

                // Match student number e.g. 2024-04520
                $idPattern = '/\b(\d{4}[-\s]?\d{4,6}|\d{2}[-\s]?\d{4}[-\s]?\d{3}|\d{7,10})\b/';
                if (preg_match($idPattern, $joined, $im)) {
                    $sNum = $im[1];
                    $rest = trim(str_replace($sNum, '', $joined));
                    $split = self::splitFullName($rest);
                    if (!empty($split['last_name']) || !empty($split['first_name'])) {
                        $candidates[] = [
                            'student_number' => $sNum,
                            'last_name' => $split['last_name'],
                            'first_name' => $split['first_name'],
                            'middle_name' => $split['middle_name'],
                            'yr_section' => $detectedSection ?: '1-A',
                            'email' => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $split['first_name']) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $split['last_name']) . '@school.edu')
                        ];
                    }
                }
            }
        }

        return [
            'candidates' => $candidates,
            'detected_section' => $detectedSection
        ];
    }

    /**
     * Convert Excel cell reference like "A1", "C5", "AA2" into a 0-based column index.
     */
    private static function columnLetterToIndex(string $cellRef): int {
        if (!preg_match('/^([A-Z]+)/i', $cellRef, $matches)) {
            return 0;
        }
        $letters = strtoupper($matches[1]);
        $len = strlen($letters);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    /**
     * Split a full name into Last, First, Middle.
     */
    private static function splitFullName(string $name): array {
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name, 2);
            $last = trim($parts[0]);
            $firstMiddle = trim($parts[1]);
            $tokens = preg_split('/\s+/', $firstMiddle);
            $middle = count($tokens) >= 2 ? array_pop($tokens) : '';
            $first = implode(' ', $tokens);
            return [
                'last_name' => $last,
                'first_name' => $first,
                'middle_name' => $middle
            ];
        }

        $tokens = preg_split('/\s+/', trim($name));
        if (empty($tokens)) {
            return ['last_name' => '', 'first_name' => '', 'middle_name' => ''];
        }
        if (count($tokens) === 1) {
            return ['last_name' => $tokens[0], 'first_name' => '', 'middle_name' => ''];
        }
        if (count($tokens) === 2) {
            return ['last_name' => $tokens[0], 'first_name' => $tokens[1], 'middle_name' => ''];
        }

        $lastPrefixes = ['dela', 'de', 'del', 'san', 'santa', 'dos', 'al'];
        if (in_array(strtolower($tokens[0]), $lastPrefixes, true)) {
            $last = $tokens[0] . ' ' . $tokens[1];
            array_splice($tokens, 0, 2);
        } else {
            $last = array_shift($tokens);
        }

        $middle = count($tokens) >= 2 ? array_pop($tokens) : '';
        $first = implode(' ', $tokens);

        return [
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle
        ];
    }
}
