<?php
/*
|----------------------------------------------------------
| db_connect.php
| Reusable database connection file (PDO)
| Include this at the top of every page:
|     require_once 'db_connect.php';
|----------------------------------------------------------
*/

// ----- Database details (XAMPP defaults) -----
$host     = "localhost";
$dbname   = "scholarship management";   // your database (name contains a space)
$username = "root";
$password = "";   // blank password for XAMPP

try {
    // Create a new PDO connection
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    // Show errors as exceptions (helps during development)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch rows as associative arrays by default
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Connection failed - stop the page and show a clean message
    die("Database connection failed: " . $e->getMessage());
}
?>
