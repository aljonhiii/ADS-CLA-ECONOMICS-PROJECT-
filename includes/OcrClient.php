<?php
/**
 * OCR Client & Document Ingestion Service (Enhanced)
 * 
 * Validates document uploads, manages caching, and coordinates extraction:
 * 1. Pure PHP digital text extraction for searchable PDFs (0ms OCR overhead).
 * 2. Instant duplicate-upload reuse via SHA-256 content caching.
 * 3. High-accuracy remote microservice with OpenCV preprocessing and pypdfium2.
 * 4. Automatic, robust local Tesseract CLI fallback for offline/XAMPP environments.
 * 5. Strict security: real MIME verification, getimagesize checks, temp isolation,
 *    and error sanitization (no server paths or secrets leaked).
 */

require_once __DIR__ . '/OcrConfig.php';
require_once __DIR__ . '/OcrLogger.php';
require_once __DIR__ . '/OcrCache.php';
require_once __DIR__ . '/PdfTextExtractor.php';
require_once __DIR__ . '/LocalTesseractEngine.php';

class OcrClient {

    private OcrConfig $config;
    private OcrCache $cache;
    private ?LocalTesseractEngine $localEngine = null;

    public function __construct(?OcrConfig $config = null) {
        $this->config = $config ?? new OcrConfig();
        $this->cache  = new OcrCache(
            $this->config->getCacheDir(),
            $this->config->getCacheTtl(),
            $this->config->isCacheEnabled()
        );

        OcrLogger::init($this->config->getLogsDir());

        if ($this->config->hasTesseractBinary()) {
            $this->localEngine = new LocalTesseractEngine(
                $this->config->getTesseractPath(),
                $this->config->getTempDir(),
                $this->config->getTimeout()
            );
        }
    }

    /**
     * Process an uploaded file ($_FILES item) with options.
     *
     * @param array $fileItem Standard $_FILES['document'] structure.
     * @param array $options  Optional processing parameters (psm, oem, lang, profile).
     * @return array{
     *   success: bool,
     *   source: 'pdf_text'|'ocr_service'|'local_ocr'|'cache'|'fallback',
     *   text: string,
     *   page_count: int,
     *   filename: string,
     *   processing_time_ms?: float,
     *   message?: string,
     *   error?: string
     * }
     */
    public function processUpload(array $fileItem, array $options = []): array {
        $startTime = microtime(true);

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
                OcrLogger::warning("Upload error code: {$fileItem['error']}");
                return ['success' => false, 'error' => 'Document upload failed. Code: ' . (int)$fileItem['error']];
        }

        $tmpPath = $fileItem['tmp_name'];
        $originalName = basename($fileItem['name'] ?? 'document');

        // 2. File size validation
        $maxSize = $this->config->getMaxFileSize();
        if ($fileItem['size'] > $maxSize) {
            $mb = round($maxSize / (1024 * 1024));
            return ['success' => false, 'error' => "File size exceeds the allowed limit of {$mb}MB."];
        }

