<?php
/*
|----------------------------------------------------------
| student_form.php - Add / Edit Student
| - No "id" in URL  -> Add mode (INSERT)
| - ?id=5 in URL    -> Edit mode (UPDATE)
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

    // Load the existing student (prepared statement)
    $stmt = $pdo->prepare("SELECT Student_ID, Name, Gender, DOB, Email, Course
                           FROM Student WHERE Student_ID = :id");
    $stmt->execute([':id' => $id]);
    $student = $stmt->fetch();

    if (!$student) {
        // Unknown id - go back with a message
        $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Student not found. It may have been deleted.'];
        header('Location: students.php');
        exit;
    }

    // Pre-fill values (only on first load, not after a failed submit)
    if ($requestMethod != 'POST') {
        $name   = $student['Name'];
        $gender = $student['Gender'];
        $dob    = $student['DOB'];
        $email  = $student['Email'];
        $course = $student['Course'];
    }
} else {
    $id = 0;   // Add mode
}

/* ---------- Default values ---------- */
$name   = $name   ?? '';
$gender = $gender ?? '';
$dob    = $dob    ?? '';
$email  = $email  ?? '';
$course = $course ?? '';
$errors = [];

/* ---------- Handle form submission ---------- */
if ($requestMethod == 'POST') {

    // 1. Collect and clean input
    $name   = trim($_POST['name']   ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $dob    = trim($_POST['dob']    ?? '');
    $email  = trim($_POST['email']  ?? '');
    $course = trim($_POST['course'] ?? '');

    // 2. Validate required fields
    if ($name == '') {
        $errors[] = 'Name is required.';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Name must be 100 characters or fewer.';
    }

    // 3. Validate email format
    if ($email == '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address (example: student@college.edu).';
    } elseif (mb_strlen($email) > 100) {
        $errors[] = 'Email must be 100 characters or fewer.';
    }

    // 4. Validate DOB (optional, but must be a real past date if given)
    if ($dob != '') {
        $d = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob) {
            $errors[] = 'Date of birth must be a valid date.';
        } elseif ($d > new DateTime('today')) {
            $errors[] = 'Date of birth cannot be in the future.';
        }
    }

    // 5. Course length check (optional field)
    if ($course != '' && mb_strlen($course) > 100) {
        $errors[] = 'Course must be 100 characters or fewer.';
    }

    // 5b. Gender must be one of the allowed values.
    // Gender is used by scholarship eligibility checks (e.g. Girls in STEM),
    // so anything outside the fixed list is rejected.
    $allowedGenders = ['Male', 'Female', 'Other'];
    if ($gender != '' && !in_array($gender, $allowedGenders, true)) {
        $errors[] = 'Gender must be Male, Female or Other.';
    }

    // 6. Duplicate email check (friendly, before hitting the database)
    if (empty($errors) && $email != '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Student
                               WHERE Email = :email AND Student_ID <> :id");
        $stmt->execute([':email' => $email, ':id' => $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'This email is already registered to another student. Please use a different email.';
        }
    }

    // 7. Save using INSERT or UPDATE (prepared statements)
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE Student
                                       SET Name = :name, Gender = :gender, DOB = :dob, Email = :email, Course = :course
                                       WHERE Student_ID = :id");
                $stmt->execute([
                    ':name'   => $name,
                    ':gender' => $gender != '' ? $gender : null,
                    ':dob'    => $dob != '' ? $dob : null,
                    ':email'  => $email,
                    ':course' => $course != '' ? $course : null,
                    ':id'     => $id
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Student "' . $name . '" updated successfully.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO Student (Name, Gender, DOB, Email, Course)
                                       VALUES (:name, :gender, :dob, :email, :course)");
                $stmt->execute([
                    ':name'   => $name,
                    ':gender' => $gender != '' ? $gender : null,
                    ':dob'    => $dob != '' ? $dob : null,
                    ':email'  => $email,
                    ':course' => $course != '' ? $course : null
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Student "' . $name . '" added successfully.'];
            }

            // Redirect back to the list so refresh does not resubmit the form
            header('Location: students.php');
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                // Safety net: UNIQUE email constraint
                $errors[] = 'This email is already registered to another student. Please use a different email.';
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
    <title><?php echo $isEdit ? 'Edit Student' : 'Add Student'; ?> | Scholarship Management Portal</title>

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
                    <a class="nav-link active" href="students.php"><i class="bi bi-people"></i> Students</a>
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
                    <h5><?php echo $isEdit ? 'Edit Student' : 'Add Student'; ?></h5>
                </div>
                <nav aria-label="breadcrumb" class="d-none d-md-block">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="students.php" class="text-decoration-none">Students</a></li>
                        <li class="breadcrumb-item active"><?php echo $isEdit ? 'Edit' : 'Add'; ?></li>
                    </ol>
                </nav>
            </div>

            <div class="px-3 px-md-4">

                <div class="panel mt-4 mx-auto" style="max-width: 720px;">

                    <div class="panel-head d-flex align-items-center justify-content-between">
                        <h6>
                            <i class="bi <?php echo $isEdit ? 'bi-pencil-square' : 'bi-person-plus'; ?> me-2 text-primary"></i>
                            <?php echo $isEdit ? 'Edit Student #' . htmlspecialchars($student['Student_ID']) : 'New Student Details'; ?>
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

                        <form method="post" action="<?php echo $isEdit ? 'student_form.php?id=' . (int)$id : 'student_form.php'; ?>" novalidate>

                            <div class="mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
                                    <input type="text" name="name" class="form-control"
                                           placeholder="e.g. Harithaa S" required
                                           value="<?php echo htmlspecialchars($name); ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Gender</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-gender-ambiguous"></i></span>
                                    <select name="gender" class="form-select">
                                        <option value="" <?php echo $gender == '' ? 'selected' : ''; ?>>Not recorded</option>
                                        <option value="Female" <?php echo $gender == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Male"   <?php echo $gender == 'Male'   ? 'selected' : ''; ?>>Male</option>
                                        <option value="Other"  <?php echo $gender == 'Other'  ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="form-text">Used for gender-based scholarship eligibility (e.g. Girls in STEM). While blank, the student cannot apply for gender-specific scholarships.</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of Birth</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-calendar-event"></i></span>
                                        <input type="date" name="dob" class="form-control"
                                               value="<?php echo htmlspecialchars($dob); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Course</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-journal-bookmark"></i></span>
                                        <input type="text" name="course" class="form-control"
                                               placeholder="e.g. B.Tech CSE"
                                               value="<?php echo htmlspecialchars($course); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" class="form-control"
                                           placeholder="e.g. harithaa@college.edu" required
                                           value="<?php echo htmlspecialchars($email); ?>">
                                </div>
                                <div class="form-text">Must be unique &mdash; two students cannot share the same email.</div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="bi bi-check-lg me-1"></i><?php echo $isEdit ? 'Update Student' : 'Save Student'; ?>
                                </button>
                                <a href="students.php" class="btn btn-outline-secondary px-4">Cancel</a>
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
                <a class="nav-link active" href="students.php"><i class="bi bi-people"></i> Students</a>
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
