<?php
/**
 * OCR Configuration Management
 * 
 * Centralizes environment settings, default fallbacks, and executable discovery
 * for both local and microservice OCR execution.
 */

require_once __DIR__ . '/../config/env.php';

class OcrConfig {

    private string $engine;
    private string $serverUrl;
    private string $apiKey;
    private ?string $tesseractPath;
    private string $defaultLang;
    private int $defaultPsm;
    private int $defaultOem;
    private int $maxFileSize;
    private int $maxPages;
    private int $timeout;
    private bool $enableCache;
    private int $cacheTtl;
    private string $storagePath;

    public function __construct() {
        $this->engine        = strtolower(env('OCR_ENGINE', 'auto'));
        $baseUrl             = rtrim(env('OCR_SERVER_URL', 'http://127.0.0.1:8000/ocr'), '/');
        $this->serverUrl     = preg_match('#/ocr$#', $baseUrl) ? $baseUrl : ($baseUrl . '/ocr');
        $this->apiKey        = env('OCR_API_KEY', 'ads_ocr_secure_secret_key_2026');
        $this->tesseractPath = $this->resolveTesseractBinary();
        $this->defaultLang   = env('OCR_DEFAULT_LANG', 'eng');
        $this->defaultPsm    = (int)env('OCR_DEFAULT_PSM', 3);
        $this->defaultOem    = (int)env('OCR_DEFAULT_OEM', 1);
        $this->maxFileSize   = (int)env('OCR_MAX_FILE_SIZE_MB', 10) * 1024 * 1024;
        $this->maxPages      = (int)env('OCR_MAX_PAGES', 5);
        $this->timeout       = (int)env('OCR_TIMEOUT_SECONDS', 30);
        $this->enableCache   = filter_var(env('OCR_ENABLE_CACHE', 'true'), FILTER_VALIDATE_BOOLEAN);
        $this->cacheTtl      = (int)env('OCR_CACHE_TTL', 86400); // 24 hours
        $this->storagePath   = env('STORAGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage');
    }

    /**
     * Locate the Tesseract executable path without hardcoding.
     */
    private function resolveTesseractBinary(): ?string {
        $envPath = env('TESSERACT_PATH', '');
        if (!empty($envPath) && is_file($envPath) && is_executable($envPath)) {
            return $envPath;
        }

        $commonPaths = [
            'D:\\tesseract\\tesseract.exe',
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            'D:\\Tesseract-OCR\\tesseract.exe',
            'D:\\xampp\\Tesseract-OCR\\tesseract.exe',
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract'
        ];

        foreach ($commonPaths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function getEngine(): string { return $this->engine; }
    public function getServerUrl(): string { return $this->serverUrl; }
    public function getApiKey(): string { return $this->apiKey; }
    public function getTesseractPath(): ?string { return $this->tesseractPath; }
    public function hasTesseractBinary(): bool { return $this->tesseractPath !== null; }
    public function getDefaultLang(): string { return $this->defaultLang; }
    public function getDefaultPsm(): int { return $this->defaultPsm; }
    public function getDefaultOem(): int { return $this->defaultOem; }
    public function getMaxFileSize(): int { return $this->maxFileSize; }
    public function getMaxPages(): int { return $this->maxPages; }
    public function getTimeout(): int { return $this->timeout; }
    public function isCacheEnabled(): bool { return $this->enableCache; }
    public function getCacheTtl(): int { return $this->cacheTtl; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getTempDir(): string { return $this->storagePath . DIRECTORY_SEPARATOR . 'tmp'; }
    public function getCacheDir(): string { return $this->storagePath . DIRECTORY_SEPARATOR . 'ocr_cache'; }
    public function getLogsDir(): string { return $this->storagePath . DIRECTORY_SEPARATOR . 'logs'; }
}
