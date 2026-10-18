<?php
/**
 * Database Connection Configuration
 * 
 * Provides a singleton PDO connection to the MySQL database.
 * Uses PDO with prepared statements for secure database access.
 * Credentials are loaded from environment variables (.env file).
 */

require_once __DIR__ . '/env.php';

// ─── Database credentials from environment ────────────────
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_PORT',    env('DB_PORT', '3306'));
define('DB_NAME',    require_env('DB_NAME'));
define('DB_USER',    require_env('DB_USER'));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ─── Application configuration from environment ──────────
define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');
define('APP_BASE',  rtrim(env('APP_BASE_PATH', '/ads_client'), '/'));

/**
 * Get or create the PDO database connection (singleton pattern).
 *
 * @return PDO The database connection instance.
 */
function db_connect(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Use real prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the real error; show a generic message to the user
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            $debug_info = (defined('APP_DEBUG') && APP_DEBUG)
                ? '<div class="alert alert-danger mt-3 small text-start"><code>' . htmlspecialchars($e->getMessage()) . '</code></div>'
                : '';
            die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Database Connection Error</title>'
              . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">'
              . '<link href="' . APP_BASE . '/assets/css/style.css" rel="stylesheet">'
              . '</head><body class="d-flex align-items-center justify-content-center min-vh-100" style="background-color: var(--bg-canvas);">'
              . '<div class="card p-5 text-center shadow-md border-0" style="max-width: 500px; border-radius: var(--radius-xl);">'
              . '<div class="brand-icon-emblem mx-auto mb-3" style="width: 54px; height: 54px; font-size: 1.5rem; background: var(--status-danger-text);"><i class="bi bi-database-exclamation"></i></div>'
              . '<h2 class="h4 fw-bold mb-2 text-danger">Database Connection Error</h2>'
              . '<p class="text-muted small mb-3">Could not connect to MySQL service. Please check your database credentials or service status.</p>'
              . $debug_info
              . '</div></body></html>');
        }
    }

    return $pdo;
}
