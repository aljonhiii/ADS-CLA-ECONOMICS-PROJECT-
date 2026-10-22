<?php
/**
 * OCR System Logger
 * 
 * Writes structured, sanitized events to storage/logs/ocr.log.
 * Protects server security by preventing server paths or secrets from leaking to users.
 */

class OcrLogger {

    private static ?string $logFile = null;

    public static function init(string $logsDir): void {
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0750, true);
        }
        self::$logFile = rtrim($logsDir, '/\\') . DIRECTORY_SEPARATOR . 'ocr.log';
    }

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            self::log('DEBUG', $message, $context);
        }
    }

    private static function log(string $level, string $message, array $context = []): void {
        if (self::$logFile === null) {
            $defaultDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            self::init($defaultDir);
        }

        $timestamp = date('Y-m-d H:i:s');
        $ctxStr = empty($context) ? '' : ' ' . json_encode(self::sanitizeContext($context), JSON_UNESCAPED_SLASHES);
        $entry = sprintf("[%s] [%s] %s%s%s", $timestamp, $level, $message, $ctxStr, PHP_EOL);

        @file_put_contents(self::$logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Remove sensitive secrets or credential tokens from context.
     */
    private static function sanitizeContext(array $context): array {
        $sanitized = [];
        $secretKeys = ['key', 'secret', 'password', 'token', 'authorization', 'api_key'];

        foreach ($context as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $secretKeys, true)) {
                $sanitized[$k] = '***REDACTED***';
            } elseif (is_array($v)) {
                $sanitized[$k] = self::sanitizeContext($v);
            } else {
                $sanitized[$k] = $v;
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize user-facing errors so system paths and internals are never exposed.
     */
    public static function sanitizeUserError(string $rawError): string {
        // Strip Windows and Unix absolute filesystem paths
        $clean = preg_replace('/[A-Za-z]:\\\\[^\s:"<>|\'"]+/', '[path]', $rawError);
        $clean = preg_replace('#/(?:var|tmp|home|usr|etc|opt)/[^\s:"<>|\'"]+#', '[path]', $clean);
        // Strip stack traces
        if (str_contains($clean, 'Stack trace:')) {
            $clean = explode('Stack trace:', $clean)[0];
        }
        return trim($clean);
    }
}
