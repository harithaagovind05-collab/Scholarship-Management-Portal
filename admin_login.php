<?php
/*============================================================
| admin_login.php - Admin Login with Authentication
| ============================================================
| Checks the username + password against the `admin` table
| (MySQL). A default admin account is created on first run
| if the `admin` table doesn't exist yet.
|
| Login form submits to itself via POST.
| Saves successful login in a session cookie.
|===========================================================
*/

session_start();
require_once 'db_connect.php';

$error = '';
$loggedIn = false;

// ---------- Handle login submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        // Use a prepared statement to prevent SQL injection
        $stmt = $pdo->prepare("SELECT Admin_ID, Admin_Name, Admin_Password FROM admin WHERE Admin_Name = :name LIMIT 1");
        $stmt->execute([':name' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['Admin_Password'])) {
            // Login successful - set session
            $_SESSION['admin_id'] = $admin['Admin_ID'];
            $_SESSION['admin_name'] = $admin['Admin_Name'];
            $loggedIn = true;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

// ---------- If already logged in, redirect to dashboard ----------
if ($loggedIn) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f4f6f8;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            font-family: system-ui;
        }
        .card {
            max-width: 420px;
            width: 100%;
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
        }
        .btn-primary {
            background: #3182ce;
            border: none;
        }
        .btn-primary:hover {
            background: #2b6fa0;
        }
        .error-msg {
            color: #b91c1c;
            font-size: .875rem;
            margin-top: .5rem;
        }
        .login-success {
            color: #15803d;
            font-size: .875rem;
            margin-top: .5rem;
        }
    </style>
</head>
<body>
<div class="card p-4">
    <h4 class="mb-3">Admin Login</h4>

    <?php if ($error): ?>
        <div class="error-msg alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($loggedIn): ?>
        <div class="login-success alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>Logged in successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="post" action="admin_login.php">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100 py-2" type="submit">Login</button>
    </form>

    <p class="mt-3 text-muted small">
        Default admin account is created automatically on first login
        (username: <strong>admin</strong>, password: <strong>admin</strong>).
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
