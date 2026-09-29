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
                    <h5>Payments</h5>
                </div>
                <span class="text-muted small d-none d-md-inline">
                    <i class="bi bi-cash-coin me-1"></i><?php echo count($payments); ?> payment<?php echo count($payments) == 1 ? '' : 's'; ?>
                </span>
            </div>

            <div class="px-3 px-md-4">

                <!-- Flash message (after add / edit / delete) -->
                <?php if ($flash): ?>
                    <div class="mt-4">
                        <?php showAlert($flash['type'], htmlspecialchars($flash['text'])); ?>
                    </div>
                <?php endif; ?>

                <!-- Summary strip -->
                <div class="row g-3 mt-1">
                    <div class="col-sm-6 col-xl-4">
                        <div class="stat-card p-3 d-flex align-items-center gap-3">
                            <div class="icon-box icon-payments"><i class="bi bi-cash-stack"></i></div>
                            <div>
                                <h3><?php echo number_format((float)$totalCollected, 2); ?></h3>
                                <p>Total Collected (Completed)</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-4">
                        <div class="stat-card p-3 d-flex align-items-center gap-3">
                            <div class="icon-box icon-applications"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <h3><?php echo $unpaidApplications; ?></h3>
                                <p>Applications Without Payment</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payments panel -->
                <div class="panel mt-4">

                    <div class="panel-head d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h6><i class="bi bi-cash-coin me-2 text-primary"></i>All Payments</h6>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <!-- Search + status filter -->
                            <form method="get" action="payments.php" class="d-flex flex-wrap gap-2" role="search">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input type="text" name="search" class="form-control"
                                           placeholder="Search student or scholarship..."
                                           value="<?php echo htmlspecialchars($search); ?>" style="min-width: 210px;">
                                </div>
                                <select name="status" class="form-select form-select-sm" style="min-width: 140px;">
                                    <option value="">All statuses</option>
                                    <option value="Pending"   <?php echo $status == 'Pending'   ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Completed" <?php echo $status == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="Failed"    <?php echo $status == 'Failed'    ? 'selected' : ''; ?>>Failed</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            </form>

                            <!-- Record Payment button -->
                            <a href="payment_form.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg me-1"></i>Record Payment
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">Payment ID</th>
                                    <th>Application</th>
                                    <th>Student</th>
                                    <th>Scholarship</th>
                                    <th>Amount</th>
                                    <th>Payment Date</th>
                                    <th>Status</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($payments) > 0): ?>
                                    <?php foreach ($payments as $pay): ?>
                                        <tr>
                                            <td class="ps-4 fw-semibold">
                                                #<?php echo htmlspecialchars($pay['Payment_ID']); ?>
                                            </td>
                                            <td>
                                                <a href="application_form.php?id=<?php echo (int)$pay['Application_ID']; ?>"
                                                   class="text-decoration-none fw-semibold"
                                                   title="Open this application">
                                                    App #<?php echo (int)$pay['Application_ID']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="student-avatar">
                                                        <?php echo htmlspecialchars(mb_strtoupper(mb_substr($pay['Student_Name'], 0, 1))); ?>
                                                    </span>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($pay['Student_Name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($pay['Course'] != '' ? $pay['Course'] : 'Student'); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($pay['Scholarship_Name']); ?></td>
                                            <td class="fw-semibold"><?php echo number_format($pay['Amount'], 2); ?></td>
                                            <td><?php echo $pay['Payment_Date'] ? date('d M Y', strtotime($pay['Payment_Date'])) : '&mdash;'; ?></td>
                                            <td><?php echo paymentStatusBadge($pay['Payment_Status']); ?></td>
                                            <td class="text-end pe-4">
                                                <a href="payment_form.php?id=<?php echo (int)$pay['Payment_ID']; ?>"
                                                   class="btn btn-outline-primary btn-sm me-1" title="Edit">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <button type="button"
                                                        class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                        data-id="<?php echo (int)$pay['Payment_ID']; ?>"
                                                        data-student="<?php echo htmlspecialchars($pay['Student_Name']); ?>"
                                                        data-amount="<?php echo htmlspecialchars(number_format($pay['Amount'], 2)); ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <?php if ($search != '' || $status != ''): ?>
                                                <i class="bi bi-search me-1"></i> No payments match your filters.
                                            <?php else: ?>
                                                <i class="bi bi-inbox me-1"></i> No payments recorded yet. Click "Record Payment" to add the first one.
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
            <form method="post" action="payments.php<?php echo ($search != '' || $status != '') ? '?' . http_build_query(array_filter(['search' => $search, 'status' => $status])) : ''; ?>">
                <div class="modal-body">
                    <p class="mb-0">
                        Delete the payment of
                        <strong id="deletePayAmount"></strong>
                        for <strong id="deletePayStudent"></strong>?
                        This action cannot be undone.
                    </p>
                    <!-- hidden fields for the delete request -->
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="payment_id" id="deletePayId" value="">
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
