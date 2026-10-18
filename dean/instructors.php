<?php
/**
 * Dean — Faculty & Instructors Directory
 * 
 * Inspect instructor workloads, department allocations, and teaching loads.
 * Donezo light design system.
 */

$page_title = 'Faculty Directory';
require_once __DIR__ . '/../includes/header.php';
require_role(['dean']);

$pdo = db_connect();

$search = trim($_GET['search'] ?? '');
$dept_filter = trim($_GET['department'] ?? '');

$dept_stmt = $pdo->query('SELECT DISTINCT department FROM instructors WHERE department IS NOT NULL AND department != "" ORDER BY department ASC');
$departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

$query = 'SELECT i.instructor_id, i.first_name, i.middle_name, i.last_name, i.department,
                 u.username, u.email, u.account_status,
                 COUNT(DISTINCT c.class_id) AS total_classes,
                 COUNT(DISTINCT sc.student_id) AS total_students_taught,
                 GROUP_CONCAT(DISTINCT s.subject_code ORDER BY s.subject_code SEPARATOR ", ") AS subjects_taught
          FROM instructors i
          JOIN users u ON i.user_id = u.user_id
          LEFT JOIN classes c ON i.instructor_id = c.instructor_id
          LEFT JOIN subjects s ON c.subject_id = s.subject_id
          LEFT JOIN student_classes sc ON c.class_id = sc.class_id
          WHERE 1=1';

$params = [];

if ($search !== '') {
    $query .= ' AND (i.first_name LIKE ? OR i.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($dept_filter !== '') {
    $query .= ' AND i.department = ?';
    $params[] = $dept_filter;
}

$query .= ' GROUP BY i.instructor_id ORDER BY i.last_name ASC, i.first_name ASC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$instructors = $stmt->fetchAll();
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-person-badge text-success me-1"></i>Faculty & Instructors
        </h1>
        <p class="page-subtitle">Inspect faculty rosters, academic departments, and assigned class loads.</p>
    </div>
    <div>
        <span class="badge bg-secondary px-3 py-2">
            Total Faculty: <strong class="text-dark ms-1"><?php echo count($instructors); ?></strong>
        </span>
    </div>
</div>

<!-- Filter and Search Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="instructors.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Search by name, username, or email..." value="<?php echo e($search); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="department" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo e($d); ?>" <?php echo ($dept_filter === $d) ? 'selected' : ''; ?>>
                            <?php echo e($d); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-forest flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <?php if ($search !== '' || $dept_filter !== ''): ?>
                    <a href="instructors.php" class="btn btn-subtle">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Faculty Table (Clean Light Data Table) -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Faculty Name</th>
                    <th>Department</th>
                    <th>User & Email</th>
                    <th>Subjects Taught</th>
                    <th class="text-center">Assigned Classes</th>
                    <th class="text-center">Students Taught</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($instructors)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-person-x fs-2 d-block mb-2 text-subtle"></i>
                            No faculty members match your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($instructors as $idx => $inst): ?>
                        <tr>
                            <td class="text-muted small"><?php echo $idx + 1; ?></td>
                            <td>
                                <div class="fw-bold text-dark">
                                    <?php echo e($inst['last_name'] . ', ' . $inst['first_name'] . ' ' . $inst['middle_name']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo e($inst['department'] ?: 'Unassigned'); ?>
                                </span>
                            </td>
                            <td>
                                <div class="small text-dark fw-medium">@<?php echo e($inst['username']); ?></div>
                                <div class="small text-muted"><?php echo e($inst['email'] ?: '—'); ?></div>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo e($inst['subjects_taught'] ?: 'No classes yet'); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary">
                                    <?php echo $inst['total_classes']; ?> Classes
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary">
                                    <?php echo $inst['total_students_taught']; ?> Students
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($inst['account_status'] === 'active'): ?>
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
