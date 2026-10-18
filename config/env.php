<?php
/**
 * Environment Variable Loader
 * 
 * Reads configuration from a .env file in the project root.
 * Falls back to actual environment variables if .env is missing.
 * Validates that required variables are present.
 */

/**
 * Parse and load variables from a .env file into $_ENV and putenv().
 * Existing environment variables are NOT overwritten.
 *
 * @param string $path Absolute path to the .env file.
 */
function load_env(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return; // Allow running without .env if real env vars are set
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        // Skip empty lines and comments
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Remove surrounding quotes (single or double)
        if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
            $value = $m[2];
        }

        // Only set if not already defined in the environment
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Retrieve an environment variable with an optional default.
 *
 * @param string      $key     Variable name.
 * @param string|null $default Fallback value if the variable is not set.
 * @return string|null
 */
function env(string $key, ?string $default = null): ?string {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    return ($val !== false) ? $val : $default;
}

/**
 * Retrieve a required environment variable.
 * Terminates with a safe, generic error if the variable is missing.
 *
 * @param string $key Variable name.
 * @return string
 */
function require_env(string $key): string {
    $value = env($key);
    if ($value === null || $value === '') {
        error_log("FATAL: Required environment variable '$key' is not set.");
        die('Application configuration error. Please check server logs.');
    }
    return $value;
}

// ─── Auto-load .env from project root ─────────────────────
load_env(dirname(__DIR__) . '/.env');
