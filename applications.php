<?php
/*
|----------------------------------------------------------
| applications.php - Applications Management Module
| Lists applications (JOIN with Student + Scholarship),
| supports search, status filter, quick status update
| and delete. Add/Edit is handled by application_form.php
|----------------------------------------------------------
*/

session_start();
require_once 'db_connect.php';
require_once 'eligibility.php';

// Was the page requested as POST (form submission) or GET (normal view)?
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- Helper: show a colored alert box ---------- */
function showAlert($type, $text)
{
    $icon = 'bi-info-circle-fill';
    if ($type == 'success') $icon = 'bi-check-circle-fill';
    elseif ($type == 'danger')  $icon = 'bi-exclamation-triangle-fill';
    elseif ($type == 'warning') $icon = 'bi-exclamation-circle-fill';

    echo '<div class="alert alert-' . $type . ' alert-dismissible fade show d-flex align-items-center gap-2" role="alert">';
    echo '<i class="bi ' . $icon . '"></i><div>' . $text . '</div>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
}

/* ---------- Helper: redirect keeping the current filters ---------- */
function redirectWithFilters($search, $status)
{
    $params = [];
    if ($search != '') $params['search'] = $search;
    if ($status != '') $params['status'] = $status;
    header('Location: applications.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

/* ---------- Read the current filters (used by handlers too) ---------- */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

/* ---------- 1. Handle DELETE (with confirmation via modal) ---------- */
if ($requestMethod == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {

    $id = $_POST['application_id'] ?? '';

    if (ctype_digit($id) && $id > 0) {
        try {
            // Prepared statement - safe from SQL injection
            $stmt = $pdo->prepare("DELETE FROM Application WHERE Application_ID = :id");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Application #' . $id . ' deleted successfully.'];
            } else {
                $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Application not found. It may have already been deleted.'];
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                // Foreign key constraint: payments are linked to this application
                $_SESSION['flash'] = ['type' => 'danger',
                    'text' => 'This application cannot be deleted because one or more payments are linked to it.'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Something went wrong while deleting. Please try again.'];
            }
        }
    }

    redirectWithFilters($search, $status);
}

/* ---------- 2. Handle quick STATUS UPDATE (dropdown inside the table) ---------- */
if ($requestMethod == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {

    $id     = $_POST['application_id'] ?? '';
    $newSt  = trim($_POST['new_status'] ?? '');
    $allowed = ['Pending', 'Approved', 'Rejected'];

    if (ctype_digit($id) && $id > 0 && in_array($newSt, $allowed)) {

        // Check the row exists first. rowCount() alone cannot tell us:
        // MySQL reports 0 changed rows when the new value equals the old one,
        // which would wrongly look like a missing record.
        $stmt = $pdo->prepare("SELECT Status, Student_ID, Scholarship_ID FROM Application WHERE Application_ID = :id");
        $stmt->execute([':id' => $id]);
        $appRow         = $stmt->fetch();
        $existingStatus = $appRow === false ? false : $appRow['Status'];

        if ($existingStatus === false) {
            $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Application not found. It may have already been deleted.'];
        } elseif (trim($existingStatus) === $newSt) {
            $_SESSION['flash'] = ['type' => 'info', 'text' => 'Application #' . $id . ' is already marked as "' . $newSt . '" - no change was needed.'];
        } else {
            // Eligibility guard: an application that fails the scholarship's
            // stored criteria can only be marked Rejected - never Approved.
            $elig = checkEligibilityByIds($pdo, $appRow['Student_ID'], $appRow['Scholarship_ID']);

            if ($elig !== null && !$elig['eligible'] && $newSt !== 'Rejected') {
                $_SESSION['flash'] = ['type' => 'danger',
                    'text' => 'Application #' . $id . ' is NOT eligible for this scholarship. '
                            . implode(' ', $elig['blockers'])
                            . ' Only "Rejected" is allowed for it.'];
            } else {
                $stmt = $pdo->prepare("UPDATE Application SET Status = :status WHERE Application_ID = :id");
                $stmt->execute([':status' => $newSt, ':id' => $id]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Application #' . $id . ' status updated to "' . $newSt . '".'];
            }
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Invalid status update request.'];
    }

    redirectWithFilters($search, $status);
}

/* ---------- 3. Fetch flash message from the form page ---------- */
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ---------- 4. Fetch applications (JOIN of 3 tables) + filters ---------- */
$where  = [];
$params = [];

if ($search != '') {
    $where[]  = "(s.Name LIKE :kw OR sc.Name LIKE :kw)";
    $params[':kw'] = '%' . $search . '%';
}
if ($status != '') {
    $where[]  = "a.Status = :status";
    $params[':status'] = $status;
}

$sql = "SELECT a.Application_ID, a.Student_ID, a.Scholarship_ID, a.Apply_Date, a.Status,
               s.Name AS Student_Name, s.Gender, s.Course,
               sc.Name AS Scholarship_Name, sc.Eligibility,
               sc.Required_Gender, sc.Required_Course_Keywords, sc.Deadline
        FROM Application a
        INNER JOIN Student s     ON a.Student_ID     = s.Student_ID
        INNER JOIN Scholarship sc ON a.Scholarship_ID = sc.Scholarship_ID";

if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY a.Apply_Date DESC, a.Application_ID DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Applications | Scholarship Management Portal</title>

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
                    <h5>Applications</h5>
                </div>
                <span class="text-muted small d-none d-md-inline">
                    <i class="bi bi-file-earmark-text me-1"></i><?php echo count($applications); ?> application<?php echo count($applications) == 1 ? '' : 's'; ?>
                </span>
            </div>

            <div class="px-3 px-md-4">

                <!-- Flash message (after add / update / delete) -->
                <?php if ($flash): ?>
                    <div class="mt-4">
                        <?php showAlert($flash['type'], htmlspecialchars($flash['text'])); ?>
                    </div>
                <?php endif; ?>

                <!-- Applications panel -->
                <div class="panel mt-4">

                    <div class="panel-head d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h6><i class="bi bi-file-earmark-text me-2 text-primary"></i>All Applications</h6>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <!-- Search + status filter -->
                            <form method="get" action="applications.php" class="d-flex flex-wrap gap-2" role="search">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input type="text" name="search" class="form-control"
                                           placeholder="Search student or scholarship..."
                                           value="<?php echo htmlspecialchars($search); ?>" style="min-width: 210px;">
                                </div>
                                <select name="status" class="form-select form-select-sm" style="min-width: 130px;">
                                    <option value="">All statuses</option>
                                    <option value="Pending"   <?php echo $status == 'Pending'   ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Approved"  <?php echo $status == 'Approved'  ? 'selected' : ''; ?>>Approved</option>
                                    <option value="Rejected"  <?php echo $status == 'Rejected'  ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            </form>

                            <!-- Add Application button -->
                            <a href="application_form.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg me-1"></i>Add Application
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Student</th>
                                    <th>Scholarship</th>
                                    <th>Eligibility</th>
                                    <th>Applied On</th>
                                    <th>Status</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($applications) > 0): ?>
                                    <?php foreach ($applications as $app):
                                        // Same engine the backend enforces on save -
                                        // this badge always matches the enforcement result.
                                        $eligResult = checkEligibility($app, $app); ?>
                                        <tr>
                                            <td class="ps-4 fw-semibold">
                                                #<?php echo htmlspecialchars($app['Application_ID']); ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="student-avatar">
                                                        <?php echo htmlspecialchars(mb_strtoupper(mb_substr($app['Student_Name'], 0, 1))); ?>
                                                    </span>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($app['Student_Name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($app['Course'] != '' ? $app['Course'] : 'Student #' . $app['Student_ID']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($app['Scholarship_Name']); ?></td>
                                            <td><?php echo eligibilityBadge($eligResult); ?></td>
                                            <td><?php echo $app['Apply_Date'] ? date('d M Y', strtotime($app['Apply_Date'])) : '&mdash;'; ?></td>

                                            <!-- Quick status update: change the dropdown and it saves instantly -->
                                            <td>
                                                <form method="post" action="applications.php<?php echo ($search != '' || $status != '') ? '?' . http_build_query(array_filter(['search' => $search, 'status' => $status])) : ''; ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="application_id" value="<?php echo (int)$app['Application_ID']; ?>">
                                                    <?php
                                                    $current = trim($app['Status']) != '' ? $app['Status'] : 'Pending';
                                                    $known   = in_array($current, ['Pending', 'Approved', 'Rejected']);
                                                    ?>
                                                    <select name="new_status" class="status-select <?php echo 'status-' . strtolower($known ? $current : 'other'); ?>"
                                                            onchange="this.form.submit()"
                                                            title="Change status">
                                                        <?php if (!$known): ?>
                                                            <option value="<?php echo htmlspecialchars($current); ?>" selected><?php echo htmlspecialchars($current); ?></option>
                                                        <?php endif; ?>
                                                        <option value="Pending"  <?php echo $current == 'Pending'  ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="Approved" <?php echo $current == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                                        <option value="Rejected" <?php echo $current == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                    </select>
                                                    <noscript><button type="submit" class="btn btn-sm btn-outline-primary ms-1">Save</button></noscript>
                                                </form>
                                            </td>

                                            <td class="text-end pe-4">
                                                <a href="application_form.php?id=<?php echo (int)$app['Application_ID']; ?>"
                                                   class="btn btn-outline-primary btn-sm me-1" title="Edit">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <button type="button"
                                                        class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                        data-id="<?php echo (int)$app['Application_ID']; ?>"
                                                        data-student="<?php echo htmlspecialchars($app['Student_Name']); ?>"
                                                        data-scholarship="<?php echo htmlspecialchars($app['Scholarship_Name']); ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <?php if ($search != '' || $status != ''): ?>
                                                <i class="bi bi-search me-1"></i> No applications match your filters.
                                            <?php else: ?>
                                                <i class="bi bi-inbox me-1"></i> No applications yet. Click "Add Application" to create the first one.
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="text-center text-muted small mt-4 mb-0">
                    Scholarship Management Portal &middot; DBMS Mini Project
                </p>

            </div>
        </main>
    </div>
</div>

<!-- ===================== DELETE CONFIRMATION MODAL ===================== -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="applications.php<?php echo ($search != '' || $status != '') ? '?' . http_build_query(array_filter(['search' => $search, 'status' => $status])) : ''; ?>">
                <div class="modal-body">
                    <p class="mb-0">
                        Delete the application of
                        <strong id="deleteAppStudent"></strong>
                        for <strong id="deleteAppScholarship"></strong>?
                        This action cannot be undone.
                    </p>
                    <!-- hidden fields for the delete request -->
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="application_id" id="deleteAppId" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Yes, Delete
                    </button>
                </div>
            </form>
        </div>
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
    // Fill the delete modal with the clicked application's details
    var deleteModal = document.getElementById('deleteModal');
    deleteModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        document.getElementById('deleteAppId').value          = button.getAttribute('data-id');
        document.getElementById('deleteAppStudent').textContent     = button.getAttribute('data-student');
        document.getElementById('deleteAppScholarship').textContent = button.getAttribute('data-scholarship');
    });
</script>
</body>
</html>
