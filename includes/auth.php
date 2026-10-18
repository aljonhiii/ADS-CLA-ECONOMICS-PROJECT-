<?php
/**
 * Authentication Helper
 * 
 * Manages PHP sessions, login/logout, and user retrieval.
 * Uses password_hash/password_verify for secure credential handling.
 * Regenerates session IDs on login to prevent session fixation.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Start a secure session and global output buffering if not already active.
 */
function start_secure_session(): void {
    if (!ob_get_level()) {
        ob_start();
    }
    if (session_status() === PHP_SESSION_NONE) {
        $is_secure = env('APP_SECURE_COOKIES', 'false') === 'true'
                     || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => 0,           // Session cookie (expires on browser close)
            'path'     => '/',
            'domain'   => '',
            'secure'   => $is_secure,  // Auto-detected or set via APP_SECURE_COOKIES
            'httponly'  => true,        // Prevent JavaScript access
            'samesite' => 'Lax',       // CSRF mitigation
        ]);
        session_start();
    }
}

// Auto-start output buffering and secure session when this file is included
if (!ob_get_level()) {
    ob_start();
}
start_secure_session();

/**
 * Check if a user is currently logged in.
 *
 * @return bool True if logged in.
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get the current logged-in user data from session.
 *
 * @return array|null User data array or null if not logged in.
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'user_id'  => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['role'],
        'name'     => $_SESSION['display_name'] ?? $_SESSION['username'],
    ];
}

/**
 * Attempt to log in with the given credentials.
 *
 * @param string $username The username.
 * @param string $password The plaintext password.
 * @return array ['success' => bool, 'message' => string, 'role' => string|null]
 */
function login(string $username, string $password): array {
    $pdo = db_connect();

    // Fetch user by username
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND account_status = ?');
    $stmt->execute([$username, 'active']);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password.', 'role' => null];
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid username or password.', 'role' => null];
    }

    // Block student logins — only Faculty and Admin may access the system
    if ($user['role'] === 'student') {
        return [
            'success' => false,
            'message' => 'Access restricted: Only Faculty and Admin accounts can sign in.',
            'role'    => null
        ];
    }

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Store user info in session
    $_SESSION['user_id']  = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];

    // Fetch the display name based on role
    $display_name = $user['username'];
    if ($user['role'] === 'instructor') {
        $stmt2 = $pdo->prepare('SELECT first_name, last_name FROM instructors WHERE user_id = ?');
        $stmt2->execute([$user['user_id']]);
        $profile = $stmt2->fetch();
        if ($profile) {
            $display_name = $profile['first_name'] . ' ' . $profile['last_name'];
        }
    } elseif ($user['role'] === 'dean') {
        $display_name = 'Admin';
    }
    $_SESSION['display_name'] = $display_name;

    return ['success' => true, 'message' => 'Login successful.', 'role' => $user['role']];
}

/**
 * Helper to get clean human-readable role label.
 */
function role_label(string $role): string {
    return match($role) {
        'dean'       => 'Admin',
        'instructor' => 'Faculty',
        default      => ucfirst($role)
    };
}

/**
 * Log the current user out and destroy the session.
 */
function logout(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Get the dashboard URL for a given role.
 *
 * @param string $role The user role.
 * @return string The dashboard path.
 */
function get_dashboard_url(string $role): string {
    switch ($role) {
        case 'dean':       return APP_BASE . '/dean/dashboard.php';
        case 'instructor': return APP_BASE . '/instructor/dashboard.php';
        default:           return APP_BASE . '/auth/login.php';
    }
}

/**
 * Redirect unauthenticated users to the login page.
 */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . APP_BASE . '/auth/login.php');
        exit;
    }
}
