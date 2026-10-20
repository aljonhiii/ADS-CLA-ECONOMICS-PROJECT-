<?php
/**
 * Dean — Student Directory
 * 
 * Inspect registered students across all curricula, year levels, and sections.
 * Clean Donezo light theme styling.
 */

$page_title = 'Student Directory';
require_once __DIR__ . '/../includes/header.php';
require_role(['dean']);

$pdo = db_connect();

// Filters and search query handling
$search = trim($_GET['search'] ?? '');
$section_filter = trim($_GET['section'] ?? '');

// Fetch unique sections for filter dropdown
$sections_stmt = $pdo->query('SELECT DISTINCT yr_section FROM students ORDER BY yr_section ASC');
$available_sections = $sections_stmt->fetchAll(PDO::FETCH_COLUMN);

// Build search query safely with prepared statements
$query = 'SELECT s.student_id, s.student_number, s.first_name, s.middle_name, s.last_name,
                 s.yr_section, u.username, u.email, u.account_status,
                 COUNT(sc.student_class_id) AS enrolled_classes_count
          FROM students s
          JOIN users u ON s.user_id = u.user_id
          LEFT JOIN student_classes sc ON s.student_id = sc.student_id
          WHERE 1=1';

$params = [];

if ($search !== '') {
    $query .= ' AND (s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR u.email LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($section_filter !== '') {
    $query .= ' AND s.yr_section = ?';
    $params[] = $section_filter;
}

$query .= ' GROUP BY s.student_id ORDER BY s.last_name ASC, s.first_name ASC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-people text-success me-1"></i>Student Directory
        </h1>
        <p class="page-subtitle">Inspect registered students, section allocations, and active course enrollments.</p>
    </div>
    <div>
        <span class="badge bg-secondary px-3 py-2">
            Total Students Found: <strong class="text-dark ms-1"><?php echo count($students); ?></strong>
        </span>
    </div>
</div>

<!-- Search and Filter Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="students.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" name="search" data-table-search="deanStudentsTable" class="form-control border-start-0 ps-0" placeholder="Search by name, student #, or email (live search)..." value="<?php echo e($search); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="section" class="form-select">
                    <option value="">All Year & Sections</option>
                    <?php foreach ($available_sections as $sec): ?>
                        <option value="<?php echo e($sec); ?>" <?php echo ($section_filter === $sec) ? 'selected' : ''; ?>>
                            <?php echo e($sec); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-forest flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <?php if ($search !== '' || $section_filter !== ''): ?>
                    <a href="students.php" class="btn btn-subtle">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Students Table (Clean Light Data Table) -->
<div class="card">
    <div class="table-responsive table-responsive-sticky" style="max-height: 700px;">
        <table class="table table-hover align-middle mb-0" id="deanStudentsTable">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Student Number</th>
                    <th>Full Name</th>
                    <th>Year & Section</th>
                    <th>User Account</th>
                    <th>Email Address</th>
                    <th class="text-center">Enrolled Classes</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-subtle"></i>
                            No students match your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $idx => $st): ?>
                        <tr>
                            <td class="text-muted small"><?php echo $idx + 1; ?></td>
                            <td>
                                <span class="font-monospace fw-bold text-dark">
                                    <?php echo e($st['student_number']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark">
                                    <?php echo e($st['last_name'] . ', ' . $st['first_name'] . ' ' . $st['middle_name']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo e($st['yr_section']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-secondary small">@<?php echo e($st['username']); ?></span>
                            </td>
                            <td class="small text-muted">
                                <?php echo e($st['email'] ?: '—'); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary">
                                    <?php echo $st['enrolled_classes_count']; ?> Classes
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($st['account_status'] === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
