<?php
/**
 * Login Page — Split-Screen Experience
 * 
 * Inspired by Kezak authentication design:
 * Left: Crisp, clean sign-in interface with role-segmented controls.
 * Right: Deep forest-green hero showcase with floating metric preview cards.
 */

require_once __DIR__ . '/../includes/auth.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    $user = current_user();
    header('Location: ' . get_dashboard_url($user['role']));
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $result = login($username, $password);
        if ($result['success']) {
            header('Location: ' . get_dashboard_url($result['role']));
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Faculty & Admin Academic Portal</title>
    <meta name="description" content="Sign in to the Faculty & Admin Academic Class, Attendance, and Grade Management System.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/style.css?v=compact2" rel="stylesheet">
</head>
<body style="background-color: var(--bg-canvas, #f6f8fa);">

<div class="auth-split-wrapper">
    <!-- Compact Split-Screen Card (Centered Floating Split-Card Layout) -->
    <div class="auth-compact-card">
        <!-- ═══ LEFT: Authentication Form ═══ -->
        <div class="auth-form-column">
            <!-- Brand Header -->
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="brand-icon-emblem">
                    <i class="bi bi-mortarboard-fill"></i>
                </span>
                <span class="fw-bold fs-5 text-dark" style="font-family: var(--font-heading);">Academic Portal</span>
            </div>

            <div class="auth-form-container">
                <p class="text-muted small mb-3">Sign in with your institutional credentials to access class records, grading, and oversight tools.</p>


                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center py-2 px-3 mb-3 rounded-3 border-0 shadow-xs" role="alert">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-6"></i>
                        <span class="small"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="" autocomplete="on">
                    <div class="mb-3">
                        <label for="username" class="form-label small fw-semibold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted">
                                <i class="bi bi-person"></i>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0" id="username" name="username"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   placeholder="Enter your username" required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label small fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted">
                                <i class="bi bi-lock"></i>
                            </span>
                            <input type="password" class="form-control border-start-0 ps-0" id="password" name="password"
                                   placeholder="Enter your password" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-forest w-100 py-2 mb-2">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                    </button>
                </form>

                <?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
                <!-- Dev Quick Test Accounts Pill Box -->
                <div class="mt-2 p-2 px-3 rounded-3" style="background-color: var(--bg-canvas); border: 1px solid var(--border-color);">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small text-muted fw-bold" style="font-size: 0.72rem;"><i class="bi bi-code-slash me-1"></i> Quick Test Credentials</span>
                        <span class="badge bg-white text-muted border" style="font-size: 0.65rem;">Debug Mode</span>
                    </div>
                    <div class="small text-muted d-flex flex-column gap-1" style="font-size: 0.72rem;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><code>dean</code> / <code>password123</code></span>
                            <span class="badge bg-primary" style="font-size: 0.65rem;">Admin</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span><code>prof_smith</code> / <code>password123</code></span>
                            <span class="badge bg-secondary" style="font-size: 0.65rem;">Faculty</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer Notice -->
            <div class="pt-3 border-top mt-3 text-muted" style="font-size: 0.72rem;">
                Copyright &copy; Academic Management System &middot; All Rights Reserved
            </div>
        </div>

        <!-- ═══ RIGHT: Branded Hero Showcase ═══ -->
        <div class="auth-hero-column d-none d-lg-flex">
            <!-- Floating Metric Preview Cards -->
            <div class="hero-floating-cards">
                <!-- Card 1: Attendance Metric -->
                <div class="hero-glass-card hero-card-1">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.7rem;">Attendance Rate</span>
                        <span class="trend-pill up">94.8%</span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <!-- SVG Ring Chart (Front-End Guidance) -->
                        <svg width="48" height="48" viewBox="0 0 36 36">
                            <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                  fill="none" stroke="#e2e8f0" stroke-width="3.5" />
                            <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                  fill="none" stroke="#10b981" stroke-width="3.5" stroke-dasharray="94.8, 100" stroke-linecap="round" />
                        </svg>
                        <div>
                            <div class="h5 fw-bold mb-0 text-dark">54 / 57</div>
                            <small class="text-muted" style="font-size: 0.72rem;">Sessions Logged</small>
                        </div>
                    </div>
                    <div class="mt-2 pt-2 border-top d-flex justify-content-between small text-muted" style="font-size: 0.7rem;">
                        <span>Present: 95%</span>
                        <span>Late: 3%</span>
                    </div>
                </div>

                <!-- Card 2: Academic Grade Summary -->
                <div class="hero-glass-card hero-card-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.7rem;">Final Grades</span>
                        <span class="trend-pill up">↑ 100%</span>
                    </div>
                    <div class="h4 fw-bold mb-1 text-dark">89.4 <span class="fs-6 text-muted fw-normal">Avg</span></div>
                    <div class="progress" style="height: 5px; background: #e2e8f0; border-radius: 4px;">
                        <div class="progress-bar bg-success" style="width: 88.5%; border-radius: 4px;"></div>
                    </div>
                    <div class="mt-2 pt-1 border-top d-flex justify-content-between small text-muted" style="font-size: 0.7rem;">
                        <span>Status: <strong class="text-success">Passed</strong></span>
                        <span>Sem: 2nd</span>
                    </div>
                </div>
            </div>

            <!-- Branded Hero Pitch -->
            <div class="mt-auto z-1">
                <div class="hero-badge-emblem">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <h2 class="h3 fw-bold text-white mb-2">
                    A Unified Hub for Smarter Academic Management
                </h2>
                <p class="text-white-50 small mb-3" style="max-width: 440px; line-height: 1.5;">
                    Empowers deans and faculty with transparent grade computation, real-time attendance monitoring, and centralized class management.
                </p>

                <!-- Carousel Indicator Bars -->
                <div class="carousel-bars" style="max-width: 180px;">
                    <div class="carousel-bar active"></div>
                    <div class="carousel-bar"></div>
                    <div class="carousel-bar"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
