<?php
// lisani_aos/db_config.php
$lisani_servername = "localhost";
$lisani_db_username = "root";
$lisani_db_password = "";
$lisani_dbname = "lisani_aos_db";

$lisani_conn = new mysqli($lisani_servername, $lisani_db_username, $lisani_db_password, $lisani_dbname);
if ($lisani_conn->connect_error) {
    die("Lisani AOS DB connection failed: " . $lisani_conn->connect_error);
}
$lisani_conn->set_charset("utf8");

if (!function_exists('close_lisani_db_connection')) {
    function close_lisani_db_connection($lisani_conn) {
        if ($lisani_conn) { $lisani_conn->close(); }
    }
}
?>