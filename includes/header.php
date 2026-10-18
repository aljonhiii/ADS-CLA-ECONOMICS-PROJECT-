<?php
/**
 * Header Template
 * 
 * Renders the HTML head, design system fonts & assets, modern top navigation bar,
 * and begins the layout wrapper.
 *
 * Expected variables:
 *   $page_title (string) — Title for the browser tab.
 */

require_once __DIR__ . '/permissions.php';

$user = current_user();
$role = $user['role'] ?? 'guest';
$display_name = $user['name'] ?? 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <title><?php echo e($page_title ?? 'Academic Management System'); ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Fonts — Plus Jakarta Sans & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Master Custom Design System Stylesheet -->
    <link href="<?php echo APP_BASE; ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Top Navigation Bar (Donezo Reference) -->
<nav class="navbar navbar-expand-lg bg-navbar fixed-top">
    <div class="container-fluid px-2 px-md-3">
        <!-- Sidebar Toggle (Mobile) -->
        <button class="btn btn-sm btn-subtle me-2 d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle navigation">
            <i class="bi bi-list fs-5"></i>
        </button>

        <!-- Brand Emblem & Logo -->
        <a class="navbar-brand me-4" href="<?php echo APP_BASE; ?>/">
            <span class="brand-icon-emblem">
                <i class="bi bi-mortarboard-fill"></i>
            </span>
            <span class="d-none d-sm-inline">Academic Portal</span>
            <span class="d-sm-none">AMS</span>
        </a>

        <!-- Right Side Controls -->
        <div class="d-flex align-items-center ms-auto gap-2">
            <?php
            $role_badge = match($role) {
                'dean'       => 'Admin',
                'instructor' => 'Faculty',
                default      => ucfirst($role)
            };
            ?>
            <!-- User Profile Dropdown Pill (Donezo Style) -->
            <div class="dropdown">
                <div class="user-profile-pill" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar-circle">
                        <?php echo strtoupper(substr($display_name, 0, 1)); ?>
                    </div>
                    <div class="d-none d-md-block text-start lh-1 me-1">
                        <div class="fw-bold text-dark small mb-1"><?php echo e($display_name); ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo e($role_badge); ?></div>
                    </div>
                    <i class="bi bi-chevron-down text-muted small ms-1"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end shadow-md border-0 mt-2 py-2" style="border-radius: var(--radius-md); min-width: 200px;">
                    <li class="px-3 py-2 border-bottom">
                        <div class="small fw-bold text-dark"><?php echo e($display_name); ?></div>
                        <div class="text-muted small">@<?php echo e($user['username'] ?? ''); ?> &bull; <?php echo e($role_badge); ?></div>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item py-2 text-danger d-flex align-items-center" href="<?php echo APP_BASE; ?>/auth/logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- Main Layout Wrapper -->
<div class="d-flex" id="wrapper">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Page Content Canvas -->
    <main class="flex-grow-1 main-content" id="mainContent">
        <div class="container-fluid p-0">
            <?php
            // Display flash messages
            $flash = get_flash();
            if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?> alert-dismissible fade show border-0 shadow-sm mb-4 rounded-3 d-flex align-items-center" role="alert">
                    <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                    <div><?php echo e($flash['message']); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
