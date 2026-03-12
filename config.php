

<?php

$host = "localhost";          // Hostinger MySQL host (usually 'localhost')
$db   = " ";  // CHANGE: your database name
$user = " ";  // CHANGE: your database username
$pass = " ";  // CHANGE: your database password

$mysqli = new mysqli($host, $user, $pass, $db);

// Check connection
if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

// For backward compatibility with older code using $conn
$conn = $mysqli;

// Optional: set charset to utf8mb4 for emojis, multilingual text
$mysqli->set_charset("utf8mb4");
?>

