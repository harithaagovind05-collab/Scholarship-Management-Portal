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
                >
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
                <li cl>
                <li class="nav-item">
                    <a class="nav-link" href="#"><i class="bi bi-shield-lock"></i> Admins</a>
                </li>
            </ul>
        </nav>>
            <li class="nav-item">
                <a class="nav-link" href="students.php"><i class="bi bi-people"></i> Students</a>
            </li>
            <>
            <li class="nav-item">
                <a class="nav-link active" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
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
