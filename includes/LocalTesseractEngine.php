<?php
/**
 * Local Tesseract OCR Engine
 * 
 * Provides direct, secure local execution of the Tesseract OCR CLI on Windows / Linux
 * without requiring the Python microservice to be running.
 * 
 * Security features:
 * - Complete command injection prevention (escapeshellarg and strict argument validation)
 * - Isolated temporary working directories in storage/tmp/ outside web root
 * - Cryptographically random temporary filenames
 * - Process execution timeouts with auto-termination
 * - Guaranteed cleanup in try/finally blocks
 */

require_once __DIR__ . '/OcrLogger.php';

class LocalTesseractEngine {

    private string $tesseractBinary;
    private string $tempDir;
    private int $timeout;

    public function __construct(string $tesseractBinary, string $tempDir, int $timeout = 30) {
        $this->tesseractBinary = $tesseractBinary;
        $this->tempDir = rtrim($tempDir, '/\\');
        $this->timeout = $timeout;

        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0750, true);
        }
    }

    /**
     * Process an image file and return extracted text.
     *
     * @param string $sourcePath Path to image file.
     * @param int    $psm        Page segmentation mode.
     * @param int    $oem        OCR engine mode.
     * @param string $lang       Language code.
     * @return array{
     *   success: bool,
     *   text: string,
     *   error?: string,
     *   processing_time_ms: float
     * }
     */
    public function processImage(string $sourcePath, int $psm = 3, int $oem = 1, string $lang = 'eng'): array {
        if (!is_file($this->tesseractBinary)) {
            return [
                'success' => false,
                'text' => '',
                'error' => 'Tesseract executable not found at configured path.',
                'processing_time_ms' => 0.0
            ];
        }

        $startTime = microtime(true);

        // Create secure temporary working copy
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'tmp';
        $randomName = 'ocr_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $isolatedPath = $this->tempDir . DIRECTORY_SEPARATOR . $randomName;

        if (!@copy($sourcePath, $isolatedPath)) {
            return [
                'success' => false,
                'text' => '',
                'error' => 'Could not initialize isolated OCR temporary file.',
                'processing_time_ms' => 0.0
            ];
        }

        try {
            // Pass 1: Run with requested PSM
            $result = $this->executeTesseractCommand($isolatedPath, $psm, $oem, $lang);

            // Pass 2: If PSM 3 returned sparse text (< 25 chars), evaluate PSM 6
            if ($psm === 3 && strlen(trim($result['text'])) < 25) {
                OcrLogger::info("Local OCR PSM 3 yielded sparse text. Retrying with PSM 6...");
                $altResult = $this->executeTesseractCommand($isolatedPath, 6, $oem, $lang);
                if (strlen(trim($altResult['text'])) > strlen(trim($result['text']))) {
                    $result = $altResult;
                }
            }

            $durationMs = round((microtime(true) - $startTime) * 1000, 1);
            $result['processing_time_ms'] = $durationMs;

            return $result;

        } finally {
            // Guaranteed cleanup of temporary working file
            if (is_file($isolatedPath)) {
                @unlink($isolatedPath);
            }
        }
    }

    /**
     * Safely execute Tesseract CLI with strict process timeout.
     */
    private function executeTesseractCommand(string $imagePath, int $psm, int $oem, string $lang): array {
        // Strict input sanitization
        $safeBinary = escapeshellarg($this->tesseractBinary);
        $safeImage  = escapeshellarg($imagePath);
        $safeLang   = escapeshellarg(preg_replace('/[^a-zA-Z0-9_+]/', '', $lang) ?: 'eng');
        $safePsm    = (int)$psm;
        $safeOem    = (int)$oem;

        // Command: tesseract input.jpg stdout -l eng --oem 1 --psm 3
        $command = "{$safeBinary} {$safeImage} stdout -l {$safeLang} --oem {$safeOem} --psm {$safePsm}";

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Ensure TESSDATA_PREFIX is set if sibling tessdata directory exists
        $env = null;
        $tessdata = dirname($this->tesseractBinary) . DIRECTORY_SEPARATOR . 'tessdata';
        if (is_dir($tessdata)) {
            $env = $_ENV;
            $env['TESSDATA_PREFIX'] = $tessdata;
        }

        $process = proc_open($command, $descriptorSpec, $pipes, null, $env);

        if (!is_resource($process)) {
            OcrLogger::error("Failed to spawn Tesseract process.");
            return [
                'success' => false,
                'text' => '',
                'error' => 'Failed to initialize local OCR sub-process.'
            ];
        }

        // Close stdin
        fclose($pipes[0]);

        // Non-blocking timeout monitoring loop
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();
        $timedOut = false;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                if (in_array($pipes[1], $read, true)) {
                    $chunk = fread($pipes[1], 8192);
                    if ($chunk !== false) $stdout .= $chunk;
                }
                if (in_array($pipes[2], $read, true)) {
                    $chunk = fread($pipes[2], 8192);
                    if ($chunk !== false) $stderr .= $chunk;
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if ((time() - $startTime) > $this->timeout) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
        }

        // Read remaining buffers
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            OcrLogger::error("Local Tesseract process timed out after {$this->timeout} seconds.");
            return [
                'success' => false,
                'text' => '',
                'error' => "OCR process timed out after {$this->timeout} seconds."
            ];
        }

        if ($exitCode !== 0 && empty(trim($stdout))) {
            OcrLogger::warning("Tesseract exited with code {$exitCode}: " . trim($stderr));
            return [
                'success' => false,
                'text' => '',
                'error' => 'OCR recognition failed. The image may be corrupt or unreadable.'
            ];
        }

        return [
            'success' => true,
            'text' => trim($stdout)
        ];
    }
}
