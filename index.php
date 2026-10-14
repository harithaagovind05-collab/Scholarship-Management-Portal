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
                <li class="nav-item">
                    <a class="nav-link" href="payments.php"><i class="bi bi-cash-coin"></i> Payments</a>
                </li>
                                <li class="nav-item">
                    <a class="nav-link" href="#"><i class="bi bi-shield-lock"></i> Admins</a>
                </li>
            </ul>
        </nav>

        <!-- ==============>
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
