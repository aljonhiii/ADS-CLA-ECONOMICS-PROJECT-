<?php
/**
 * OCR Client & Document Ingestion Service
 * 
 * Validates document uploads and manages extraction.
 * - Extracts selectable text directly from digital PDFs using pure PHP.
 * - Dispatches scanned PDFs and images securely to the remote Linux OCR server.
 * - Fully compatible with InfinityFree shared hosting (uses standard cURL).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/PdfTextExtractor.php';

class OcrClient {

    private string $serverUrl;
    private string $apiKey;
    private int $maxFileSize;
    private int $maxPages;
    private int $timeout;

    public function __construct() {
        $baseUrl           = rtrim(env('OCR_SERVER_URL', 'http://localhost:8000/ocr'), '/');
        $this->serverUrl   = preg_match('#/ocr$#', $baseUrl) ? $baseUrl : ($baseUrl . '/ocr');
        $this->apiKey      = env('OCR_API_KEY', 'ads_ocr_secure_secret_key_2026');
        $this->maxFileSize = (int)env('OCR_MAX_FILE_SIZE_MB', 5) * 1024 * 1024;
        $this->maxPages    = (int)env('OCR_MAX_PAGES', 5);
        $this->timeout     = (int)env('OCR_TIMEOUT_SECONDS', 30);
    }

    /**
     * Process an uploaded file ($_FILES item).
     *
     * @param array $fileItem Standard $_FILES['file'] structure.
     * @return array{
     *   success: bool,
     *   source: 'pdf_text'|'ocr_service'|'fallback',
     *   text: string,
     *   page_count: int,
     *   filename: string,
     *   message?: string,
     *   error?: string
     * }
     */
    public function processUpload(array $fileItem): array {
        // 1. Basic PHP upload error check
        if (!isset($fileItem['error']) || is_array($fileItem['error'])) {
            return ['success' => false, 'error' => 'Invalid upload parameter received.'];
        }

        switch ($fileItem['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'error' => 'File exceeds maximum upload size limit.'];
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'error' => 'No file was uploaded. Please choose an image or PDF.'];
            default:
                return ['success' => false, 'error' => 'Upload failed with code: ' . $fileItem['error']];
        }

        $tmpPath = $fileItem['tmp_name'];
        $originalName = basename($fileItem['name']);

        // 2. File size validation
        if ($fileItem['size'] > $this->maxFileSize) {
            $mb = round($this->maxFileSize / (1024 * 1024));
            return ['success' => false, 'error' => "File size exceeds the allowed limit of {$mb}MB."];
        }

        // 3. MIME and Extension validation
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($ext, $allowedExts, true)) {
            return ['success' => false, 'error' => 'Unsupported file format. Please upload JPG, PNG, or PDF.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf', 'application/x-pdf'];
        if (!in_array($mime, $allowedMimes, true)) {
            return ['success' => false, 'error' => "Invalid file MIME type detected ($mime). Please upload a valid document or image."];
        }

        // 4. Processing flow based on type
        if ($ext === 'pdf') {
            return $this->processPdfDocument($tmpPath, $originalName, $mime);
        } else {
            return $this->processImageDocument($tmpPath, $originalName, $mime);
        }
    }

    /**
     * Process PDF: Try pure PHP text extraction first; fallback to OCR service if scanned.
     */
    private function processPdfDocument(string $filePath, string $originalName, string $mime): array {
        // A. Validate page count
        $content = @file_get_contents($filePath);
        $pageCount = PdfTextExtractor::detectPageCount($content ?: '');

        if ($pageCount > $this->maxPages) {
            return [
                'success' => false,
                'error' => "PDF contains {$pageCount} pages. Maximum allowed is {$this->maxPages} pages per batch."
            ];
        }

        // B. Attempt pure PHP selectable text extraction
        $pdfResult = PdfTextExtractor::extract($filePath);
        if ($pdfResult['success'] && $pdfResult['has_selectable_text']) {
            return [
                'success' => true,
                'source' => 'pdf_text',
                'text' => $pdfResult['text'],
                'page_count' => $pageCount,
                'filename' => $originalName,
                'message' => 'Digital PDF text extracted directly.'
            ];
        }

        // C. Scanned PDF: Dispatch to remote Linux OCR Server
        return $this->dispatchToRemoteOcrServer($filePath, $originalName, $mime, $pageCount);
    }

    /**
     * Process Image: Dispatch directly to remote Linux OCR Server.
     */
    private function processImageDocument(string $filePath, string $originalName, string $mime): array {
        return $this->dispatchToRemoteOcrServer($filePath, $originalName, $mime, 1);
    }

    /**
     * Send file securely to the external OCR Microservice via cURL.
     */
    private function dispatchToRemoteOcrServer(string $filePath, string $originalName, string $mime, int $pageCount): array {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL extension is missing in this PHP environment. Cannot contact OCR service.'
            ];
        }

        // Check if OCR Server URL is configured
        if (empty($this->serverUrl)) {
            return [
                'success' => false,
                'error' => 'OCR Server URL is not configured. Please set OCR_SERVER_URL in .env.'
            ];
        }

        // Prepare multipart cURL payload
        $cfile = new CURLFile($filePath, $mime, $originalName);
        $postData = [
            'file' => $cfile,
            'pages' => $pageCount
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->serverUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'X-OCR-API-KEY: ' . $this->apiKey,
                'Accept: application/json'
            ]
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            return [
                'success' => false,
                'error' => "Could not reach OCR microservice ({$curlError}). Please ensure the external OCR server is running and accessible."
            ];
        }

        $json = json_decode($rawResponse, true);

        if ($httpCode === 401 || $httpCode === 403) {
            return [
                'success' => false,
                'error' => 'OCR service authentication failed (Invalid API key).'
            ];
        }

        if ($httpCode >= 400 || empty($json) || empty($json['success'])) {
            $err = $json['detail'] ?? $json['error'] ?? $json['message'] ?? "OCR service returned HTTP code $httpCode.";
            return [
                'success' => false,
                'error' => 'OCR extraction failed: ' . $err
            ];
        }

        return [
            'success' => true,
            'source' => 'ocr_service',
            'text' => $json['text'] ?? '',
            'page_count' => $json['pages'] ?? $pageCount,
            'filename' => $originalName,
            'message' => 'Optical Character Recognition completed successfully.'
        ];
    }
}
