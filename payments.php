<?php
/*
|----------------------------------------------------------
| payments.php - Payments Management Module
| Lists payments (JOIN with Application + Student + Scholarship),
| supports search, status filter and delete.
| Add/Edit is handled by payment_form.php
|----------------------------------------------------------
*/

session_start();
require_once 'db_connect.php';

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

/* ---------- Helper: payment status badge (reuses the existing badge styles) ---------- */
function paymentStatusBadge($status)
{
    $status = trim($status);
    $key    = strtolower($status);

    // Map payment statuses onto the existing badge colours
    $class = 'status-other';
    if ($key == 'completed')     $class = 'status-approved';   // green
    elseif ($key == 'pending')   $class = 'status-pending';    // amber
    elseif ($key == 'failed')    $class = 'status-rejected';   // red

    return '<span class="badge badge-status ' . $class . '">'
         . htmlspecialchars($status != '' ? $status : 'Unknown') . '</span>';
}

/* ---------- Helper: redirect keeping the current filters ---------- */
function redirectWithFilters($search, $status)
{
    $params = [];
    if ($search != '') $params['search'] = $search;
    if ($status != '') $params['status'] = $status;
    header('Location: payments.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

/* ---------- Read the current filters (used by handlers too) ---------- */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

/* ---------- 1. Handle DELETE (with confirmation via modal) ---------- */
if ($requestMethod == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {

    $id = $_POST['payment_id'] ?? '';

    if (ctype_digit($id) && $id > 0) {
        try {
            // Prepared statement - safe from SQL injection
            $stmt = $pdo->prepare("DELETE FROM Payment WHERE Payment_ID = :id");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Payment #' . $id . ' deleted successfully.'];
            } else {
                $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Payment not found. It may have already been deleted.'];
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                $_SESSION['flash'] = ['type' => 'danger',
                    'text' => 'This payment cannot be deleted because other records are linked to it.'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Something went wrong while deleting. Please try again.'];
            }
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Invalid delete request.'];
    }

    // Redirect so refresh does not repeat the delete
    redirectWithFilters($search, $status);
}

/* ---------- 2. Fetch flash message from the form page ---------- */
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ---------- 3. Fetch payments (JOIN of 4 tables) + filters ---------- */
$where  = [];
$params = [];

if ($search != '') {
    // Search by student name OR scholarship name
    $where[]       = "(s.Name LIKE :kw OR sc.Name LIKE :kw)";
    $params[':kw'] = '%' . $search . '%';
}
if ($status != '') {
    $where[]           = "p.Payment_Status = :status";
    $params[':status'] = $status;
}

$sql = "SELECT p.Payment_ID, p.Application_ID, p.Amount, p.Payment_Date, p.Payment_Status,
               s.Name  AS Student_Name, s.Course,
               sc.Name AS Scholarship_Name, sc.Amount AS Scholarship_Amount
        FROM Payment p
        INNER JOIN Application a  ON p.Application_ID  = a.Application_ID
        INNER JOIN Student s      ON a.Student_ID      = s.Student_ID
        INNER JOIN Scholarship sc ON a.Scholarship_ID  = sc.Scholarship_ID";

if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY p.Payment_Date DESC, p.Payment_ID DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

/* ---------- 4. Totals (live from MySQL, respecting the current filters) ---------- */
$collectedSql = "SELECT COALESCE(SUM(p.Amount), 0)
                 FROM Payment p
                 INNER JOIN Application a  ON p.Application_ID  = a.Application_ID
                 INNER JOIN Student s      ON a.Student_ID      = s.Student_ID
                 INNER JOIN Scholarship sc ON a.Scholarship_ID  = sc.Scholarship_ID";

$collectedWhere  = $where;
$collectedParams = $params;
$collectedWhere[] = "p.Payment_Status = 'Completed'";

$collectedSql .= " WHERE " . implode(" AND ", $collectedWhere);
$stmt = $pdo->prepare($collectedSql);
$stmt->execute($collectedParams);
$totalCollected = $stmt->fetchColumn();

/* ---------- 5. Applications with no payment yet (for the quick count note) ---------- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Application a
                       WHERE NOT EXISTS (SELECT 1 FROM Payment p WHERE p.Application_ID = a.Application_ID)");
$stmt->execute();
$unpaidApplications = $stmt->fetchColumn();

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payments | Scholarship Management Portal</title>

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
                    <a class="nav-link" href="applications.php"><i class="bi bi-file-earmark-text"></i> Applications</a>
                </li>
                <li class="na>
                <li class="nav-item">
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#"><i class="bi bi-shield-lock"></i>
            </ul>
        </nav>

        <!-- ===================== MAIN CONTENT ===================== -->
        <main class="col-lg-10 ms-sm-auto px-0 pb-4">

       >
            <li class="nav-item">
                <a class>
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
                <a class="nav-link" href="#"><i class="bi bi-shield-lock"></i> Admins</a>
            </li>
        </ul>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Fill the delete modal with the clicked payment's details
    var deleteModal = document.getElementById('deleteModal');
    deleteModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        document.getElementById('deletePayId').value          = button.getAttribute('data-id');
        document.getElementById('deletePayStudent').textContent = button.getAttribute('data-student');
        document.getElementById('deletePayAmount').textContent  = button.getAttribute('data-amount');
    });
</script>
</body>
</html>
