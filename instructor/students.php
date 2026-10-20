<?php
/**
 * Instructor — Class Rosters & OCR Student Enrollment
 * 
 * Allows faculty to:
 * 1. View and manage enrolled students for each of their assigned courses.
 * 2. Upload student rosters in Image (JPG, PNG) or PDF format.
 * 3. Extract text using local PDF extraction or external Tesseract OCR server.
 * 4. Interactively review, edit, and correct parsed student records.
 * 5. Confirm and batch-enroll students into MariaDB with full transaction safety.
 * 
 * Follows Donezo light design system and WCAG 2.2 AA standards.
 */

$page_title = 'Class Rosters & OCR Enrollment';
require_once __DIR__ . '/../includes/header.php';
require_role(['instructor']);

$pdo = db_connect();
$instructor = get_current_instructor($pdo);
$inst_id = (int)$instructor['instructor_id'];

require_once __DIR__ . '/../includes/OcrClient.php';
require_once __DIR__ . '/../includes/RosterParser.php';

// 1. Fetch all assigned classes for dropdown selector
$stmt = $pdo->prepare(
    'SELECT c.class_id, s.subject_code, s.subject_name, c.school_year, c.school_semester,
            (SELECT COUNT(*) FROM student_classes sc WHERE sc.class_id = c.class_id) AS enrolled_count
     FROM classes c
     JOIN subjects s ON c.subject_id = s.subject_id
     WHERE c.instructor_id = ?
     ORDER BY c.school_year DESC, c.school_semester DESC, s.subject_code ASC'
);
$stmt->execute([$inst_id]);
$my_classes = $stmt->fetchAll();

// Determine active class
$class_id = (int)($_GET['class_id'] ?? ($my_classes[0]['class_id'] ?? 0));
$class_info = null;

if ($class_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT c.*, s.subject_code, s.subject_name
         FROM classes c
         JOIN subjects s ON c.subject_id = s.subject_id
         WHERE c.class_id = ? AND c.instructor_id = ?'
    );
    $stmt->execute([$class_id, $inst_id]);
    $class_info = $stmt->fetch();
    if (!$class_info) {
        $class_id = 0; // Not authorized or doesn't exist
    }
}

