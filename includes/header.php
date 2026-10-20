<?php
/**
 * Header Template
 *
 * Renders the HTML head, top bar and opens the page layout.
 * Plain CSS design system (assets/css/style.css).
 *
 * Expected variables:
 *   $page_title (string) — Title for the browser tab.
 */

require_once __DIR__ . '/permissions.php';

$user = current_user();
$role = $user['role'] ?? 'guest';
$display_name = $user['name'] ?? 'Guest';

// System identity (BSE-SPMS · WMSU CLA BS Economics)
$system_short = 'BSE-SPMS';
$system_full  = 'BS Economics Student Performance Monitoring System';
$system_sub   = 'BS Economics · College of Liberal Arts · WMSU';

$logo_file = __DIR__ . '/../assets/img/cla-economics-logo.png';
$has_logo  = is_file($logo_file);

$role_badge = match($role) {
    'dean'       => 'Dean',
    'instructor' => 'Instructor',
    default      => ucfirst($role)
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <title><?php echo e(($page_title ?? 'Dashboard') . ' · ' . $system_short); ?></title>

    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Fonts: Source Serif 4 (titles, numbers) + Inter (interface) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet">
    <!-- Plain CSS master stylesheet (tokens, base, layout, components, auth, utilities) -->
    <link href="<?php echo APP_BASE; ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Top Navigation Bar -->
<header class="topbar">
    <button class="icon-btn d-lg-none" type="button" id="sidebarToggle" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">
        <i class="bi bi-list fs-5"></i>
    </button>

    <a class="topbar-brand" href="<?php echo APP_BASE; ?>/" title="<?php echo e($system_full); ?>">
        <?php if ($has_logo): ?>
            <img class="brand-logo" src="<?php echo APP_BASE; ?>/assets/img/cla-economics-logo.png" alt="CLA Economics logo">
        <?php else: ?>
            <span class="brand-icon-emblem"><i class="bi bi-mortarboard-fill"></i></span>
        <?php endif; ?>
        <span class="brand-text">
            <span class="brand-name"><?php echo e($system_short); ?></span>
            <span class="brand-sub"><?php echo e($system_sub); ?></span>
        </span>
    </a>

    <div class="topbar-right ms-auto">
        <div class="dropdown">
            <button type="button" class="user-profile-pill" data-bs-toggle="dropdown" data-dropdown aria-haspopup="true" aria-expanded="false">
                <span class="user-avatar-circle"><?php echo e(strtoupper(substr($display_name, 0, 1))); ?></span>
                <span class="user-meta d-none d-md-block">
                    <span class="name d-block"><?php echo e($display_name); ?></span>
                    <span class="role d-block"><?php echo e($role_badge); ?></span>
                </span>
                <i class="bi bi-chevron-down text-muted small ms-1"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-md">
                <li class="dropdown-header px-3 py-2 border-bottom">
                    <div class="small fw-bold text-dark"><?php echo e($display_name); ?></div>
                    <div class="text-muted small">@<?php echo e($user['username'] ?? ''); ?> &bull; <?php echo e($role_badge); ?></div>
                </li>
                <li>
                    <a class="dropdown-item py-2 text-danger d-flex align-items-center" href="<?php echo APP_BASE; ?>/auth/logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>

<!-- Main Layout Wrapper -->
<div id="wrapper">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content" id="mainContent">
        <div class="container-fluid">
            <?php
            // Flash messages (success / danger / warning / info)
            $flash = get_flash();
            if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?> alert-dismissible" role="alert" data-auto-dismiss="true">
                    <i class="bi bi-info-circle-fill fs-5 me-2"></i>
                    <div><?php echo e($flash['message']); ?></div>
                    <button type="button" class="btn-close" data-dismiss="alert" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
