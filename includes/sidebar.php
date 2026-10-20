<?php
/**
 * Sidebar Navigation
 * 
 * Renders role-based navigation links organized by section categories.
 * Highlights the active page with deep forest green pill active indicator.
 */

$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));
$user = current_user();
$role = $user['role'] ?? '';

/**
 * Helper to determine if a nav link is active.
 */
function is_active(string $page, string $dir = ''): string {
    global $current_page, $current_dir;
    if ($dir && $current_dir !== $dir) return '';
    return ($current_page === $page) ? 'active' : '';
}
?>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar (Donezo Reference) -->
<nav class="sidebar" id="sidebar">
    <div class="nav flex-column gap-1">
        <?php if ($role === 'dean'): ?>
            <!-- ═══ ADMIN NAV (DEAN) ═══ -->
            <div class="sidebar-section-title">Admin Menu</div>
            <a class="nav-link <?php echo is_active('dashboard.php', 'dean'); ?>" href="<?php echo APP_BASE; ?>/dean/dashboard.php">
                <i class="bi bi-grid-fill"></i><span>Dashboard</span>
            </a>

            <div class="sidebar-section-title">Institutional Management</div>
            <a class="nav-link <?php echo is_active('students.php', 'dean'); ?>" href="<?php echo APP_BASE; ?>/dean/students.php">
                <i class="bi bi-people"></i><span>Students</span>
            </a>
            <a class="nav-link <?php echo is_active('subjects.php', 'dean'); ?>" href="<?php echo APP_BASE; ?>/dean/subjects.php">
                <i class="bi bi-book"></i><span>Subjects</span>
            </a>
            <a class="nav-link <?php echo is_active('classes.php', 'dean'); ?>" href="<?php echo APP_BASE; ?>/dean/classes.php">
                <i class="bi bi-building"></i><span>Classes</span>
            </a>
            <a class="nav-link <?php echo is_active('instructors.php', 'dean'); ?>" href="<?php echo APP_BASE; ?>/dean/instructors.php">
                <i class="bi bi-person-badge"></i><span>Faculty</span>
            </a>

            <div class="sidebar-section-title">Oversight & Reports</div>
            <a class="nav-link <?php echo is_active('attendance.php', 'dean'); ?>" href="<?php echo APP_BASE; ?>/dean/attendance.php">
                <i class="bi bi-calendar-check"></i><span>Attendance Logs</span>
            </a>
            <a class="nav-link <?php echo is_active('grades.php', 'dean'); ?>" href="<?php echo APP_BASE; ?>/dean/grades.php">
                <i class="bi bi-award"></i><span>Grade Distributions</span>
            </a>

        <?php elseif ($role === 'instructor'): ?>
            <!-- ═══ FACULTY NAV (INSTRUCTOR) ═══ -->
            <div class="sidebar-section-title">Faculty Menu</div>
            <a class="nav-link <?php echo is_active('dashboard.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/dashboard.php">
                <i class="bi bi-grid-fill"></i><span>Dashboard</span>
            </a>

            <div class="sidebar-section-title">Class Management</div>
            <a class="nav-link <?php echo is_active('classes.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/classes.php">
                <i class="bi bi-building"></i><span>My Classes</span>
            </a>
            <a class="nav-link <?php echo is_active('students.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/students.php">
                <i class="bi bi-person-lines-fill"></i><span>Rosters & OCR</span>
            </a>
            <a class="nav-link <?php echo is_active('sessions.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/sessions.php">
                <i class="bi bi-calendar-event"></i><span>Class Sessions</span>
            </a>
            <a class="nav-link <?php echo is_active('attendance.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/attendance.php">
                <i class="bi bi-calendar-check"></i><span>Attendance Record</span>
            </a>

            <div class="sidebar-section-title">Grades & Evaluation</div>
            <a class="nav-link <?php echo is_active('grading-components.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/grading-components.php">
                <i class="bi bi-sliders"></i><span>Grading Weights</span>
            </a>
            <a class="nav-link <?php echo is_active('grades.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/grades.php">
                <i class="bi bi-pencil-square"></i><span>Enter Scores</span>
            </a>
            <a class="nav-link <?php echo is_active('finalize-grades.php', 'instructor'); ?>" href="<?php echo APP_BASE; ?>/instructor/finalize-grades.php">
                <i class="bi bi-check-circle"></i><span>Finalize & Lock</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- Donezo-Style Bottom Status Card -->
    <div class="sidebar-promo-card mt-auto">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-white text-dark rounded-pill fw-bold" style="font-size: 0.65rem;">ACTIVE TERM</span>
            <small class="text-white-50" style="font-size: 0.7rem;"><?php echo date('M Y'); ?></small>
        </div>
        <div class="fw-bold small text-white mb-1">Academic Portal v2.0</div>
        <div class="text-white-50" style="font-size: 0.72rem; line-height: 1.3;">
            Real-time enrollment & grading records online.
        </div>
    </div>
</nav>
