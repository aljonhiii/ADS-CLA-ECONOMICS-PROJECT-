<?php
/**
 * Roster Parser & Student Entity Extraction Engine
 * 
 * Tokenizes raw text from OCR and digital PDFs into structured student candidate records.
 * Identifies student numbers, tokenizes names, extracts sections, and checks database state.
 */

class RosterParser {

    /**
     * Parse raw extracted text into structured student candidate records.
     *
     * @param string   $rawText         Extracted OCR/PDF text.
     * @param PDO      $pdo             Database connection.
     * @param int      $classId         Current class ID.
     * @param string   $defaultSection  Fallback year & section (from class offering).
     * @return array{
     *   candidates: array,
     *   total_found: int,
     *   new_count: int,
     *   existing_count: int,
     *   already_enrolled_count: int
     * }
     */
    public static function parse(string $rawText, PDO $pdo, int $classId, string $defaultSection = ''): array {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $rawText));

        // 1. Try to detect a document-level Year & Section header (e.g., "BSCS 3-A")
        $documentSection = self::detectDocumentSection($rawText) ?: $defaultSection;

        $parsedCandidates = [];
        $seenNumbers = [];
        $autoCounter = 1;

        // Pass 1: Try standard horizontal line-by-line parsing
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            // Check if line looks like header row or irrelevant metadata
            if (self::isHeaderRow($trimmed)) continue;

            // 1. Try standard single-candidate line with student number
            $candidate = self::parseLine($trimmed, $documentSection);
            if ($candidate && !empty($candidate['student_number'])) {
                $num = $candidate['student_number'];
                if (!isset($seenNumbers[$num])) {
                    $seenNumbers[$num] = true;
                    $parsedCandidates[] = $candidate;
                }
                continue;
            }

            // 2. Try multi-column or name-only lines (e.g., "1 Bautista, James B. 1 Cruz, Albea C.")
            $multiCandidates = self::parseNameOnlyLine($trimmed, $documentSection, $autoCounter);
            foreach ($multiCandidates as $mc) {
                $num = $mc['student_number'];
                if (!isset($seenNumbers[$num])) {
                    $seenNumbers[$num] = true;
                    $parsedCandidates[] = $mc;
                }
            }
        }

        // Pass 2: If horizontal parsing found no candidates (or fewer candidates than vertical records),
        // parse vertical/multi-line blocks where student IDs and names span consecutive lines
        $cleanLines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
        $verticalCandidates = self::parseVerticalBlocks($cleanLines, $documentSection);
        if (count($verticalCandidates) > count($parsedCandidates)) {
            $parsedCandidates = $verticalCandidates;
        }

        // 3. Cross-reference candidates against database records
        $candidates = self::enrichWithDatabaseState($parsedCandidates, $pdo, $classId);

        $newCount = 0;
        $existingCount = 0;
        $alreadyEnrolledCount = 0;

        foreach ($candidates as $c) {
            if ($c['status'] === 'already_enrolled') {
                $alreadyEnrolledCount++;
            } elseif ($c['status'] === 'existing') {
                $existingCount++;
            } else {
                $newCount++;
            }
        }

        return [
            'candidates' => $candidates,
            'total_found' => count($candidates),
            'new_count' => $newCount,
            'existing_count' => $existingCount,
            'already_enrolled_count' => $alreadyEnrolledCount,
            'detected_section' => $documentSection
        ];
    }

    /**
     * Inspect line and tokenize into student fields.
     */
    private static function parseLine(string $line, string $defaultSection): ?array {
        // Skip document title/header lines
        if (preg_match('/(academic\s*year|school\s*year|semester|official\s*class\s*roster|subject:|course:)/i', $line)) {
            return null;
        }

        // Match Student Number: e.g. 2023-00123, 23-1234-567, 2023123456
        $idPattern = '/\b(\d{4}[-\s]?\d{4,6}|\d{2}[-\s]?\d{4}[-\s]?\d{3}|\d{7,10})\b/';
        if (!preg_match($idPattern, $line, $idMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $studentNumber = trim($idMatch[1][0]);
        // Normalize: replace internal whitespace with hyphen if year prefix
        if (preg_match('/^(\d{4})\s+(\d{4,6})$/', $studentNumber, $sm)) {
            $studentNumber = $sm[1] . '-' . $sm[2];
        }

        // Guard: Skip if it's an academic year range e.g. 2024-2025 or 2025-2026
        if (preg_match('/^(\d{4})-(\d{4})$/', $studentNumber, $ym)) {
            $y1 = (int)$ym[1];
            $y2 = (int)$ym[2];
            if ($y2 === $y1 + 1 || ($y1 >= 1990 && $y1 <= 2040 && $y2 >= 1990 && $y2 <= 2040)) {
                return null;
            }
        }

        // Remove student number from line to parse name and section
        $remaining = substr_replace($line, '', $idMatch[0][1], strlen($idMatch[0][0]));

        // Remove leading row numbers like "1.", "01 -", "#1"
        $remaining = preg_replace('/^\s*#?\d+[\.\-\)]\s*/', '', $remaining);

        // Strip common roster status words
        $remaining = preg_replace('/\b(regular|irregular|enrolled|active|transferee|repeater)\b/i', '', $remaining);

        // Detect line-specific section if present, including OCR border noise (e.g. ACT-ADeeACT)
        $section = $defaultSection;
        $secRegex = '/\b([A-Z]{2,6}[-\s]?[A-Z0-9]{1,4})\s*(?:ee[A-Za-z0-9]*)?\s*$/i';
        if (preg_match($secRegex, $remaining, $secMatch)) {
            $section = trim($secMatch[1]);
            $remaining = preg_replace($secRegex, '', $remaining);
        } elseif (!empty($defaultSection)) {
            $dPattern = '/\b' . preg_quote($defaultSection, '/') . '\b(?:\s*ee[A-Za-z0-9]*)?/i';
            if (preg_match($dPattern, $remaining, $dMatch)) {
                $section = $defaultSection;
                $remaining = str_replace($dMatch[0], '', $remaining);
            }
        }

        // Clean up remaining text to parse name
        $nameText = preg_replace('/[|\t;]/', ' ', $remaining);
        $nameText = preg_replace('/\s+/', ' ', $nameText);
        $nameText = trim($nameText, " \t\n\r\0\x0B,-");

        $nameParts = self::splitName($nameText);

        if (empty($nameParts['last_name']) && empty($nameParts['first_name'])) {
            return null;
        }

        return [
            'student_number' => $studentNumber,
            'last_name' => $nameParts['last_name'],
            'first_name' => $nameParts['first_name'],
            'middle_name' => $nameParts['middle_name'],
            'yr_section' => $section ?: '1-A',
            'email' => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nameParts['first_name']) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $nameParts['last_name']) . '@school.edu')
        ];
    }

    /**
     * Tokenize name text into Last, First, and Middle name.
     */
    private static function splitName(string $name): array {
        // Case A: Contains a comma -> "Dela Cruz, Juan Santos" (Standard Last, First Middle)
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name, 2);
            $last = trim($parts[0]);
            $firstMiddle = trim($parts[1]);

            $tokens = preg_split('/\s+/', $firstMiddle);
            $first = '';
            $middle = '';
            if (count($tokens) === 1) {
                $first = $tokens[0];
            } elseif (count($tokens) >= 2) {
                $middle = array_pop($tokens);
                $first = implode(' ', $tokens);
            }
            return [
                'last_name' => ucwords(strtolower($last)),
                'first_name' => ucwords(strtolower($first)),
                'middle_name' => ucwords(strtolower(trim($middle, ' .')))
            ];
        }

        // Case B: Tabular roster format (Last Name, First Name, Middle Name space-separated)
        $tokens = preg_split('/\s+/', trim($name));
        if (empty($tokens)) {
            return ['last_name' => '', 'first_name' => '', 'middle_name' => ''];
        }

        if (count($tokens) === 1) {
            return ['last_name' => ucwords(strtolower($tokens[0])), 'first_name' => '', 'middle_name' => ''];
        }

        if (count($tokens) === 2) {
            return ['last_name' => ucwords(strtolower($tokens[0])), 'first_name' => ucwords(strtolower($tokens[1])), 'middle_name' => ''];
        }

        // Compound Filipino Last Names ("Dela Cruz", "De Los Santos", etc.)
        $lastPrefixes = ['dela', 'de', 'del', 'san', 'santa', 'dos', 'al'];
        $last = '';
        if (in_array(strtolower($tokens[0]), $lastPrefixes, true) && count($tokens) >= 3) {
            if (strtolower($tokens[0]) === 'de' && in_array(strtolower($tokens[1]), ['los', 'la', 'las'], true) && count($tokens) >= 4) {
                $last = $tokens[0] . ' ' . $tokens[1] . ' ' . $tokens[2];
                array_splice($tokens, 0, 3);
            } else {
                $last = $tokens[0] . ' ' . $tokens[1];
                array_splice($tokens, 0, 2);
            }
        } else {
            $last = array_shift($tokens);
        }

        // Check for Suffix at end: JR, SR, II, III, IV
        $suffix = '';
        $lastToken = end($tokens);
        if (preg_match('/^(jr\.?|sr\.?|ii|iii|iv)$/i', $lastToken)) {
            $suffix = ' ' . array_pop($tokens);
        }

        // Middle name is the final token (e.g. initial "W.", "S.", or full "Facunla", "Ishikawa")
        $middle = '';
        if (!empty($tokens)) {
            $middle = array_pop($tokens) . $suffix;
        }
        $first = implode(' ', $tokens);

        return [
            'last_name' => ucwords(strtolower(trim($last))),
            'first_name' => ucwords(strtolower(trim($first))),
            'middle_name' => ucwords(strtolower(trim($middle, ' .')))
        ];
    }

    /**
     * Cross-reference extracted student numbers against the database.
     */
    public static function enrichWithDatabaseState(array $candidates, PDO $pdo, int $classId): array {
        if (empty($candidates)) {
            return [];
        }

        $numbers = array_column($candidates, 'student_number');
        $placeholders = implode(',', array_fill(0, count($numbers), '?'));

        // Query existing students
        $stmt = $pdo->prepare(
            "SELECT s.student_id, s.student_number, s.first_name, s.middle_name, s.last_name, s.yr_section,
                    (SELECT COUNT(*) FROM student_classes sc WHERE sc.student_id = s.student_id AND sc.class_id = ?) AS is_enrolled
             FROM students s
             WHERE s.student_number IN ($placeholders)"
        );

        $params = array_merge([$classId], $numbers);
        $stmt->execute($params);
        $dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dbMap = [];
        foreach ($dbRows as $row) {
            $dbMap[$row['student_number']] = $row;
        }

        $enriched = [];
        foreach ($candidates as $c) {
            $num = $c['student_number'];
            if (isset($dbMap[$num])) {
                $match = $dbMap[$num];
                $isEnrolled = (int)$match['is_enrolled'] > 0;
                $enriched[] = [
                    'student_number' => $num,
                    // Use database verified name if present, or keep OCR if user wants to update
                    'last_name' => $c['last_name'] ?: $match['last_name'],
                    'first_name' => $c['first_name'] ?: $match['first_name'],
                    'middle_name' => $c['middle_name'] ?: ($match['middle_name'] ?? ''),
                    'yr_section' => $c['yr_section'] ?: $match['yr_section'],
                    'status' => $isEnrolled ? 'already_enrolled' : 'existing',
                    'student_id' => (int)$match['student_id'],
                    'selected' => !$isEnrolled // Pre-check if not already enrolled
                ];
            } else {
                $enriched[] = [
                    'student_number' => $num,
                    'last_name' => $c['last_name'],
                    'first_name' => $c['first_name'],
                    'middle_name' => $c['middle_name'],
                    'yr_section' => $c['yr_section'],
                    'status' => 'new',
                    'student_id' => null,
                    'selected' => true
                ];
            }
        }

        return $enriched;
    }

    /**
     * Parse multi-column or name-only lines (e.g. DepEd format: "1 Bautista, James B. 1 Cruz, Albea C.").
     */
    private static function parseNameOnlyLine(string $line, string $defaultSection, int &$counter): array {
        // Exclude document headers and administrative metadata lines
        if (preg_match('/(republic\s*of|department\s*of|division\s*of|high\s*school|district|list\s*of\s*students|noted\s*by|school\s*head|teacher|principal|adviser|\bno\s+boys\b|\bno\s+girls\b)/i', $line)) {
            return [];
        }

        // Must contain at least one comma to indicate "LastName, FirstName"
        if (strpos($line, ',') === false) {
            return [];
        }

        // Match one or more [Number] LastName, FirstName [MiddleInitial] patterns on the line
        $pattern = '/(?:^|\s+)(?:(\d{1,2})\s+)?([A-Z][a-zA-Z\'\s\-]+,\s*[A-Z][a-zA-Z\'\s\-]+(?:\s+[A-Z]\.?)?)(?=\s+\d{1,2}\s+[A-Z]|$)/';
        if (!preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $results = [];
        foreach ($matches as $m) {
            $rawName = trim($m[2]);
            $nameParts = self::splitName($rawName);
            if (empty($nameParts['last_name']) && empty($nameParts['first_name'])) {
                continue;
            }

            $generatedNum = sprintf('%s-%04d', date('Y'), $counter++);

            $results[] = [
                'student_number' => $generatedNum,
                'last_name' => $nameParts['last_name'],
                'first_name' => $nameParts['first_name'],
                'middle_name' => $nameParts['middle_name'],
                'yr_section' => $defaultSection ?: '1-A',
                'email' => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nameParts['first_name']) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $nameParts['last_name']) . '@school.edu')
            ];
        }

        return $results;
    }

    /**
     * Inspect top text for section signatures.
     */
    private static function detectDocumentSection(string $text): ?string {
        if (preg_match('/(?:GRADE\s*\d+\s*[-–—:]\s*[A-Za-z0-9\s]+)/i', $text, $m)) {
            return trim($m[0]);
        }
        if (preg_match('/(?:Section|Year\s*&\s*Section|Class|Yr\s*&\s*Sec)[:\s]+([A-Za-z0-9\-_]{2,15})/i', $text, $m)) {
            $sec = trim($m[1]);
            // Filter out common noise
            if (!preg_match('/(name|student|date|instructor|subject|number|email)/i', $sec)) {
                return $sec;
            }
        }
        return null;
    }

    /**
     * Check if a line is just table header names (e.g. "Student ID | Name | Section").
     */
    private static function isHeaderRow(string $line): bool {
        $lower = strtolower($line);
        if (preg_match('/(republic\s*of|department\s*of|division\s*of|high\s*school|list\s*of\s*students|noted\s*by|school\s*head|teacher\s*-\s*in\s*-\s*charge|\bno\s+boys\b|\bno\s+girls\b)/i', $line)) {
            return true;
        }
        $headerWords = ['student number', 'student no', 'student id', 'full name', 'first name', 'last name', 'yr & sec', 'year & section'];
        $count = 0;
        foreach ($headerWords as $w) {
            if (strpos($lower, $w) !== false) $count++;
        }
        return ($count >= 2);
    }

    /**
     * Parse multi-line/vertical student blocks where student number, last name,
     * first name, middle name, and section are on consecutive lines.
     */
    public static function parseVerticalBlocks(array $cleanLines, string $defaultSection = ''): array {
        $idPattern = '/^#?\s*(\d{4}[-\s]?\d{4,6}|\d{2}[-\s]?\d{4}[-\s]?\d{3}|\d{7,10})$/';
        $studentIndices = [];
        foreach ($cleanLines as $idx => $line) {
            if (preg_match($idPattern, trim($line), $m)) {
                $studentIndices[] = ['idx' => $idx, 'number' => $m[1]];
            }
        }

        if (empty($studentIndices)) {
            return [];
        }

        $candidates = [];
        $totalLines = count($cleanLines);

        for ($i = 0; $i < count($studentIndices); $i++) {
            $cur = $studentIndices[$i];
            $nextIdx = ($i + 1 < count($studentIndices)) ? $studentIndices[$i + 1]['idx'] : $totalLines;

            $sNum = $cur['number'];
            $fields = array_values(array_filter(
                array_slice($cleanLines, $cur['idx'] + 1, $nextIdx - $cur['idx'] - 1),
                fn($f) => trim($f) !== '' && !preg_match('/^(student\s*list|official\s*class|page\s*\d+|section\s*:|grade\s*:)/i', $f)
            ));

            if (empty($fields)) {
                continue;
            }

            $lName = '';
            $fName = '';
            $mName = '';
            $sec = $defaultSection;

            // Check if the last field is a section (e.g. ACT-AD, BSIT-3A)
            $secPattern = '/^([A-Z]{2,6}[-\s]?[A-Z0-9]{1,4})\s*(?:ee[A-Za-z0-9]*)?$/i';
            if (count($fields) >= 2 && preg_match($secPattern, end($fields), $sm)) {
                $sec = trim($sm[1]);
                array_pop($fields);
            }

            if (count($fields) >= 3) {
                $lName = $fields[0];
                $fName = $fields[1];
                $mName = implode(' ', array_slice($fields, 2));
            } elseif (count($fields) === 2) {
                $lName = $fields[0];
                $fName = $fields[1];
            } elseif (count($fields) === 1) {
                $single = $fields[0];
                if (strpos($single, ',') !== false) {
                    $p = explode(',', $single, 2);
                    $lName = trim($p[0]);
                    $tokens = preg_split('/\s+/', trim($p[1]));
                    $mName = count($tokens) >= 2 ? array_pop($tokens) : '';
                    $fName = implode(' ', $tokens);
                } else {
                    $tokens = preg_split('/\s+/', trim($single));
                    $lName = array_shift($tokens);
                    $mName = count($tokens) >= 2 ? array_pop($tokens) : '';
                    $fName = implode(' ', $tokens);
                }
            }

            if (!empty($lName) || !empty($fName)) {
                $candidates[] = [
                    'student_number' => $sNum,
                    'last_name' => ucwords(strtolower(trim($lName))),
                    'first_name' => ucwords(strtolower(trim($fName))),
                    'middle_name' => ucwords(strtolower(trim($mName, ' .'))),
                    'yr_section' => $sec ?: ($defaultSection ?: '1-A'),
                    'email' => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fName) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $lName) . '@school.edu')
                ];
            }
        }

        return $candidates;
    }
}
