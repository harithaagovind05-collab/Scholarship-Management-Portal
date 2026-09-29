<?php
/*
|----------------------------------------------------------
| scholarship_form.php - Add / Edit Scholarship
| - No "id" in URL  -> Add mode (INSERT)
| - ?id=3 in URL    -> Edit mode (UPDATE)
|----------------------------------------------------------
*/

session_start();
require_once 'db_connect.php';

// Was the page requested as POST (form submission) or GET (normal view)?
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- Which mode are we in? ---------- */
$id     = $_GET['id'] ?? '';
$isEdit = false;

if (ctype_digit($id) && $id > 0) {
    $isEdit = true;

    // Load the existing scholarship (prepared statement)
    $stmt = $pdo->prepare("SELECT Scholarship_ID, Name, Amount, Eligibility,
                                  Required_Gender, Required_Course_Keywords, Deadline
                           FROM Scholarship WHERE Scholarship_ID = :id");
    $stmt->execute([':id' => $id]);
    $scholarship = $stmt->fetch();

    if (!$scholarship) {
        // Unknown id - go back with a message
        $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Scholarship not found. It may have been deleted.'];
        header('Location: scholarships.php');
        exit;
    }

    // Pre-fill values (only on first load, not after a failed submit)
    if ($requestMethod != 'POST') {
        $name        = $scholarship['Name'];
        $amount      = $scholarship['Amount'];
        $eligibility = $scholarship['Eligibility'];
        $requiredGender = $scholarship['Required_Gender'];
        $requiredCourses = $scholarship['Required_Course_Keywords'];
        $deadline    = $scholarship['Deadline'];
    }
} else {
    $id = 0;   // Add mode
}

/* ---------- Default values ---------- */
$name        = $name        ?? '';
$amount      = $amount      ?? '';
$eligibility = $eligibility ?? '';
$requiredGender = $requiredGender ?? '';
$requiredCourses = $requiredCourses ?? '';
$deadline    = $deadline    ?? '';
$errors      = [];

/* ---------- Handle form submission ---------- */
if ($requestMethod == 'POST') {

    // 1. Collect and clean input
    $name        = trim($_POST['name']        ?? '');
    $amount      = trim($_POST['amount']      ?? '');
    $eligibility = trim($_POST['eligibility'] ?? '');
    $requiredGender = trim($_POST['required_gender'] ?? '');
    $requiredCourses = trim($_POST['required_course_keywords'] ?? '');
    $deadline    = trim($_POST['deadline']    ?? '');

    // 2. Validate Name (required, max 150 characters)
    if ($name == '') {
        $errors[] = 'Scholarship name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Scholarship name must be 150 characters or fewer.';
    }

    // 3. Validate Amount (required, must be a number greater than zero)
    if ($amount == '') {
        $errors[] = 'Amount is required.';
    } elseif (!is_numeric($amount)) {
        $errors[] = 'Amount must be a number (example: 25000 or 25000.50).';
    } elseif ((float)$amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    } elseif ((float)$amount > 99999999.99) {
        $errors[] = 'Amount is too large. Maximum allowed is 99,999,999.99.';
    }

    // 4. Validate Eligibility (optional, max 255 characters)
    if ($eligibility != '' && mb_strlen($eligibility) > 255) {
        $errors[] = 'Eligibility must be 255 characters or fewer.';
    }

    // 4b. Validate machine-checkable criteria (optional fields)
    $allowedReqGenders = ['', 'Female', 'Male', 'Other'];
    if (!in_array($requiredGender, $allowedReqGenders, true)) {
        $errors[] = 'Required gender must be Female, Male or Other (or left empty for no check).';
    }
    if (mb_strlen($requiredCourses) > 255) {
        $errors[] = 'Required course keywords must be 255 characters or fewer.';
    }

    // 5. Validate Deadline (optional, but must be a real date if given)
    if ($deadline != '') {
        $d = DateTime::createFromFormat('Y-m-d', $deadline);
        if (!$d || $d->format('Y-m-d') !== $deadline) {
            $errors[] = 'Deadline must be a valid date.';
        }
    }

    // 6. Save using INSERT or UPDATE (prepared statements)
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE Scholarship
                                       SET Name = :name, Amount = :amount, Eligibility = :eligibility,
                                           Required_Gender = :reqgender,
                                           Required_Course_Keywords = :reqcourses,
                                           Deadline = :deadline
                                       WHERE Scholarship_ID = :id");
                $stmt->execute([
                    ':name'        => $name,
                    ':amount'      => $amount,
                    ':eligibility' => $eligibility != '' ? $eligibility : null,
                    ':reqgender'   => $requiredGender != '' ? $requiredGender : null,
                    ':reqcourses'  => $requiredCourses != '' ? $requiredCourses : null,
                    ':deadline'    => $deadline != '' ? $deadline : null,
                    ':id'          => $id
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Scholarship "' . $name . '" updated successfully.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO Scholarship (Name, Amount, Eligibility,
                                                               Required_Gender, Required_Course_Keywords, Deadline)
                                       VALUES (:name, :amount, :eligibility, :reqgender, :reqcourses, :deadline)");
                $stmt->execute([
                    ':name'        => $name,
                    ':amount'      => $amount,
                    ':eligibility' => $eligibility != '' ? $eligibility : null,
                    ':reqgender'   => $requiredGender != '' ? $requiredGender : null,
                    ':reqcourses'  => $requiredCourses != '' ? $requiredCourses : null,
                    ':deadline'    => $deadline != '' ? $deadline : null
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Scholarship "' . $name . '" added successfully.'];
            }

            // Redirect back to the list so refresh does not resubmit the form
            header('Location: scholarships.php');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $isEdit ? 'Edit Scholarship' : 'Add Scholarship'; ?> | Scholarship Management Portal</title>

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
                    <a class="nav-link active" href="scholarships.php"><i class="bi bi-award"></i> Scholarships</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
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
                    <h5><?php echo $isEdit ? 'Edit Scholarship' : 'Add Scholarship'; ?></h5>
                </div>
                <nav aria-label="breadcrumb" class="d-none d-md-block">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="scholarships.php" class="text-decoration-none">Scholarships</a></li>
                        <li class="breadcrumb-item active"><?php echo $isEdit ? 'Edit' : 'Add'; ?></li>
                    </ol>
                </nav>
            </div>

            <div class="px-3 px-md-4">

                <div class="panel mt-4 mx-auto" style="max-width: 720px;">

                    <div class="panel-head d-flex align-items-center justify-content-between">
                        <h6>
                            <i class="bi <?php echo $isEdit ? 'bi-pencil-square' : 'bi-award'; ?> me-2 text-primary"></i>
                            <?php echo $isEdit ? 'Edit Scholarship #' . htmlspecialchars($scholarship['Scholarship_ID']) : 'New Scholarship Details'; ?>
                        </h6>
                    </div>

                    <div class="p-4">

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

                        <form method="post" action="<?php echo $isEdit ? 'scholarship_form.php?id=' . (int)$id : 'scholarship_form.php'; ?>" novalidate>

                            <div class="mb-3">
                                <label class="form-label">Scholarship Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-award"></i></span>
                                    <input type="text" name="name" class="form-control"
                                           placeholder="e.g. Merit Scholarship 2026" required
                                           value="<?php echo htmlspecialchars($name); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-cash"></i></span>
                                        <input type="number" name="amount" class="form-control"
                                               placeholder="e.g. 25000" min="0.01" step="0.01" required
                                               value="<?php echo htmlspecialchars($amount); ?>">
                                    </div>
                                    <div class="form-text">Must be greater than zero.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Application Deadline</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-calendar-event"></i></span>
                                        <input type="date" name="deadline" class="form-control"
                                               value="<?php echo htmlspecialchars($deadline); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Eligibility Criteria</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white align-items-start pt-2"><i class="bi bi-ui-checks"></i></span>
                                    <textarea name="eligibility" class="form-control" rows="3"
                                              placeholder="e.g. CGPA above 8.0, family income below 3 LPA"
                                              maxlength="255"><?php echo htmlspecialchars($eligibility); ?></textarea>
                                </div>
                                <div class="form-text">Optional &mdash; describe who can apply (max 255 characters).</div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-5 mb-3">
                                    <label class="form-label">Required Gender <span class="text-muted small">(auto-checked)</span></label>
                                    <select name="required_gender" class="form-select">
                                        <option value="" <?php echo $requiredGender == '' ? 'selected' : ''; ?>>Any gender (no check)</option>
                                        <option value="Female" <?php echo $requiredGender == 'Female' ? 'selected' : ''; ?>>Female only</option>
                                        <option value="Male"   <?php echo $requiredGender == 'Male'   ? 'selected' : ''; ?>>Male only</option>
                                        <option value="Other"  <?php echo $requiredGender == 'Other'  ? 'selected' : ''; ?>>Other only</option>
                                    </select>
                                    <div class="form-text">The server blocks applications that fail this rule.</div>
                                </div>
                                <div class="col-md-7 mb-3">
                                    <label class="form-label">Required Course Keywords <span class="text-muted small">(auto-checked)</span></label>
                                    <input type="text" name="required_course_keywords" class="form-control"
                                           placeholder="e.g. B.E, B.Tech"
                                           value="<?php echo htmlspecialchars($requiredCourses); ?>">
                                    <div class="form-text">Comma-separated course name fragments. Empty = no automatic course check.</div>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="bi bi-check-lg me-1"></i><?php echo $isEdit ? 'Update Scholarship' : 'Save Scholarship'; ?>
                                </button>
                                <a href="scholarships.php" class="btn btn-outline-secondary px-4">Cancel</a>
                            </div>

                        </form>
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
                <a class="nav-link active" href="scholarships.php"><i class="bi bi-award"></i> Scholarships</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
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
</body>
</html>
