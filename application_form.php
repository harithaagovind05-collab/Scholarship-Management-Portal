<?php
/*
|----------------------------------------------------------
| application_form.php - Add / Edit Application
| - No "id" in URL  -> Add mode (INSERT)
| - ?id=2 in URL    -> Edit mode (UPDATE)
| Student and Scholarship dropdowns come from the database.
|----------------------------------------------------------
*/

session_start();
require_once 'db_connect.php';
require_once 'eligibility.php';

// Was the page requested as POST (form submission) or GET (normal view)?
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- Which mode are we in? ---------- */
$id     = $_GET['id'] ?? '';
$isEdit = false;

if (ctype_digit($id) && $id > 0) {
    $isEdit = true;

    // Load the existing application (prepared statement)
    $stmt = $pdo->prepare("SELECT Application_ID, Student_ID, Scholarship_ID, Apply_Date, Status
                           FROM Application WHERE Application_ID = :id");
    $stmt->execute([':id' => $id]);
    $application = $stmt->fetch();

    if (!$application) {
        $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Application not found. It may have been deleted.'];
        header('Location: applications.php');
        exit;
    }

    // Pre-fill values (only on first load, not after a failed submit)
    if ($requestMethod != 'POST') {
        $studentId     = $application['Student_ID'];
        $scholarshipId = $application['Scholarship_ID'];
        $applyDate     = $application['Apply_Date'];
        $status        = $application['Status'];
    }
} else {
    $id = 0;   // Add mode
}

/* ---------- Default values ---------- */
$studentId     = $studentId     ?? '';
$scholarshipId = $scholarshipId ?? '';
$applyDate     = $applyDate     ?? date('Y-m-d');   // default: today
$status        = $status        ?? 'Pending';
$errors        = [];
$eligibilityNotes = [];   // manual-verification conditions (shown when eligible)

/* ---------- Fetch dropdown data (from the database, never hardcoded) ---------- */

// All students
$stmt = $pdo->prepare("SELECT Student_ID, Name, Gender, Course FROM Student ORDER BY Name ASC");
$stmt->execute();
$students = $stmt->fetchAll();

// All scholarships
$stmt = $pdo->prepare("SELECT Scholarship_ID, Name, Amount, Required_Gender, Required_Course_Keywords FROM Scholarship ORDER BY Name ASC");
$stmt->execute();
$scholarships = $stmt->fetchAll();

/* ---------- Handle form submission ---------- */
if ($requestMethod == 'POST') {

    // 1. Collect input
    $studentId     = trim($_POST['student_id']     ?? '');
    $scholarshipId = trim($_POST['scholarship_id'] ?? '');
    $applyDate     = trim($_POST['apply_date']     ?? '');
    $status        = trim($_POST['status']         ?? '');

    // 2. Student must be chosen AND must exist in the Student table
    if ($studentId == '' || !ctype_digit($studentId)) {
        $errors[] = 'Please select a student.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Student WHERE Student_ID = :id");
        $stmt->execute([':id' => $studentId]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'The selected student does not exist in the database.';
        }
    }

    // 3. Scholarship must be chosen AND must exist in the Scholarship table
    if ($scholarshipId == '' || !ctype_digit($scholarshipId)) {
        $errors[] = 'Please select a scholarship.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Scholarship WHERE Scholarship_ID = :id");
        $stmt->execute([':id' => $scholarshipId]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'The selected scholarship does not exist in the database.';
        }
    }

    // 3b. ELIGIBILITY ENFORCEMENT (server-side, runs on Add AND Edit).
    // The scholarship's stored criteria (Required_Gender /
    // Required_Course_Keywords, e.g. Girls in STEM = female engineering
    // students) are checked here against the student's stored data -
    // not just hidden in the browser.
    if (empty($errors)) {
        $result = checkEligibilityByIds($pdo, $studentId, $scholarshipId);

        if ($result !== null) {
            if (!$result['eligible']) {
                // Allow marking an existing legacy application as Rejected
                // when student + scholarship are unchanged, so ineligible
                // records found by the audit can be cleaned up here too.
                $unchangedPair = $isEdit
                    && isset($application)
                    && (int)$application['Student_ID']     === (int)$studentId
                    && (int)$application['Scholarship_ID'] === (int)$scholarshipId;

                if (!($unchangedPair && $status === 'Rejected')) {
                    foreach ($result['blockers'] as $blocker) {
                        $errors[] = $blocker;
                    }
                    $errors[] = 'Eligibility is re-checked on the server, so this application cannot be saved.';
                }
            } else {
                $eligibilityNotes = $result['manual_checks'];
            }
        }
    }

    // 4. Apply date must be a real date
    if ($applyDate == '') {
        $errors[] = 'Apply date is required.';
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $applyDate);
        if (!$d || $d->format('Y-m-d') !== $applyDate) {
            $errors[] = 'Apply date must be a valid date.';
        }
    }

    // 5. Status must be one of the allowed values
    $allowedStatus = ['Pending', 'Approved', 'Rejected'];
    if (!in_array($status, $allowedStatus)) {
        $errors[] = 'Status must be Pending, Approved or Rejected.';
    }

    // 6. Save using INSERT or UPDATE (prepared statements)
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE Application
                                       SET Student_ID = :sid, Scholarship_ID = :scid,
                                           Apply_Date = :adate, Status = :status
                                       WHERE Application_ID = :id");
                $stmt->execute([
                    ':sid'    => $studentId,
                    ':scid'   => $scholarshipId,
                    ':adate'  => $applyDate,
                    ':status' => $status,
                    ':id'     => $id
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Application #' . $id . ' updated successfully.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO Application (Student_ID, Scholarship_ID, Apply_Date, Status)
                                       VALUES (:sid, :scid, :adate, :status)");
                $stmt->execute([
                    ':sid'    => $studentId,
                    ':scid'   => $scholarshipId,
                    ':adate'  => $applyDate,
                    ':status' => $status
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Application added successfully.'];
            }

            // Redirect back to the list so refresh does not resubmit the form
            header('Location: applications.php');
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                $errors[] = 'Could not save: the selected student or scholarship is invalid.';
            } else {
                $errors[] = 'Something went wrong while saving. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $isEdit ? 'Edit Application' : 'Add Application'; ?> | Scholarship Management Portal</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom styles -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="container-fluid">
    <div class="row">

        <!-- ===================== SIDEBAR (desktop) ===================== -->
        <nav class="col-lg-2 d-none d-lg-block sidebar px-0">
            <div class="brand">
                <i class="bi bi-mortarboard-fill fs-4"></i> Scholarship Portal
            </div>

            <div class="nav-label">Main</div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-label">Manage</li>
                <li class="nav-item">
                    <a class="nav-link" href="students.php"><i class="bi bi-people"></i> Students</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="scholarships.php"><i class="bi bi-award"></i> Scholarships</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="sql_lab.php"><i class="bi bi-database"></i> SQL Query Lab</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#"><i class="bi bi-shield-lock"></i> Admins</a>
                </li>
            </ul>
        </nav>

        <!-- ===================== MAIN CONTENT ===================== -->
        <main class="col-lg-10 ms-sm-auto px-0 pb-4">

            <!-- Topbar -->
            <div class="topbar d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button"
                            data-bs-toggle="offcanvas" data-bs-target="#mobileMenu">
                        <i class="bi bi-list fs-5"></i>
                    </button>
                    <h5><?php echo $isEdit ? 'Edit Application' : 'Add Application'; ?></h5>
                </div>
                <nav aria-label="breadcrumb" class="d-none d-md-block">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="applications.php" class="text-decoration-none">Applications</a></li>
                        <li class="breadcrumb-item active"><?php echo $isEdit ? 'Edit' : 'Add'; ?></li>
                    </ol>
                </nav>
            </div>

            <div class="px-3 px-md-4">

                <div class="panel mt-4 mx-auto" style="max-width: 720px;">

                    <div class="panel-head d-flex align-items-center justify-content-between">
                        <h6>
                            <i class="bi <?php echo $isEdit ? 'bi-pencil-square' : 'bi-file-earmark-plus'; ?> me-2 text-primary"></i>
                            <?php echo $isEdit ? 'Edit Application #' . htmlspecialchars($application['Application_ID']) : 'New Application Details'; ?>
                        </h6>
                    </div>

                    <div class="p-4">

                        <!-- Friendly warning if there is nothing to link yet -->
                        <?php if (count($students) == 0 || count($scholarships) == 0): ?>
                            <div class="alert alert-warning d-flex gap-2" role="alert">
                                <i class="bi bi-exclamation-circle-fill"></i>
                                <div>
                                    <?php if (count($students) == 0): ?>
                                        You need at least one <strong>student</strong> before creating an application.
                                        <a href="student_form.php" class="alert-link">Add a student first</a>.<br>
                                    <?php endif; ?>
                                    <?php if (count($scholarships) == 0): ?>
                                        You need at least one <strong>scholarship</strong> before creating an application.
                                        <a href="scholarship_form.php" class="alert-link">Add a scholarship first</a>.
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Error messages -->
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger d-flex gap-2" role="alert">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>
                                    <strong>Please fix the following:</strong>
                                    <ul class="mb-0 mt-1">
                                        <?php foreach ($errors as $err): ?>
                                            <li><?php echo htmlspecialchars($err); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (count($students) > 0 && count($scholarships) > 0): ?>

                            <form method="post" action="<?php echo $isEdit ? 'application_form.php?id=' . (int)$id : 'application_form.php'; ?>" novalidate>

                                <div class="mb-3">
                                    <label class="form-label">Student <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
                                        <select name="student_id" class="form-select" required>
                                            <option value="">-- Select a student --</option>
                                            <?php foreach ($students as $stu): ?>
                                                <option value="<?php echo (int)$stu['Student_ID']; ?>"
                                                        <?php echo $studentId == $stu['Student_ID'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($stu['Name']); ?>
                                                    <?php echo $stu['Gender'] != '' ? '[' . htmlspecialchars($stu['Gender']) . ']' : '[gender not recorded]'; ?>
                                                    <?php echo $stu['Course'] != '' ? '(' . htmlspecialchars($stu['Course']) . ')' : ''; ?>
                                                    &mdash; ID <?php echo (int)$stu['Student_ID']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Scholarship <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-award"></i></span>
                                        <select name="scholarship_id" class="form-select" required>
                                            <option value="">-- Select a scholarship --</option>
                                            <?php foreach ($scholarships as $sch): ?>
                                                <option value="<?php echo (int)$sch['Scholarship_ID']; ?>"
                                                        <?php echo $scholarshipId == $sch['Scholarship_ID'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($sch['Name']); ?>
                                                    &mdash; <?php echo number_format($sch['Amount'], 2); ?>
                                                    &mdash; ID <?php echo (int)$sch['Scholarship_ID']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Live eligibility banner (server-backed via eligibility_check.php) -->
                                    <div id="eligibilityBanner" class="d-none"></div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Apply Date <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-calendar-event"></i></span>
                                            <input type="date" name="apply_date" class="form-control" required
                                                   value="<?php echo htmlspecialchars($applyDate); ?>">
                                        </div>
                                        <div class="form-text">Defaults to today's date.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Status</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-flag"></i></span>
                                            <select name="status" class="form-select">
                                                <option value="Pending"  <?php echo $status == 'Pending'  ? 'selected' : ''; ?>>Pending</option>
                                                <option value="Approved" <?php echo $status == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="Rejected" <?php echo $status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary px-4">
                                        <i class="bi bi-check-lg me-1"></i><?php echo $isEdit ? 'Update Application' : 'Save Application'; ?>
                                    </button>
                                    <a href="applications.php" class="btn btn-outline-secondary px-4">Cancel</a>
                                </div>

                            </form>

                        <?php endif; ?>
                    </div>
                </div>

                <p class="text-center text-muted small mt-4 mb-0">
                    Scholarship Management Portal &middot; DBMS Mini Project
                </p>

            </div>
        </main>
    </div>
</div>

<!-- ===================== MOBILE MENU ===================== -->
<div class="offcanvas offcanvas-start text-bg-dark" tabindex="-1" id="mobileMenu">
    <div class="offcanvas-header border-bottom border-secondary">
        <h5 class="offcanvas-title"><i class="bi bi-mortarboard-fill me-2"></i>Scholarship Portal</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body px-2">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="students.php"><i class="bi bi-people"></i> Students</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="scholarships.php"><i class="bi bi-award"></i> Scholarships</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sql_lab.php"><i class="bi bi-database"></i> SQL Query Lab</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#"><i class="bi bi-shield-lock"></i> Admins</a>
            </li>
        </ul>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Live eligibility preview - the server re-checks everything on save.
    (function () {
        var studentSel = document.querySelector('select[name="student_id"]');
        var scholarSel = document.querySelector('select[name="scholarship_id"]');
        var banner     = document.getElementById('eligibilityBanner');
        if (!studentSel || !scholarSel || !banner) return;

        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }

        function check() {
            var sid = studentSel.value, scid = scholarSel.value;
            if (!sid || !scid) { banner.className = 'd-none'; banner.innerHTML = ''; return; }
            banner.className = 'mt-2';
            banner.innerHTML = '<div class="alert alert-info d-flex gap-2 py-2 mb-0"><i class="bi bi-hourglass-split"></i><div>Checking eligibility&hellip;</div></div>';
            fetch('eligibility_check.php?student_id=' + encodeURIComponent(sid) + '&scholarship_id=' + encodeURIComponent(scid))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.ok) { banner.className = 'd-none'; return; }
                    var cls   = d.eligible ? 'alert alert-success d-flex gap-2 py-2 mb-0' : 'alert alert-danger d-flex gap-2 py-2 mb-0';
                    var icon  = d.eligible ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                    var extra = '';
                    (d.manual_checks || []).forEach(function (m) { extra += '<div class="small mt-1">' + esc(m) + '</div>'; });
                    banner.innerHTML = '<div class="' + cls + '"><i class="bi ' + icon + '"></i><div>'
                                     + (d.eligible ? '<strong>Eligible:</strong> ' : '<strong>Not eligible:</strong> ')
                                     + esc(d.summary) + extra + '</div></div>';
                })
                .catch(function () { banner.className = 'd-none'; });
        }

        studentSel.addEventListener('change', check);
        scholarSel.addEventListener('change', check);
        check();
    })();
</script>
</body>
</html>
