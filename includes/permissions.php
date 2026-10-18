<?php
/**
 * Permissions & Security Helper
 * 
 * Role-based authorization guards, ownership verification,
 * CSRF token management, and XSS output escaping.
 */

require_once __DIR__ . '/auth.php';

/**
 * Require the current user to have one of the specified roles.
 * Terminates with 403 if unauthorized.
 *
 * @param array $allowed_roles Array of allowed role strings (e.g., ['dean', 'instructor']).
 */
function require_role(array $allowed_roles): void {
    require_login();
    $user = current_user();
    if ($user['role'] === 'student') {
        logout();
        set_flash('danger', 'Access restricted: Only Faculty and Admin accounts can access the academic portal.');
        header('Location: ' . APP_BASE . '/auth/login.php');
        exit;
    }
    if (!in_array($user['role'], $allowed_roles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>403 — Access Denied</title>'
           . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">'
           . '<link href="' . APP_BASE . '/assets/css/style.css" rel="stylesheet">'
           . '</head><body class="d-flex align-items-center justify-content-center min-vh-100" style="background-color: var(--bg-canvas);">'
           . '<div class="card p-5 text-center shadow-md border-0" style="max-width: 440px; border-radius: var(--radius-xl);">'
           . '<div class="brand-icon-emblem mx-auto mb-3" style="width: 54px; height: 54px; font-size: 1.5rem;"><i class="bi bi-shield-lock-fill"></i></div>'
           . '<h1 class="h3 fw-bold mb-2">Access Denied</h1>'
           . '<p class="text-muted small mb-4">You do not have the required permissions to view this academic page.</p>'
           . '<a href="' . APP_BASE . '/" class="btn btn-forest py-2 px-4 mx-auto">Return to Dashboard</a>'
           . '</div></body></html>';
        exit;
    }
}

/**
 * Verify that the current instructor owns the given class.
 *
 * @param PDO $pdo Database connection.
 * @param int $class_id The class ID to verify.
 * @return bool True if the instructor owns this class.
 */
function instructor_owns_class(PDO $pdo, int $class_id): bool {
    $user = current_user();
    if ($user['role'] !== 'instructor') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT c.class_id FROM classes c
         JOIN instructors i ON c.instructor_id = i.instructor_id
         WHERE c.class_id = ? AND i.user_id = ?'
    );
    $stmt->execute([$class_id, $user['user_id']]);
    return (bool) $stmt->fetch();
}

/**
 * Verify that the current student owns the given student_class enrollment.
 *
 * @param PDO $pdo Database connection.
 * @param int $student_class_id The enrollment ID.
 * @return bool True if the student owns this enrollment.
 */
function student_owns_enrollment(PDO $pdo, int $student_class_id): bool {
    $user = current_user();
    if ($user['role'] !== 'student') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT sc.student_class_id FROM student_classes sc
         JOIN students s ON sc.student_id = s.student_id
         WHERE sc.student_class_id = ? AND s.user_id = ?'
    );
    $stmt->execute([$student_class_id, $user['user_id']]);
    return (bool) $stmt->fetch();
}

/**
 * Get the student record for the currently logged-in student.
 *
 * @param PDO $pdo Database connection.
 * @return array|null Student record or null.
 */
function get_current_student(PDO $pdo): ?array {
    $user = current_user();
    if (!$user || $user['role'] !== 'student') return null;
    $stmt = $pdo->prepare('SELECT * FROM students WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Get the instructor record for the currently logged-in instructor.
 *
 * @param PDO $pdo Database connection.
 * @return array|null Instructor record or null.
 */
function get_current_instructor(PDO $pdo): ?array {
    $user = current_user();
    if (!$user || $user['role'] !== 'instructor') return null;
    $stmt = $pdo->prepare('SELECT * FROM instructors WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    return $stmt->fetch() ?: null;
}

// ─── CSRF Protection ──────────────────────────────────────────

/**
 * Generate or retrieve the current CSRF token.
 *
 * @return string The CSRF token.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden CSRF input field for use in forms.
 *
 * @return string HTML hidden input element.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * Verify the CSRF token from a POST request.
 * Terminates with 403 on failure.
 */
function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('CSRF token validation failed. Please refresh the page and try again.');
    }
}

// ─── Output Escaping ──────────────────────────────────────────

/**
 * Escape a string for safe HTML output (prevents XSS).
 *
 * @param mixed $value The value to escape.
 * @return string The escaped string.
 */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ─── Flash Messages ──────────────────────────────────────────

/**
 * Set a flash message to display on the next page load.
 *
 * @param string $type    Message type ('success', 'danger', 'warning', 'info').
 * @param string $message The message text.
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Get and clear the flash message.
 *
 * @return array|null Flash message array or null.
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
