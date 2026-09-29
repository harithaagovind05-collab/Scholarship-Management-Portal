<?php
/*
|----------------------------------------------------------
| index.php - Dashboard (Scholarship Management Portal)
| Shows summary counts + latest 5 applications.
| All data comes live from the MySQL database.
|----------------------------------------------------------
*/

require_once 'db_connect.php';
require_once 'eligibility.php';

/* ---------- Helper: convert a status into a colored badge ---------- */
function statusBadge($status)
{
    $status = trim($status);
    $key    = strtolower($status);

    $class = 'status-other';
    if ($key == 'approved')      $class = 'status-approved';
    elseif ($key == 'pending')   $class = 'status-pending';
    elseif ($key == 'rejected')  $class = 'status-rejected';

    return '<span class="badge badge-status ' . $class . '">'
         . htmlspecialchars($status) . '</span>';
}

/* ---------- 1. Summary counts (live from MySQL) ---------- */

// Total students
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Student");
$stmt->execute();
$totalStudents = $stmt->fetchColumn();

// Total scholarships
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Scholarship");
$stmt->execute();
$totalScholarships = $stmt->fetchColumn();

// Total applications
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Application");
$stmt->execute();
$totalApplications = $stmt->fetchColumn();

// Total payments
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Payment");
$stmt->execute();
$totalPayments = $stmt->fetchColumn();

/* ---------- 2. Latest 5 applications (JOIN of 3 tables) ---------- */
$sql = "SELECT a.Application_ID,
               a.Apply_Date,
               a.Status,
               s.Name  AS Student_Name,
               s.Gender,
               s.Course,
               sc.Name AS Scholarship_Name,
               sc.Amount,
               sc.Eligibility,
               sc.Required_Gender,
               sc.Required_Course_Keywords,
               sc.Deadline
        FROM Application a
        INNER JOIN Student s     ON a.Student_ID     = s.Student_ID
        INNER JOIN Scholarship sc ON a.Scholarship_ID = sc.Scholarship_ID
        ORDER BY a.Apply_Date DESC, a.Application_ID DESC
        LIMIT 5";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$recentApplications = $stmt->fetchAll();

$today = date('l, d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Scholarship Management Portal</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom styles -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- ===================== SIDEBAR (desktop) ===================== -->
<div class="container-fluid">
    <div class="row">

        <nav class="col-lg-2 d-none d-lg-block sidebar px-0">
            <div class="brand">
                <i class="bi bi-mortarboard-fill fs-4"></i> Scholarship Portal
            </div>

            <div class="nav-label">Main</div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" href="index.php">
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
                    <a class="nav-link" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
                </li>
                <li class="nav-item">
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
                    <!-- Mobile menu button -->
                    <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button"
                            data-bs-toggle="offcanvas" data-bs-target="#mobileMenu">
                        <i class="bi bi-list fs-5"></i>
                    </button>
                    <h5>Dashboard</h5>
                </div>
                <span class="text-muted small d-none d-md-inline">
                    <i class="bi bi-calendar3 me-1"></i><?php echo $today; ?>
                </span>
            </div>

            <div class="px-3 px-md-4">

                <!-- Welcome banner -->
                <div class="mt-4 p-4 rounded-3 text-white"
                     style="background: linear-gradient(135deg, #4361ee, #6d5df1);">
                    <h4 class="fw-bold mb-1">Welcome to Scholarship Management Portal</h4>
                    <p class="mb-0 opacity-75">
                        Track students, scholarships, applications and payments — all in one place.
                    </p>
                </div>

                <!-- Summary cards -->
                <div class="row g-3 mt-1">

                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card p-3 d-flex align-items-center gap-3">
                            <div class="icon-box icon-students"><i class="bi bi-people-fill"></i></div>
                            <div>
                                <h3><?php echo $totalStudents; ?></h3>
                                <p>Total Students</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card p-3 d-flex align-items-center gap-3">
                            <div class="icon-box icon-scholarships"><i class="bi bi-award-fill"></i></div>
                            <div>
                                <h3><?php echo $totalScholarships; ?></h3>
                                <p>Scholarships</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card p-3 d-flex align-items-center gap-3">
                            <div class="icon-box icon-applications"><i class="bi bi-file-earmark-text-fill"></i></div>
                            <div>
                                <h3><?php echo $totalApplications; ?></h3>
                                <p>Applications</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card p-3 d-flex align-items-center gap-3">
                            <div class="icon-box icon-payments"><i class="bi bi-cash-coin"></i></div>
                            <div>
                                <h3><?php echo $totalPayments; ?></h3>
                                <p>Payments</p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Recent applications -->
                <div class="panel mt-4">
                    <div class="panel-head d-flex align-items-center justify-content-between">
                        <h6><i class="bi bi-clock-history me-2 text-primary"></i>Recent Applications</h6>
                        <span class="badge bg-primary-subtle text-primary">Latest 5</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Student</th>
                                    <th>Scholarship</th>
                                    <th>Amount</th>
                                    <th>Eligibility</th>
                                    <th>Applied On</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentApplications) > 0): ?>
                                    <?php foreach ($recentApplications as $app): ?>
                                        <tr>
                                            <td class="ps-4 fw-semibold">
                                                #<?php echo htmlspecialchars($app['Application_ID']); ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold">
                                                    <?php echo htmlspecialchars($app['Student_Name']); ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($app['Course']); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars($app['Scholarship_Name']); ?></td>
                                            <td><?php echo number_format($app['Amount']); ?></td>
                                            <td><?php echo eligibilityBadge(checkEligibility($app, $app)); ?></td>
                                            <td><?php echo date('d M Y', strtotime($app['Apply_Date'])); ?></td>
                                            <td><?php echo statusBadge($app['Status']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox me-1"></i> No applications found yet.
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

<!-- ===================== MOBILE MENU ===================== -->
<div class="offcanvas offcanvas-start text-bg-dark" tabindex="-1" id="mobileMenu">
    <div class="offcanvas-header border-bottom border-secondary">
        <h5 class="offcanvas-title"><i class="bi bi-mortarboard-fill me-2"></i>Scholarship Portal</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body px-2">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link active" href="index.php">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
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
                <a class="nav-link" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
            </li>
                <a class="nav-link" href="sql_lab.php"><i class="bi bi-database"></i> SQL Query Lab</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#"><i class="bi bi-shield-lock"></i> Admins</a>
            </li>
        </ul>
    </div>
</div>

<!-- Bootstrap JS (needed for the mobile menu) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
