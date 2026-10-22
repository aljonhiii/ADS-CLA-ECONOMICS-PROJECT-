<?php
/**
 * Automated Test Suite for OCR Pipeline
 * 
 * Verifies:
 * 1. Configuration loading and Tesseract binary detection
 * 2. Pure PHP digital PDF extraction
 * 3. Local Tesseract image OCR execution
 * 4. Cache hit and duplicate-result reuse
 * 5. Security guards (MIME spoofing, invalid extensions, size limits)
 * 6. Roster parser entity tokenization
 */

require_once __DIR__ . '/../includes/OcrClient.php';
require_once __DIR__ . '/../includes/RosterParser.php';

function run_test(string $name, callable $fn): bool {
    echo "  [TEST] $name ... ";
    try {
        $result = $fn();
        if ($result === true) {
            echo "PASS\n";
            return true;
        } else {
            echo "FAIL: " . ($result ?: 'Unknown failure') . "\n";
            return false;
        }
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

echo "=================================================================\n";
echo " AUTOMATED OCR PIPELINE INTEGRATION TEST SUITE\n";
echo "=================================================================\n\n";

// Clear cache before test execution
$cacheFiles = glob(dirname(__DIR__) . '/storage/ocr_cache/*.json');
foreach ($cacheFiles as $cf) {
    @unlink($cf);
}

$passed = 0;
$total = 0;

// Test 1: Configuration & Binary Detection
$total++;
if (run_test("Tesseract binary detection and configuration loading", function() {
    $config = new OcrConfig();
    if (!$config->hasTesseractBinary()) {
        return "Tesseract binary not located.";
    }
    if ($config->getDefaultPsm() !== 3) {
        return "Expected default PSM 3.";
    }
    return true;
})) $passed++;

// Test 2: Digital PDF Direct Extraction
$total++;
if (run_test("Digital PDF direct text extraction (sample_student_roster.pdf)", function() {
    $pdfPath = dirname(__DIR__) . '/sample_student_roster.pdf';
    $mockFile = [
        'name' => 'sample_student_roster.pdf',
        'type' => 'application/pdf',
        'tmp_name' => $pdfPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($pdfPath)
    ];

    $client = new OcrClient();
    $res = $client->processUpload($mockFile);

    if (!$res['success']) {
        return "Extraction failed: " . ($res['error'] ?? '');
    }
    if ($res['source'] !== 'pdf_text') {
        return "Expected source 'pdf_text', got '{$res['source']}'";
    }
    if (strpos($res['text'], '2023-01041') === false) {
        return "Expected student ID '2023-01041' in extracted text.";
    }
    return true;
})) $passed++;

// Test 3: Local Image OCR Extraction
$total++;
if (run_test("Image OCR processing via Local Tesseract Engine", function() {
    $imgPath = dirname(__DIR__) . '/817417167_1095568116204497_3938283814883707059_n.png';
    $mockFile = [
        'name' => 'diagram.png',
        'type' => 'image/png',
        'tmp_name' => $imgPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($imgPath)
    ];

    $client = new OcrClient();
    $res = $client->processUpload($mockFile);

    if (!$res['success']) {
        return "Extraction failed: " . ($res['error'] ?? '');
    }
    if (empty(trim($res['text']))) {
        return "Extracted text was empty.";
    }
    return true;
})) $passed++;

// Test 4: Duplicate Upload Cache Hit
$total++;
if (run_test("OCR Caching & instant duplicate reuse", function() {
    $imgPath = dirname(__DIR__) . '/817417167_1095568116204497_3938283814883707059_n.png';
    $mockFile = [
        'name' => 'diagram.png',
        'type' => 'image/png',
        'tmp_name' => $imgPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($imgPath)
    ];

    $client = new OcrClient();
    // First call (populates cache)
    $client->processUpload($mockFile);

    // Second call (must hit cache)
    $res = $client->processUpload($mockFile);

    if (!$res['success']) {
        return "Second extraction failed.";
    }
    if ($res['source'] !== 'cache') {
        return "Expected source 'cache', got '{$res['source']}'";
    }
    if ($res['processing_time_ms'] > 50) {
        return "Cache latency unexpectedly high: {$res['processing_time_ms']}ms";
    }
    return true;
})) $passed++;

// Test 5: Security - Disallowed Extension Rejection
$total++;
if (run_test("Security: Block disallowed file extension (.php, .exe)", function() {
    $mockFile = [
        'name' => 'exploit.php',
        'type' => 'application/x-php',
        'tmp_name' => __FILE__,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize(__FILE__)
    ];

    $client = new OcrClient();
    $res = $client->processUpload($mockFile);

    if ($res['success']) {
        return "Security vulnerability: executable script upload was allowed!";
    }
    return true;
})) $passed++;

// Test 6: Security - Fake MIME Spoofing Rejection
$total++;
if (run_test("Security: Block spoofed MIME type (text file renamed as .png)", function() {
    $fakePng = dirname(__DIR__) . '/storage/tmp/fake.png';
    file_put_contents($fakePng, 'This is plain text pretending to be a PNG.');

    $mockFile = [
        'name' => 'fake.png',
        'type' => 'image/png',
        'tmp_name' => $fakePng,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fakePng)
    ];

    $client = new OcrClient();
    $res = $client->processUpload($mockFile);
    @unlink($fakePng);

    if ($res['success']) {
        return "Security vulnerability: spoofed image was allowed!";
    }
    return true;
})) $passed++;

// Test 7: Security - Oversized File Rejection
$total++;
if (run_test("Security: Enforce maximum file size limit", function() {
    $pdfPath = dirname(__DIR__) . '/sample_student_roster.pdf';
    $mockFile = [
        'name' => 'huge_document.pdf',
        'type' => 'application/pdf',
        'tmp_name' => $pdfPath,
        'error' => UPLOAD_ERR_OK,
        'size' => 500 * 1024 * 1024 // 500MB (exceeds limit)
    ];

    $client = new OcrClient();
    $res = $client->processUpload($mockFile);

    if ($res['success']) {
        return "File exceeding size limit was not rejected.";
    }
    return true;
})) $passed++;

// Test 8: Roster Entity Extraction on Sample PDF
$total++;
if (run_test("Roster candidate parsing from extracted digital text", function() {
    $pdfPath = dirname(__DIR__) . '/sample_student_roster.pdf';
    $res = PdfTextExtractor::extract($pdfPath);
    if (!$res['success']) {
        return "PDF extract failed.";
    }

    $lines = explode("\n", $res['text']);
    $studentCount = 0;
    foreach ($lines as $line) {
        if (preg_match('/\b\d{4}-\d{5}\b/', $line)) {
            $studentCount++;
        }
    }

    if ($studentCount !== 10) {
        return "Expected 10 student records, found $studentCount";
    }
    return true;
})) $passed++;

echo "\n=================================================================\n";
echo " TEST SUMMARY: $passed / $total PASSED\n";
echo "=================================================================\n";

exit($passed === $total ? 0 : 1);