// ═══════════════════════════════════════════════════════════
// POST ACTION HANDLERS
// ═══════════════════════════════════════════════════════════
$ocr_result = null;
$parsed_data = null;
$active_tab = 'roster';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class_id > 0) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ─── ACTION A: Manual Single Student Enrollment ─────────
    if ($action === 'manual_enroll') {
        $s_num = trim($_POST['student_number'] ?? '');
        $f_name = trim($_POST['first_name'] ?? '');
        $m_name = trim($_POST['middle_name'] ?? '');
        $l_name = trim($_POST['last_name'] ?? '');
        $section = trim($_POST['yr_section'] ?? '1-A');
        $email = trim($_POST['email'] ?? '');

        if ($s_num === '' || $f_name === '' || $l_name === '') {
            set_flash('danger', 'Student number, first name, and last name are required.');
        } else {
            try {
                $pdo->beginTransaction();

                // Check if student exists
                $stmt = $pdo->prepare('SELECT student_id, user_id FROM students WHERE student_number = ?');
                $stmt->execute([$s_num]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $student_id = (int)$existing['student_id'];
                } else {
                    // Create user
                    $username = 's_' . preg_replace('/[^a-zA-Z0-9]/', '', $s_num);
                    // Check duplicate username
                    $u_check = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
                    $u_check->execute([$username]);
                    if ($u_check->fetch()) {
                        $username .= '_' . rand(100, 999);
                    }

                    $pwd_hash = password_hash('password123', PASSWORD_DEFAULT);
                    $u_stmt = $pdo->prepare(
                        'INSERT INTO users (username, email, password_hash, role, account_status)
                         VALUES (?, ?, ?, "student", "active")'
                    );
                    $u_stmt->execute([$username, $email ?: null, $pwd_hash]);
                    $user_id = (int)$pdo->lastInsertId();

                    // Insert student
                    $s_stmt = $pdo->prepare(
                        'INSERT INTO students (user_id, student_number, first_name, middle_name, last_name, yr_section)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $s_stmt->execute([$user_id, $s_num, $f_name, $m_name ?: null, $l_name, $section]);
                    $student_id = (int)$pdo->lastInsertId();
                }

                // Enroll into class
                $sc_stmt = $pdo->prepare(
                    'INSERT IGNORE INTO student_classes (student_id, class_id) VALUES (?, ?)'
                );
                $sc_stmt->execute([$student_id, $class_id]);

                // Audit log
                $a_stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
                $a_stmt->execute([
                    current_user()['user_id'],
                    'STUDENT_ENROLLED_MANUAL',
                    "Enrolled student #$s_num into class_id $class_id"
                ]);

                $pdo->commit();
                set_flash('success', "Student {$f_name} {$l_name} ({$s_num}) enrolled successfully.");
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Enrollment error: ' . $e->getMessage());
                set_flash('danger', 'Failed to enroll student: ' . $e->getMessage());
            }
        }
        header("Location: students.php?class_id=$class_id");
        exit;
    }

    // ─── ACTION B: Process OCR Document Upload ───────────────
    elseif ($action === 'process_ocr') {
        $active_tab = 'ocr';
        if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
            set_flash('warning', 'Please select an image or PDF document to upload.');
        } else {
            $client = new OcrClient();
            $ocr_result = $client->processUpload($_FILES['document']);

            if (!$ocr_result['success']) {
                set_flash('danger', $ocr_result['error'] ?? 'Document processing failed.');
            } else {
                $raw_text = $ocr_result['text'];
                $parsed_data = RosterParser::parse($raw_text, $pdo, $class_id);
                if ($parsed_data['total_found'] === 0) {
                    set_flash('warning', 'Document text was extracted, but no student ID patterns were identified. You can review the raw text below and adjust.');
                } else {
                    set_flash('info', "Extracted {$parsed_data['total_found']} student records from {$ocr_result['filename']}. Please review before confirming.");
                }
            }
        }
    }

    // ─── ACTION C: Re-parse User-Edited Text ────────────────
    elseif ($action === 'reparse_text') {
        $active_tab = 'ocr';
        $raw_text = trim($_POST['raw_text'] ?? '');
        if ($raw_text === '') {
            set_flash('warning', 'Raw text is empty. Nothing to parse.');
        } else {
            $parsed_data = RosterParser::parse($raw_text, $pdo, $class_id);
            $ocr_result = [
                'success' => true,
                'source' => 'manual_edit',
                'text' => $raw_text,
                'page_count' => 1,
                'filename' => 'Edited Text'
            ];
            set_flash('info', "Re-parsed {$parsed_data['total_found']} candidate student records.");
        }
    }

    // ─── ACTION D: Confirm & Import Batch to Database ───────
    elseif ($action === 'confirm_import') {
        $selected_students = $_POST['candidates'] ?? [];
        if (empty($selected_students)) {
            set_flash('warning', 'No students were selected for import.');
            header("Location: students.php?class_id=$class_id");
            exit;
        }

        $imported_count = 0;
        $errors = [];

        try {
            $pdo->beginTransaction();

            $stmt_check_student = $pdo->prepare('SELECT student_id, user_id FROM students WHERE student_number = ?');
            $stmt_check_username = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
            $stmt_insert_user = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, account_status) VALUES (?, ?, ?, "student", "active")');
            $stmt_insert_student = $pdo->prepare('INSERT INTO students (user_id, student_number, first_name, middle_name, last_name, yr_section) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt_enroll = $pdo->prepare('INSERT IGNORE INTO student_classes (student_id, class_id) VALUES (?, ?)');

            $default_pwd_hash = password_hash('password123', PASSWORD_DEFAULT);

            foreach ($selected_students as $candidate) {
                if (empty($candidate['selected'])) continue;

                $s_num = trim($candidate['student_number'] ?? '');
                $l_name = trim($candidate['last_name'] ?? '');
                $f_name = trim($candidate['first_name'] ?? '');
                $m_name = trim($candidate['middle_name'] ?? '');
                $section = trim($candidate['yr_section'] ?? '1-A');

                if ($s_num === '' || $f_name === '' || $l_name === '') {
                    continue; // Skip incomplete
                }

                // 1. Check if student already exists in students table
                $stmt_check_student->execute([$s_num]);
                $existing = $stmt_check_student->fetch();

                if ($existing) {
                    $student_id = (int)$existing['student_id'];
                } else {
                    // Create new student user
                    $username = 's_' . preg_replace('/[^a-zA-Z0-9]/', '', $s_num);
                    $stmt_check_username->execute([$username]);
                    if ($stmt_check_username->fetch()) {
                        $username .= '_' . rand(100, 999);
                    }

                    $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $f_name) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $l_name) . '@school.edu');

                    $stmt_insert_user->execute([$username, $email, $default_pwd_hash]);
                    $user_id = (int)$pdo->lastInsertId();

                    $stmt_insert_student->execute([
                        $user_id,
                        $s_num,
                        $f_name,
                        $m_name ?: null,
                        $l_name,
                        $section
                    ]);
                    $student_id = (int)$pdo->lastInsertId();
                }

                // 2. Enroll student into this class
                $stmt_enroll->execute([$student_id, $class_id]);
                if ($stmt_enroll->rowCount() > 0) {
                    $imported_count++;
                }
            }

            // Audit trail
            $stmt_audit = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
            $stmt_audit->execute([
                current_user()['user_id'],
                'STUDENTS_IMPORTED_OCR',
                "Enrolled $imported_count students into class_id $class_id"
            ]);

            $pdo->commit();
            set_flash('success', "Batch import successful! Enrolled $imported_count student(s) into {$class_info['subject_code']}.");
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Batch import error: ' . $e->getMessage());
            set_flash('danger', 'Failed to import batch: ' . $e->getMessage());
        }

        header("Location: students.php?class_id=$class_id");
        exit;
    }

    // ─── ACTION E: Remove Student Enrollment from this Class ──
    elseif ($action === 'unenroll') {
        $sc_id = (int)($_POST['student_class_id'] ?? 0);
        if ($sc_id > 0) {
            $stmt = $pdo->prepare('DELETE FROM student_classes WHERE student_class_id = ? AND class_id = ?');
            $stmt->execute([$sc_id, $class_id]);
            set_flash('info', 'Student removed from class enrollment.');
        }
        header("Location: students.php?class_id=$class_id");
        exit;
    }
}

