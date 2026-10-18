<?php
/**
 * Dean — Subject Catalog
 * 
 * Overview of curriculum subjects, code designations, offerings, and enrollment counts.
 * Donezo light design system.
 */

$page_title = 'Subject Catalog';
require_once __DIR__ . '/../includes/header.php';
require_role(['dean']);

$pdo = db_connect();

$search = trim($_GET['search'] ?? '');

$query = 'SELECT s.subject_id, s.subject_code, s.subject_name,
                 COUNT(DISTINCT c.class_id) AS total_classes,
                 COUNT(DISTINCT sc.student_id) AS total_enrolled_students
          FROM subjects s
          LEFT JOIN classes c ON s.subject_id = c.subject_id
          LEFT JOIN student_classes sc ON c.class_id = sc.class_id
          WHERE 1=1';

$params = [];

if ($search !== '') {
    $query .= ' AND (s.subject_code LIKE ? OR s.subject_name LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

$query .= ' GROUP BY s.subject_id ORDER BY s.subject_code ASC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$subjects = $stmt->fetchAll();
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row">
    <div>
        <h1 class="page-title">
            <i class="bi bi-book text-success me-1"></i>Subject Catalog
        </h1>
        <p class="page-subtitle">Overview of curriculum course codes, academic titles, and active class allocations.</p>
    </div>
    <div>
        <span class="badge bg-secondary px-3 py-2">
            Total Subjects: <strong class="text-dark ms-1"><?php echo count($subjects); ?></strong>
        </span>
    </div>
</div>

<!-- Search Form Card -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="subjects.php" class="row g-2">
            <div class="col-md-9">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Search subject code or title..." value="<?php echo e($search); ?>">
                </div>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-forest flex-grow-1">
                    <i class="bi bi-search me-1"></i> Search
                </button>
                <?php if ($search !== ''): ?>
                    <a href="subjects.php" class="btn btn-subtle">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Subjects Table (Clean Light Data Table) -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Subject Code</th>
                    <th>Subject Title</th>
                    <th class="text-center">Class Offerings</th>
                    <th class="text-center">Total Students Enrolled</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subjects)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-journal-x fs-2 d-block mb-2 text-subtle"></i>
                            No subjects found matching your query.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subjects as $idx => $sub): ?>
                        <tr>
                            <td class="text-muted small"><?php echo $idx + 1; ?></td>
                            <td>
                                <span class="badge bg-primary fs-6 px-3 py-1 font-monospace">
                                    <?php echo e($sub['subject_code']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($sub['subject_name']); ?></div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary">
                                    <?php echo $sub['total_classes']; ?> Offerings
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary">
                                    <?php echo $sub['total_enrolled_students']; ?> Students
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
