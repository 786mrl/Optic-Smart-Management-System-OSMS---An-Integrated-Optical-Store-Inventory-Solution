<?php
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "optic_pos_db";
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8");
if (!function_exists('close_db_connection')) {
    function close_db_connection($conn) {
        if ($conn) { $conn->close(); }
    }
}
?>