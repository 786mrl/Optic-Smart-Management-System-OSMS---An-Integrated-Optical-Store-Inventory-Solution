<?php
// db_config.php
$servername = "localhost";
$db_username = "root"; // Default XAMPP username
$db_password = "";     // Default XAMPP password
$dbname = "optic_pos_db";

// Create connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Set character set to UTF8 for proper handling of all characters
$conn->set_charset("utf8");

// Function to safely close connection
function close_db_connection($conn) {
    if ($conn) {
        $conn->close();
    }
}
?>