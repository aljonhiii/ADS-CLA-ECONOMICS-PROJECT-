<?php
/**
 * Logout Handler
 * 
 * Destroys the current session and redirects to the login page.
 */

require_once __DIR__ . '/../includes/auth.php';

logout();
header('Location: ' . APP_BASE . '/auth/login.php');
exit;
