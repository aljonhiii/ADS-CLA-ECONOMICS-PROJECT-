<?php
/**
 * OCR Result Caching & Duplicate Reuse Engine
 * 
 * Caches OCR responses by file content SHA-256 hash to eliminate redundant
 * network transmission and heavy OCR re-processing on re-uploads.
 */

class OcrCache {

    private string $cacheDir;
    private int $ttl;
    private bool $enabled;

    public function __construct(string $cacheDir, int $ttl = 86400, bool $enabled = true) {
        $this->cacheDir = rtrim($cacheDir, '/\\');
        $this->ttl = $ttl;
        $this->enabled = $enabled;

        if ($this->enabled && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }
    }

    /**
     * Compute a cache key based on file content and processing options.
     */
    public function makeKey(string $filePath, array $options = []): string {
        $hash = @hash_file('sha256', $filePath);
        if ($hash === false) {
            $hash = md5($filePath . filemtime($filePath));
        }
        $optStr = md5(json_encode($options));
        return $hash . '_' . $optStr;
    }

    /**
     * Retrieve cached OCR extraction if present and unexpired.
     */
    public function get(string $key): ?array {
        if (!$this->enabled) return null;

        $cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.json';
        if (!is_file($cacheFile)) return null;

        $mtime = filemtime($cacheFile);
        if ((time() - $mtime) > $this->ttl) {
            @unlink($cacheFile);
            return null;
        }

        $raw = @file_get_contents($cacheFile);
        if ($raw === false) return null;

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Store an OCR result in cache with atomic write.
     */
    public function set(string $key, array $data): bool {
        if (!$this->enabled) return false;

        $cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.json';
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload === false) return false;

        $tmpFile = tempnam($this->cacheDir, 'tmp_cache_');
        if ($tmpFile === false) return false;

        if (file_put_contents($tmpFile, $payload, LOCK_EX) !== false) {
            return @rename($tmpFile, $cacheFile);
        }

        @unlink($tmpFile);
        return false;
    }
}