        // 3. Extension validation
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'bmp', 'tiff', 'xlsx', 'xls', 'csv'];
        if (!in_array($ext, $allowedExts, true)) {
            return ['success' => false, 'error' => 'Unsupported file format. Please upload Excel (.xlsx, .csv), PDF, or an image.'];
        }

        // Fast-path: Native Spreadsheet Extraction (Excel .xlsx, .xls, .csv)
        if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            require_once __DIR__ . '/SpreadsheetExtractor.php';
            $sheetResult = SpreadsheetExtractor::extract($tmpPath, $originalName);
            $sheetResult['processing_time_ms'] = round((microtime(true) - $startTime) * 1000, 1);
            return $sheetResult;
        }

        // 4. Real MIME validation via finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/webp', 'image/bmp', 'image/tiff',
            'application/pdf', 'application/x-pdf'
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            OcrLogger::warning("MIME validation failed for '{$originalName}'. Detected: {$mime}");
            return ['success' => false, 'error' => 'Invalid file content detected. Please upload a genuine image or PDF document.'];
        }

        // 5. Deep content validation (images must have valid dimensions; PDFs must have %PDF header)
        if (str_starts_with($mime, 'image/')) {
            $imgInfo = @getimagesize($tmpPath);
            if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
                OcrLogger::warning("Image sanity check failed for '{$originalName}'.");
                return ['success' => false, 'error' => 'The uploaded image file is corrupt or unreadable.'];
            }
        } elseif ($ext === 'pdf') {
            $header = @file_get_contents($tmpPath, false, null, 0, 8);
            if ($header === false || !str_starts_with($header, '%PDF-')) {
                OcrLogger::warning("PDF signature check failed for '{$originalName}'.");
                return ['success' => false, 'error' => 'The uploaded file is not a valid PDF document.'];
            }
        }

        // 6. Check Cache for instant reuse (duplicate upload)
        $cacheKey = $this->cache->makeKey($tmpPath, $options);
        $cachedResult = $this->cache->get($cacheKey);
        if ($cachedResult !== null) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 1);
            OcrLogger::info("Cache hit for file '{$originalName}' (Duration: {$durationMs}ms)");
            $cachedResult['source'] = 'cache';
            $cachedResult['filename'] = $originalName;
            $cachedResult['processing_time_ms'] = $durationMs;
            $cachedResult['message'] = 'Retrieved instantly from OCR cache.';
            return $cachedResult;
        }

        // 7. Route based on document type
        try {
            if ($ext === 'pdf') {
                $result = $this->processPdfDocument($tmpPath, $originalName, $mime, $options);
            } else {
                $result = $this->processImageDocument($tmpPath, $originalName, $mime, $options);
            }

            $durationMs = round((microtime(true) - $startTime) * 1000, 1);
            $result['processing_time_ms'] = $durationMs;

            // Store successful extraction in cache
            if ($result['success'] && !empty($result['text'])) {
                $this->cache->set($cacheKey, $result);
                OcrLogger::info("OCR processed successfully for '{$originalName}' [Engine: {$result['source']}] in {$durationMs}ms");
            }

            return $result;

        } catch (Throwable $e) {
            OcrLogger::error("Unhandled exception during OCR: " . $e->getMessage(), [
                'file' => $originalName,
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error' => 'An unexpected error occurred during OCR extraction. Please try again or re-save the document.'
            ];
        }
    }

    /**
     * Process PDF: Try pure PHP text extraction first; dispatch to service or local OCR if scanned.
     */
    private function processPdfDocument(string $filePath, string $originalName, string $mime, array $options): array {
        $content = @file_get_contents($filePath);
        $pageCount = PdfTextExtractor::detectPageCount($content ?: '');

        if ($pageCount > $this->config->getMaxPages()) {
            return [
                'success' => false,
                'error' => "PDF contains {$pageCount} pages. Maximum allowed is {$this->config->getMaxPages()} pages per batch."
            ];
        }

        // A. Attempt pure PHP selectable digital text extraction
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

        // B. Scanned PDF: Dispatch to engine
        return $this->dispatchExtraction($filePath, $originalName, $mime, $pageCount, $options, true);
    }

    /**
     * Process Image: Dispatch to configured engine.
     */
    private function processImageDocument(string $filePath, string $originalName, string $mime, array $options): array {
        return $this->dispatchExtraction($filePath, $originalName, $mime, 1, $options, false);
    }

    /**
     * Dispatch extraction to OCR Microservice or Local Tesseract Engine based on configuration.
     */
    private function dispatchExtraction(
        string $filePath,
        string $originalName,
        string $mime,
        int $pageCount,
        array $options,
        bool $isPdf
    ): array {
        $engine = $this->config->getEngine();

        // 1. Force Local Engine if configured
        if ($engine === 'local') {
            return $this->runLocalEngine($filePath, $originalName, $options, $isPdf);
        }

        // 2. Microservice Mode or Auto Mode
        if (function_exists('curl_init') && !empty($this->config->getServerUrl())) {
            $serviceResult = $this->dispatchToRemoteOcrServer($filePath, $originalName, $mime, $pageCount, $options);
            if ($serviceResult['success']) {
                return $serviceResult;
            }

            // In 'auto' mode, fall back to local Tesseract if microservice was unreachable
            if ($engine === 'auto' && $this->localEngine !== null) {
                OcrLogger::warning("OCR Microservice unreachable ({$serviceResult['error']}). Falling back to Local Tesseract Engine...");
                $localResult = $this->runLocalEngine($filePath, $originalName, $options, $isPdf);
                if ($localResult['success']) {
                    $localResult['message'] = ($localResult['message'] ?? '') . ' (Processed via local Tesseract fallback)';
                    return $localResult;
                }

                // If local fallback also failed, return a friendly, informative message
                $friendly = $localResult['error'] ?? '';
                return [
                    'success' => false,
                    'error' => $friendly ?: $serviceResult['error']
                ];
            }

            return $serviceResult;
        }

        // 3. Fallback to Local Engine if cURL is missing
        if ($this->localEngine !== null) {
            return $this->runLocalEngine($filePath, $originalName, $options, $isPdf);
        }

        return [
            'success' => false,
            'error' => 'No available OCR engine configured. Please ensure Tesseract is installed or the OCR service is running.'
        ];
    }

    /**
     * Run Local Tesseract CLI Engine.
     */
    private function runLocalEngine(string $filePath, string $originalName, array $options, bool $isPdf): array {
        if ($this->localEngine === null) {
            return [
                'success' => false,
                'error' => 'Local Tesseract binary is not installed or configured on this server.'
            ];
        }

        if ($isPdf) {
            // 1. Try digital text extraction first
            $pdfResult = PdfTextExtractor::extract($filePath);
            if (!empty(trim($pdfResult['text'])) && strlen(trim($pdfResult['text'])) > 30) {
                return [
                    'success' => true,
                    'source' => 'pdf_text',
                    'text' => $pdfResult['text'],
                    'page_count' => $pdfResult['page_count'],
                    'filename' => $originalName,
                    'message' => 'Scanned PDF text extracted with partial digital stream.'
                ];
            }

            // 2. Try extracting embedded JPEG image streams from the scanned PDF
            $extractedImages = PdfTextExtractor::extractEmbeddedJpegImages($filePath, $this->config->getTempDir());
            if (!empty($extractedImages)) {
                $psm  = (int)($options['psm'] ?? $this->config->getDefaultPsm());
                $oem  = (int)($options['oem'] ?? $this->config->getDefaultOem());
                $lang = (string)($options['lang'] ?? $this->config->getDefaultLang());

                $pageTexts = [];
                try {
                    foreach ($extractedImages as $imgFile) {
                        $pResult = $this->localEngine->processImage($imgFile, $psm, $oem, $lang);
                        if ($pResult['success'] && !empty(trim($pResult['text']))) {
                            $pageTexts[] = trim($pResult['text']);
                        }
                    }
                } finally {
                    foreach ($extractedImages as $imgFile) {
                        @unlink($imgFile);
                    }
                }

                if (!empty($pageTexts)) {
                    return [
                        'success' => true,
                        'source' => 'local_ocr',
                        'text' => implode("\n\n", $pageTexts),
                        'page_count' => count($pageTexts),
                        'filename' => $originalName,
                        'message' => 'Scanned PDF images extracted and processed via native local Tesseract engine.'
                    ];
                }
            }

            return [
                'success' => false,
                'error' => "Scanned PDF requires high-accuracy rasterization. Please launch 'start_ocr.bat' to start the OCR microservice on http://127.0.0.1:8000, or upload the roster as an image (JPG/PNG)."
            ];
        }

        $psm  = (int)($options['psm'] ?? $this->config->getDefaultPsm());
        $oem  = (int)($options['oem'] ?? $this->config->getDefaultOem());
        $lang = (string)($options['lang'] ?? $this->config->getDefaultLang());

        $execResult = $this->localEngine->processImage($filePath, $psm, $oem, $lang);

        if (!$execResult['success']) {
            return [
                'success' => false,
                'error' => OcrLogger::sanitizeUserError($execResult['error'] ?? 'Local OCR processing failed.')
            ];
        }

        return [
            'success' => true,
            'source' => 'local_ocr',
            'text' => $execResult['text'],
            'page_count' => 1,
            'filename' => $originalName,
            'message' => 'OCR completed via native local Tesseract engine.'
        ];
    }

    /**
     * Send file securely to the external OCR Microservice via cURL.
     */
    private function dispatchToRemoteOcrServer(
        string $filePath,
        string $originalName,
        string $mime,
        int $pageCount,
        array $options
    ): array {
        $cfile = new CURLFile($filePath, $mime, $originalName);
        $postData = [
            'file'    => $cfile,
            'pages'   => $pageCount,
            'psm'     => $options['psm'] ?? $this->config->getDefaultPsm(),
            'oem'     => $options['oem'] ?? $this->config->getDefaultOem(),
            'lang'    => $options['lang'] ?? $this->config->getDefaultLang(),
            'profile' => $options['profile'] ?? 'auto'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->config->getServerUrl(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config->getTimeout(),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'X-OCR-API-KEY: ' . $this->config->getApiKey(),
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
                'error' => "Could not reach OCR microservice ({$curlError})."
            ];
        }

        $json = json_decode($rawResponse, true);

        if ($httpCode === 401 || $httpCode === 403) {
            OcrLogger::error("OCR Microservice rejected API Key.");
            return [
                'success' => false,
                'error' => 'OCR service authentication failed. Please check OCR_API_KEY.'
            ];
        }

        if ($httpCode >= 400 || empty($json) || empty($json['success'])) {
            $err = $json['detail'] ?? $json['error'] ?? $json['message'] ?? "OCR service returned HTTP code $httpCode.";
            return [
                'success' => false,
                'error' => 'OCR extraction failed: ' . OcrLogger::sanitizeUserError($err)
            ];
        }

        return [
            'success' => true,
            'source' => 'ocr_service',
            'text' => $json['text'] ?? '',
            'page_count' => $json['pages'] ?? $pageCount,
            'filename' => $originalName,
            'metadata' => $json['metadata'] ?? [],
            'message' => 'OCR completed successfully with OpenCV enhancement.'
        ];
    }
}
