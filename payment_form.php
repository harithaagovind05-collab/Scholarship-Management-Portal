<?php
/*
|----------------------------------------------------------
| payment_form.php - Add / Edit Payment
| - No "id" in URL  -> Add mode (INSERT)
| - ?id=4 in URL    -> Edit mode (UPDATE)
| The Application dropdown comes from the database (JOIN with
| Student + Scholarship so each option is easy to identify).
|----------------------------------------------------------
*/

session_start();
require_once 'db_connect.php';

// Was the page requested as POST (form submission) or GET (normal view)?
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Allowed payment statuses (must match the ones used in payments.php)
$allowedStatus = ['Pending', 'Completed', 'Failed'];

/* ---------- Which mode are we in? ---------- */
$id     = $_GET['id'] ?? '';
$isEdit = false;

if (ctype_digit($id) && $id > 0) {
    $isEdit = true;

    // Load the existing payment (prepared statement)
    $stmt = $pdo->prepare("SELECT Payment_ID, Application_ID, Amount, Payment_Date, Payment_Status
                           FROM Payment WHERE Payment_ID = :id");
    $stmt->execute([':id' => $id]);
    $payment = $stmt->fetch();

    if (!$payment) {
        $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Payment not found. It may have been deleted.'];
        header('Location: payments.php');
        exit;
    }

    // Pre-fill values (only on first load, not after a failed submit)
    if ($requestMethod != 'POST') {
        $applicationId = $payment['Application_ID'];
        $amount        = $payment['Amount'];
        $paymentDate   = $payment['Payment_Date'];
        $paymentStatus = $payment['Payment_Status'];
    }
} else {
    $id = 0;   // Add mode
}

/* ---------- Default values ---------- */
$applicationId = $applicationId ?? '';
$amount        = $amount        ?? '';
$paymentDate   = $paymentDate   ?? date('Y-m-d');   // default: today
$paymentStatus = $paymentStatus ?? 'Pending';
$errors        = [];

/* ---------- Fetch the Application dropdown (from the database, never hardcoded) ---------- */
$stmt = $pdo->prepare("SELECT a.Application_ID, a.Apply_Date, a.Status,
                              s.Name  AS Student_Name, s.Course,
                              sc.Name AS Scholarship_Name, sc.Amount AS Scholarship_Amount
                       FROM Application a
                       INNER JOIN Student s      ON a.Student_ID     = s.Student_ID
                       INNER JOIN Scholarship sc ON a.Scholarship_ID = sc.Scholarship_ID
                       ORDER BY a.Application_ID DESC");
$stmt->execute();
$applications = $stmt->fetchAll();

/* ---------- Handle form submission ---------- */
if ($requestMethod == 'POST') {

    // 1. Collect input
    $applicationId = trim($_POST['application_id'] ?? '');
    $amount        = trim($_POST['amount']         ?? '');
    $paymentDate   = trim($_POST['payment_date']   ?? '');
    $paymentStatus = trim($_POST['payment_status'] ?? '');

    // 2. Application must be chosen AND must exist in the Application table
    if ($applicationId == '' || !ctype_digit($applicationId)) {
        $errors[] = 'Please select an application.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Application WHERE Application_ID = :id");
        $stmt->execute([':id' => $applicationId]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'The selected application does not exist in the database.';
        }
    }

    // 3. Amount must be a number greater than zero
    if ($amount == '') {
        $errors[] = 'Amount is required.';
    } elseif (!is_numeric($amount)) {
        $errors[] = 'Amount must be a number (example: 25000 or 25000.50).';
    } elseif ((float)$amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    } elseif ((float)$amount > 99999999.99) {
        $errors[] = 'Amount is too large. Maximum allowed is 99,999,999.99.';
    }

    // 4. Payment date must be a real date
    if ($paymentDate == '') {
        $errors[] = 'Payment date is required.';
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$d || $d->format('Y-m-d') !== $paymentDate) {
            $errors[] = 'Payment date must be a valid date.';
        }
    }

    // 5. Payment status must be one of the allowed values
    if (!in_array($paymentStatus, $allowedStatus)) {
        $errors[] = 'Payment status must be Pending, Completed or Failed.';
    }

    // 6. Save using INSERT or UPDATE (prepared statements)
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE Payment
                                       SET Application_ID = :aid, Amount = :amount,
                                           Payment_Date = :pdate, Payment_Status = :pstatus
                                       WHERE Payment_ID = :id");
                $stmt->execute([
                    ':aid'     => $applicationId,
                    ':amount'  => $amount,
                    ':pdate'   => $paymentDate,
                    ':pstatus' => $paymentStatus,
                    ':id'      => $id
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Payment #' . $id . ' updated successfully.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO Payment (Application_ID, Amount, Payment_Date, Payment_Status)
                                       VALUES (:aid, :amount, :pdate, :pstatus)");
                $stmt->execute([
                    ':aid'     => $applicationId,
                    ':amount'  => $amount,
                    ':pdate'   => $paymentDate,
                    ':pstatus' => $paymentStatus
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Payment recorded successfully.'];
            }

            // Redirect back to the list so refresh does not resubmit the form
            header('Location: payments.php');
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                $errors[] = 'Could not save: the selected application is invalid.';
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
    <title><?php echo $isEdit ? 'Edit Payment' : 'Record Payment'; ?> | Scholarship Management Portal</title>

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
                    <a class="nav-link" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
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
                    <h5><?php echo $isEdit ? 'Edit Payment' : 'Record Payment'; ?></h5>
                </div>
                <nav aria-label="breadcrumb" class="d-none d-md-block">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="payments.php" class="text-decoration-none">Payments</a></li>
                        <li class="breadcrumb-item active"><?php echo $isEdit ? 'Edit' : 'Record'; ?></li>
                    </ol>
                </nav>
            </div>

            <div class="px-3 px-md-4">

                <div class="panel mt-4 mx-auto" style="max-width: 720px;">

                    <div class="panel-head d-flex align-items-center justify-content-between">
                        <h6>
                            <i class="bi <?php echo $isEdit ? 'bi-pencil-square' : 'bi-cash-coin'; ?> me-2 text-primary"></i>
                            <?php echo $isEdit ? 'Edit Payment #' . htmlspecialchars($payment['Payment_ID']) : 'New Payment Details'; ?>
                        </h6>
                    </div>

                    <div class="p-4">

                        <!-- Friendly warning if there is nothing to link yet -->
                        <?php if (count($applications) == 0): ?>
                            <div class="alert alert-warning d-flex gap-2" role="alert">
                                <i class="bi bi-exclamation-circle-fill"></i>
                                <div>
                                    You need at least one <strong>application</strong> before recording a payment.
                                    <a href="application_form.php" class="alert-link">Add an application first</a>.
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

                        <?php if (count($applications) > 0): ?>

                            <form method="post" action="<?php echo $isEdit ? 'payment_form.php?id=' . (int)$id : 'payment_form.php'; ?>" novalidate>

                                <div class="mb-3">
                                    <label class="form-label">Application <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-file-earmark-text"></i></span>
                                        <select name="application_id" id="applicationSelect" class="form-select" required>
                                            <option value="">-- Select an application --</option>
                                            <?php foreach ($applications as $app): ?>
                                                <option value="<?php echo (int)$app['Application_ID']; ?>"
                                                        data-amount="<?php echo htmlspecialchars($app['Scholarship_Amount']); ?>"
                                                        <?php echo $applicationId == $app['Application_ID'] ? 'selected' : ''; ?>>
                                                    App #<?php echo (int)$app['Application_ID']; ?>
                                                    &mdash; <?php echo htmlspecialchars($app['Student_Name']); ?>
                                                    <?php echo $app['Course'] != '' ? '(' . htmlspecialchars($app['Course']) . ')' : ''; ?>
                                                    &mdash; <?php echo htmlspecialchars($app['Scholarship_Name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-text">Only applications created in the Applications module are listed here.</div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-cash"></i></span>
                                            <input type="number" name="amount" id="amountInput" class="form-control"
                                                   placeholder="e.g. 25000" min="0.01" step="0.01" required
                                                   value="<?php echo htmlspecialchars($amount); ?>">
                                        </div>
                                        <div class="form-text">Must be greater than zero.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-calendar-event"></i></span>
                                            <input type="date" name="payment_date" class="form-control" required
                                                   value="<?php echo htmlspecialchars($paymentDate); ?>">
                                        </div>
                                        <div class="form-text">Defaults to today's date.</div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">Payment Status</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-flag"></i></span>
                                        <select name="payment_status" class="form-select">
                                            <option value="Pending"   <?php echo $paymentStatus == 'Pending'   ? 'selected' : ''; ?>>Pending</option>
                                            <option value="Completed" <?php echo $paymentStatus == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="Failed"    <?php echo $paymentStatus == 'Failed'    ? 'selected' : ''; ?>>Failed</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary px-4">
                                        <i class="bi bi-check-lg me-1"></i><?php echo $isEdit ? 'Update Payment' : 'Save Payment'; ?>
                                    </button>
                                    <a href="payments.php" class="btn btn-outline-secondary px-4">Cancel</a>
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
                <a class="nav-link" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
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
    // Convenience: if the amount is still empty, suggest the scholarship amount
    // of the selected application (the user can always overwrite it).
    var applicationSelect = document.getElementById('applicationSelect');
    var amountInput       = document.getElementById('amountInput');

    if (applicationSelect && amountInput) {
        applicationSelect.addEventListener('change', function () {
            var chosen = applicationSelect.options[applicationSelect.selectedIndex];
            var suggested = chosen ? chosen.getAttribute('data-amount') : '';

            if (amountInput.value.trim() === '' && suggested) {
                amountInput.value = suggested;
            }
        });
    }
</script>
</body>
</html>
