<?php
/**
 * Root Entry Point
 * 
 * Routes authenticated users to their role-specific dashboard.
 * Redirects unauthenticated users to the login page.
 */

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    $user = current_user();
    if ($user['role'] === 'student') {
        logout();
        header('Location: ' . APP_BASE . '/auth/login.php');
        exit;
    }
    header('Location: ' . get_dashboard_url($user['role']));
    exit;
}

// Not logged in — redirect to login
header('Location: ' . APP_BASE . '/auth/login.php');
exit;
