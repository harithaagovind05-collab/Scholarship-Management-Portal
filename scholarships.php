<?php
/*
|----------------------------------------------------------
| scholarships.php - Scholarships Management Module
| Lists all scholarships, supports search and delete.
| Add/Edit is handled by scholarship_form.php
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

/* ---------- 1. Handle DELETE (with confirmation via modal) ---------- */
if ($requestMethod == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {

    $id = $_POST['scholarship_id'] ?? '';

    if (ctype_digit($id) && $id > 0) {
        try {
            // Prepared statement - safe from SQL injection
            $stmt = $pdo->prepare("DELETE FROM Scholarship WHERE Scholarship_ID = :id");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Scholarship deleted successfully.'];
            } else {
                $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Scholarship not found. It may have already been deleted.'];
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                // Foreign key constraint: this scholarship has applications linked
                $_SESSION['flash'] = ['type' => 'danger',
                    'text' => 'This scholarship cannot be deleted because one or more applications are linked to it.'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Something went wrong while deleting. Please try again.'];
            }
        }
    }

    // Redirect so refresh does not repeat the delete
    header('Location: scholarships.php');
    exit;
}

/* ---------- 2. Fetch flash message from the form page ---------- */
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ---------- 3. Search scholarships (Name or Eligibility) ---------- */
$search = trim($_GET['search'] ?? '');
$today  = date('Y-m-d');

if ($search != '') {
    $sql = "SELECT Scholarship_ID, Name, Amount, Eligibility, Required_Gender,
                   Required_Course_Keywords, Deadline
            FROM Scholarship
            WHERE Name LIKE :kw OR Eligibility LIKE :kw
            ORDER BY Name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':kw' => '%' . $search . '%']);
} else {
    $sql  = "SELECT Scholarship_ID, Name, Amount, Eligibility, Required_Gender,
                   Required_Course_Keywords, Deadline
             FROM Scholarship
             ORDER BY Name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}

$scholarships = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scholarships | Scholarship Management Portal</title>

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
                    <h5>Scholarships</h5>
                </div>
                <span class="text-muted small d-none d-md-inline">
                    <i class="bi bi-award me-1"></i><?php echo count($scholarships); ?> scholarship<?php echo count($scholarships) == 1 ? '' : 's'; ?>
                </span>
            </div>

            <div class="px-3 px-md-4">

                <!-- Flash message (after add / edit / delete) -->
                <?php if ($flash): ?>
                    <div class="mt-4">
                        <?php showAlert($flash['type'], htmlspecialchars($flash['text'])); ?>
                    </div>
                <?php endif; ?>

                <!-- Scholarships panel -->
                <div class="panel mt-4">

                    <div class="panel-head d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h6><i class="bi bi-award me-2 text-primary"></i>All Scholarships</h6>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <!-- Search box -->
                            <form method="get" action="scholarships.php" class="d-flex" role="search">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input type="text" name="search" class="form-control"
                                           placeholder="Search name or eligibility..."
                                           value="<?php echo htmlspecialchars($search); ?>" style="min-width: 220px;">
                                    <button type="submit" class="btn btn-primary">Search</button>
                                </div>
                            </form>

                            <!-- Add Scholarship button -->
                            <a href="scholarship_form.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg me-1"></i>Add Scholarship
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Scholarship</th>
                                    <th>Amount</th>
                                    <th>Eligibility</th>
                                    <th>Deadline</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($scholarships) > 0): ?>
                                    <?php foreach ($scholarships as $sch): ?>
                                        <tr>
                                            <td class="ps-4 fw-semibold">
                                                #<?php echo htmlspecialchars($sch['Scholarship_ID']); ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="student-avatar">
                                                        <i class="bi bi-award-fill"></i>
                                                    </span>
                                                    <span class="fw-semibold"><?php echo htmlspecialchars($sch['Name']); ?></span>
                                                </div>
                                            </td>
                                            <td class="fw-semibold"><?php echo number_format($sch['Amount'], 2); ?></td>
                                            <td>
                                                <?php echo $sch['Eligibility'] != '' ? htmlspecialchars($sch['Eligibility']) : '&mdash;'; ?>
                                                <?php if ($sch['Required_Gender'] != '' || $sch['Required_Course_Keywords'] != ''): ?>
                                                    <div class="mt-1">
                                                        <?php if ($sch['Required_Gender'] != ''): ?>
                                                            <span class="badge bg-info-subtle text-info-emphasis"><?php echo htmlspecialchars($sch['Required_Gender']); ?> only</span>
                                                        <?php endif; ?>
                                                        <?php if ($sch['Required_Course_Keywords'] != ''): ?>
                                                            <span class="badge bg-secondary-subtle text-secondary">Courses: <?php echo htmlspecialchars($sch['Required_Course_Keywords']); ?></span>
                                                        <?php endif; ?>
                                                        <span class="badge bg-success-subtle text-success">auto-enforced</span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($sch['Deadline']): ?>
                                                    <span class="<?php echo $sch['Deadline'] < $today ? 'text-danger fw-semibold' : ''; ?>">
                                                        <?php echo date('d M Y', strtotime($sch['Deadline'])); ?>
                                                        <?php echo $sch['Deadline'] < $today ? '<small class="d-block text-muted">expired</small>' : ''; ?>
                                                    </span>
                                                <?php else: ?>
                                                    &mdash;
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <a href="scholarship_form.php?id=<?php echo (int)$sch['Scholarship_ID']; ?>"
                                                   class="btn btn-outline-primary btn-sm me-1" title="Edit">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <button type="button"
                                                        class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                        data-id="<?php echo (int)$sch['Scholarship_ID']; ?>"
                                                        data-name="<?php echo htmlspecialchars($sch['Name']); ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <?php if ($search != ''): ?>
                                                <i class="bi bi-search me-1"></i>
                                                No scholarships found for "<strong><?php echo htmlspecialchars($search); ?></strong>".
                                            <?php else: ?>
                                                <i class="bi bi-inbox me-1"></i> No scholarships added yet. Click "Add Scholarship" to create the first one.
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
            <form method="post" action="scholarships.php">
                <div class="modal-body">
                    <p class="mb-0">
                        Are you sure you want to delete the scholarship
                        <strong id="deleteScholarshipName"></strong>?
                        This action cannot be undone.
                    </p>
                    <!-- hidden fields for the delete request -->
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="scholarship_id" id="deleteScholarshipId" value="">
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
<script>
    // Fill the delete modal with the clicked scholarship's details
    var deleteModal = document.getElementById('deleteModal');
    deleteModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        document.getElementById('deleteScholarshipId').value = button.getAttribute('data-id');
        document.getElementById('deleteScholarshipName').textContent = button.getAttribute('data-name');
    });
</script>
</body>
</html>