// Fetch currently enrolled students for active class
$enrolled_students = [];
if ($class_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT sc.student_class_id, st.student_id, st.student_number, st.first_name, st.middle_name, st.last_name,
                st.yr_section, u.email, u.username
         FROM student_classes sc
         JOIN students st ON sc.student_id = st.student_id
         JOIN users u ON st.user_id = u.user_id
         WHERE sc.class_id = ?
         ORDER BY st.last_name ASC, st.first_name ASC'
    );
    $stmt->execute([$class_id]);
    $enrolled_students = $stmt->fetchAll();
}
?>

<!-- Page Header (Donezo Style) -->
<div class="page-header-row mb-3">
    <div>
        <h1 class="page-title">
            <i class="bi bi-person-lines-fill text-success me-1"></i>Class Rosters & OCR Enrollment
        </h1>
        <p class="page-subtitle">Upload student rosters in Image/PDF format with Tesseract OCR, or manage class rosters manually.</p>
    </div>
    <div>
        <?php if ($class_info): ?>
            <span class="badge bg-secondary px-3 py-2">
                Course: <strong class="text-dark ms-1"><?php echo e($class_info['subject_code']); ?></strong>
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Class Selector Card -->
<div class="card mb-3">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-9">
                <label class="form-label small text-muted mb-1">Select Course Section</label>
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choose a class —</option>
                    <?php foreach ($my_classes as $cls): ?>
                        <option value="<?php echo $cls['class_id']; ?>" <?php echo $cls['class_id'] == $class_id ? 'selected' : ''; ?>>
                            <?php echo e($cls['subject_code'] . ' — ' . $cls['subject_name'] . ' (' . $cls['school_year'] . ' ' . $cls['school_semester'] . ') — ' . $cls['enrolled_count'] . ' enrolled'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-subtle w-100" data-bs-toggle="modal" data-bs-target="#manualAddModal" <?php echo $class_id === 0 ? 'disabled' : ''; ?>>
                    <i class="bi bi-person-plus text-success me-1"></i> Add Single Student
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($class_id > 0 && $class_info): ?>
    <!-- Navigation Tabs (Donezo Clean Style) -->
    <ul class="nav nav-pills mb-3" id="rosterTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'roster' ? 'active' : ''; ?> fw-semibold" id="roster-tab" data-bs-toggle="pill" data-bs-target="#roster-pane" type="button" role="tab">
                <i class="bi bi-people me-1"></i> Current Enrolled Roster (<?php echo count($enrolled_students); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'ocr' ? 'active' : ''; ?> fw-semibold" id="ocr-tab" data-bs-toggle="pill" data-bs-target="#ocr-pane" type="button" role="tab">
                <i class="bi bi-camera me-1"></i> OCR Document / Image Ingestion
            </button>
        </li>
    </ul>

    <div class="tab-content" id="rosterTabsContent">
        
        <!-- ═══════════════════════════════════════════════════════
             TAB 1: CURRENT ENROLLED ROSTER
             ═══════════════════════════════════════════════════════ -->
        <div class="tab-pane fade <?php echo $active_tab === 'roster' ? 'show active' : ''; ?>" id="roster-pane" role="tabpanel">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-bold text-dark"><i class="bi bi-list-check text-success me-1"></i>Enrolled Students</span>
                        <span class="badge bg-secondary" data-count-for="enrolledStudentsTable"><?php echo count($enrolled_students); ?> students</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="max-width: 240px;">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control form-control-sm border-start-0" placeholder="Filter student..." data-table-search="enrolledStudentsTable">
                        </div>
                        <a href="grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-sm btn-subtle">
                            <i class="bi bi-pencil-square text-primary me-1"></i> Grades
                        </a>
                        <a href="attendance.php?class_id=<?php echo $class_id; ?>" class="btn btn-sm btn-subtle">
                            <i class="bi bi-calendar-check text-success me-1"></i> Attendance
                        </a>
                    </div>
                </div>
                <div class="table-responsive table-responsive-sticky" style="max-height: 600px;">
                    <table class="table table-hover align-middle mb-0" id="enrolledStudentsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Student Number</th>
                                <th>Full Name</th>
                                <th>Year & Section</th>
                                <th>Email</th>
                                <th class="text-center" style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($enrolled_students)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-person-x fs-1 d-block mb-2 text-subtle"></i>
                                        No students enrolled in this course section yet.<br>
                                        Use the <strong>OCR Document Ingestion</strong> tab or click <strong>Add Single Student</strong> to enroll students.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($enrolled_students as $idx => $st): ?>
                                    <tr>
                                        <td class="text-muted small"><?php echo $idx + 1; ?></td>
                                        <td>
                                            <span class="font-monospace fw-bold text-dark"><?php echo e($st['student_number']); ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark">
                                                <?php echo e($st['last_name'] . ', ' . $st['first_name'] . ($st['middle_name'] ? ' ' . $st['middle_name'] : '')); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo e($st['yr_section']); ?></span>
                                        </td>
                                        <td class="small text-muted">
                                            <?php echo e($st['email'] ?: 'No email set'); ?>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" onsubmit="return confirm('Remove student <?php echo e($st['first_name'] . ' ' . $st['last_name']); ?> from this class?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="unenroll">
                                                <input type="hidden" name="student_class_id" value="<?php echo $st['student_class_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-subtle text-danger" title="Unenroll Student">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════
             TAB 2: OCR DOCUMENT / IMAGE INGESTION
             ═══════════════════════════════════════════════════════ -->
        <div class="tab-pane fade <?php echo $active_tab === 'ocr' ? 'show active' : ''; ?>" id="ocr-pane" role="tabpanel">
            
            <!-- Upload Box Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark">
                        <i class="bi bi-cloud-arrow-up text-success me-2"></i>Upload Student Roster Document (Image or PDF)
                    </span>
                    <span class="badge bg-secondary">Tesseract OCR & Digital PDF Ingestion</span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data" id="ocrUploadForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="process_ocr">

                        <div class="row g-3 align-items-center">
                            <div class="col-md-8">
                                <label class="form-label small text-muted fw-semibold mb-1">Select Document or Photo Roster</label>
                                <input type="file" name="document" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required id="ocrFileInput">
                                <div class="form-text small mt-1">
                                    <i class="bi bi-info-circle me-1"></i> Supported: <strong>JPG, PNG, PDF</strong> (Max 5MB, up to 5 pages). Digital PDFs extract immediately; scanned files are processed via secure OCR server.
                                </div>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-forest w-100 py-2 shadow-sm" id="ocrSubmitBtn">
                                    <i class="bi bi-lightning-charge me-1"></i> Extract & Parse Roster
                                </button>
                            </div>
                        </div>

                        <!-- Processing Spinner / Loading State -->
                        <div id="ocrLoadingSpinner" class="mt-4 p-4 text-center rounded-3 bg-light border" style="display: none;">
                            <div class="spinner-border text-success mb-2" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">Analyzing Document & Running Character Recognition...</h6>
                            <p class="text-muted small mb-0">Please wait while the document streams and character patterns are tokenized. This typically takes 2–5 seconds.</p>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($parsed_data)): ?>
                <!-- ═══════════════════════════════════════════════
                     INTERACTIVE REVIEW & CORRECTION INTERFACE
                     ═══════════════════════════════════════════════ -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <h6 class="mb-0 fw-bold text-dark">
                                <i class="bi bi-check2-circle text-success me-2"></i>Review & Correct Parsed Student Records
                            </h6>
                            <small class="text-muted">Review entries, verify student numbers, correct typos, and select candidates to enroll.</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success-subtle text-success border border-success px-2 py-1">
                                <?php echo $parsed_data['new_count']; ?> New
                            </span>
                            <span class="badge bg-primary-subtle text-primary border border-primary px-2 py-1">
                                <?php echo $parsed_data['existing_count']; ?> Existing
                            </span>
                            <?php if ($parsed_data['already_enrolled_count'] > 0): ?>
                                <span class="badge bg-secondary px-2 py-1">
                                    <?php echo $parsed_data['already_enrolled_count']; ?> Already Enrolled
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Raw Text Accordion (Collapsible) -->
                    <div class="accordion accordion-flush border-bottom" id="rawTextAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingRaw">
                                <button class="accordion-button collapsed py-2 px-3 bg-light text-muted small" type="button" data-bs-toggle="collapse" data-bs-target="#collapseRaw" aria-expanded="false">
                                    <i class="bi bi-code-square me-2"></i> Inspect / Edit Raw Extracted Text (Source: <?php echo e($ocr_result['source'] ?? 'OCR'); ?>)
                                </button>
                            </h2>
                            <div id="collapseRaw" class="accordion-collapse collapse" data-bs-parent="#rawTextAccordion">
                                <div class="accordion-body p-3 bg-light">
                                    <form method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="reparse_text">
                                        <label class="form-label small text-muted">You can edit the raw OCR text below and click <strong>Re-parse</strong> if names or IDs were slightly misread:</label>
                                        <textarea name="raw_text" rows="6" class="form-control font-monospace small mb-2"><?php echo e($ocr_result['text'] ?? ''); ?></textarea>
                                        <button type="submit" class="btn btn-sm btn-subtle">
                                            <i class="bi bi-arrow-repeat me-1"></i> Re-parse Text
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Candidate Review Form -->
                    <form method="POST" id="batchConfirmForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="confirm_import">

                        <div class="table-responsive table-responsive-sticky" style="max-height: 550px;">
                            <table class="table table-hover align-middle mb-0" id="candidatesTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;" class="text-center">
                                            <input type="checkbox" id="selectAllCandidates" checked title="Select All">
                                        </th>
                                        <th style="min-width: 150px;">Student Number</th>
                                        <th style="min-width: 160px;">Last Name</th>
                                        <th style="min-width: 160px;">First Name</th>
                                        <th style="min-width: 140px;">Middle Name</th>
                                        <th style="min-width: 120px;">Year & Section</th>
                                        <th class="text-center" style="width: 130px;">Status</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="candidatesTableBody">
                                    <?php foreach ($parsed_data['candidates'] as $i => $cand): 
                                        $is_already = ($cand['status'] === 'already_enrolled');
                                    ?>
                                        <tr class="<?php echo $is_already ? 'table-light opacity-75' : ''; ?>">
                                            <td class="text-center">
                                                <input type="checkbox" 
                                                       name="candidates[<?php echo $i; ?>][selected]" 
                                                       value="1" 
                                                       class="candidate-checkbox form-check-input"
                                                       <?php echo $cand['selected'] ? 'checked' : ''; ?>
                                                       <?php echo $is_already ? 'disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       name="candidates[<?php echo $i; ?>][student_number]" 
                                                       value="<?php echo e($cand['student_number']); ?>" 
                                                       class="form-control form-control-sm font-monospace fw-bold" 
                                                       required>
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       name="candidates[<?php echo $i; ?>][last_name]" 
                                                       value="<?php echo e($cand['last_name']); ?>" 
                                                       class="form-control form-control-sm" 
                                                       required>
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       name="candidates[<?php echo $i; ?>][first_name]" 
                                                       value="<?php echo e($cand['first_name']); ?>" 
                                                       class="form-control form-control-sm" 
                                                       required>
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       name="candidates[<?php echo $i; ?>][middle_name]" 
                                                       value="<?php echo e($cand['middle_name']); ?>" 
                                                       class="form-control form-control-sm">
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       name="candidates[<?php echo $i; ?>][yr_section]" 
                                                       value="<?php echo e($cand['yr_section']); ?>" 
                                                       class="form-control form-control-sm">
                                            </td>
                                            <td class="text-center">
                                                <?php if ($cand['status'] === 'already_enrolled'): ?>
                                                    <span class="badge bg-secondary">Enrolled</span>
                                                <?php elseif ($cand['status'] === 'existing'): ?>
                                                    <span class="badge bg-primary">Existing</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">New</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-subtle text-muted" onclick="this.closest('tr').remove();" title="Remove this candidate row">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Card Footer with Action Buttons -->
                        <div class="card-footer bg-white border-top p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-subtle" id="addRowBtn">
                                    <i class="bi bi-plus-circle me-1"></i> Add Candidate Row
                                </button>
                                <a href="students.php?class_id=<?php echo $class_id; ?>" class="btn btn-sm btn-subtle">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset / Upload New
                                </a>
                            </div>
                            <!-- Primary Explicit Confirmation Button -->
                            <button type="submit" class="btn btn-forest px-4 shadow-sm" id="confirmImportBtn">
                                <i class="bi bi-check2-all me-1"></i> Confirm & Enroll Selected Students
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: MANUAL ADD SINGLE STUDENT
     ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="manualAddModal" tabindex="-1" aria-labelledby="manualAddModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title fw-bold" id="manualAddModalLabel">
                    <i class="bi bi-person-plus text-success me-2"></i>Add Single Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="manual_enroll">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted">Student Number <span class="text-danger">*</span></label>
                        <input type="text" name="student_number" class="form-control font-monospace" placeholder="e.g. 2023-00123" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" placeholder="Juan" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" placeholder="Dela Cruz" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" placeholder="Santos">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Year & Section</label>
                            <input type="text" name="yr_section" class="form-control" placeholder="e.g. BSCS 3-A" value="1-A">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-muted">Email Address (Optional)</label>
                        <input type="email" name="email" class="form-control" placeholder="juan.delacruz@school.edu">
                    </div>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-subtle" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-forest px-4">
                        <i class="bi bi-check-lg me-1"></i> Enroll Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Dynamic Client Script for OCR Processing & Table Interactions -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Loading spinner on upload submit
    const uploadForm = document.getElementById('ocrUploadForm');
    const spinner = document.getElementById('ocrLoadingSpinner');
    const submitBtn = document.getElementById('ocrSubmitBtn');

    if (uploadForm && spinner && submitBtn) {
        uploadForm.addEventListener('submit', function () {
            spinner.style.display = 'block';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing Document...';
        });
    }

    // 2. Select All Checkboxes in Candidate Table
    const selectAll = document.getElementById('selectAllCandidates');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            const boxes = document.querySelectorAll('.candidate-checkbox:not(:disabled)');
            boxes.forEach(b => b.checked = selectAll.checked);
        });
    }

    // 3. Add Manual Row button
    const addRowBtn = document.getElementById('addRowBtn');
    const tableBody = document.getElementById('candidatesTableBody');

    if (addRowBtn && tableBody) {
        addRowBtn.addEventListener('click', function () {
            const rowCount = tableBody.querySelectorAll('tr').length;
            const newIndex = 'new_' + Date.now();
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-center">
                    <input type="checkbox" name="candidates[${newIndex}][selected]" value="1" class="candidate-checkbox form-check-input" checked>
                </td>
                <td>
                    <input type="text" name="candidates[${newIndex}][student_number]" placeholder="2023-XXXXX" class="form-control form-control-sm font-monospace fw-bold" required>
                </td>
                <td>
                    <input type="text" name="candidates[${newIndex}][last_name]" placeholder="Last Name" class="form-control form-control-sm" required>
                </td>
                <td>
                    <input type="text" name="candidates[${newIndex}][first_name]" placeholder="First Name" class="form-control form-control-sm" required>
                </td>
                <td>
                    <input type="text" name="candidates[${newIndex}][middle_name]" placeholder="Middle" class="form-control form-control-sm">
                </td>
                <td>
                    <input type="text" name="candidates[${newIndex}][yr_section]" value="1-A" class="form-control form-control-sm">
                </td>
                <td class="text-center">
                    <span class="badge bg-success">Manual</span>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-subtle text-muted" onclick="this.closest('tr').remove();">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </td>
            `;
            tableBody.appendChild(tr);
            tr.querySelector('input[type="text"]').focus();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
