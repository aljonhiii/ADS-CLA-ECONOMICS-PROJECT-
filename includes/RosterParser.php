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

        // 2. Extract potential candidate lines containing student numbers
        $parsedCandidates = [];
        $seenNumbers = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            // Check if line looks like header row or irrelevant metadata
            if (self::isHeaderRow($trimmed)) continue;

            $candidate = self::parseLine($trimmed, $documentSection);
            if ($candidate && !empty($candidate['student_number'])) {
                $num = $candidate['student_number'];
                // Prevent duplicate entries within the same uploaded document
                if (isset($seenNumbers[$num])) {
                    continue;
                }
                $seenNumbers[$num] = true;
                $parsedCandidates[] = $candidate;
            }
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

        // Detect line-specific section if present
        $section = $defaultSection;
        if (preg_match('/\b([A-Z]{2,5}\s*\d+[-\s]?[A-Z0-9]?|\d+[-\s][A-Z])\b/i', $remaining, $secMatch)) {
            $section = trim($secMatch[1]);
            // Remove section token from remaining text
            $remaining = str_replace($secMatch[0], '', $remaining);
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
        $last = '';
        $first = '';
        $middle = '';

        // Case A: Contains a comma -> "Dela Cruz, Juan Santos"
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name, 2);
            $last = trim($parts[0]);
            $firstMiddle = trim($parts[1]);

            $tokens = preg_split('/\s+/', $firstMiddle);
            if (count($tokens) === 1) {
                $first = $tokens[0];
            } elseif (count($tokens) >= 2) {
                // If last token is 1 letter with dot (e.g. "S.") or single word, consider middle name
                $lastToken = end($tokens);
                if (strlen(trim($lastToken, '.')) <= 2 || count($tokens) > 2) {
                    $middle = array_pop($tokens);
                    $first = implode(' ', $tokens);
                } else {
                    $middle = array_pop($tokens);
                    $first = implode(' ', $tokens);
                }
            }
        } else {
            // Case B: No comma -> "Juan Santos Dela Cruz" or "Juan Dela Cruz"
            $tokens = preg_split('/\s+/', $name);
            if (count($tokens) === 1) {
                $last = $tokens[0];
            } elseif (count($tokens) === 2) {
                $first = $tokens[0];
                $last = $tokens[1];
            } elseif (count($tokens) >= 3) {
                // Handle multi-word last names like "Dela Cruz", "De Los Reyes", "Del Rosario"
                $lastPrefixes = ['dela', 'de', 'del', 'san', 'santa', 'dos'];
                $count = count($tokens);

                if (in_array(strtolower($tokens[$count - 2]), $lastPrefixes, true)) {
                    $last = $tokens[$count - 2] . ' ' . $tokens[$count - 1];
                    array_splice($tokens, -2);
                } else {
                    $last = array_pop($tokens);
                }

                if (count($tokens) > 1) {
                    $middle = array_pop($tokens);
                    $first = implode(' ', $tokens);
                } else {
                    $first = implode(' ', $tokens);
                }
            }
        }

        return [
            'last_name' => ucwords(strtolower(trim($last))),
            'first_name' => ucwords(strtolower(trim($first))),
            'middle_name' => ucwords(strtolower(trim($middle, ' .')))
        ];
    }

    /**
     * Cross-reference extracted student numbers against the database.
     */
    private static function enrichWithDatabaseState(array $candidates, PDO $pdo, int $classId): array {
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
     * Inspect top text for section signatures.
     */
    private static function detectDocumentSection(string $text): ?string {
        if (preg_match('/(?:Section|Year\s*&\s*Section|Class|Yr\s*&\s*Sec)[:\s]+([A-Z0-9\-\s]{3,15})/i', $text, $m)) {
            $sec = trim($m[1]);
            // Filter out common noise
            if (!preg_match('/(name|student|date|instructor|subject)/i', $sec)) {
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
        $headerWords = ['student number', 'student no', 'student id', 'full name', 'first name', 'last name', 'yr & sec', 'year & section'];
        $count = 0;
        foreach ($headerWords as $w) {
            if (strpos($lower, $w) !== false) $count++;
        }
        return ($count >= 2);
    }
}
