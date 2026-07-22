<?php
// auth_check.php
if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
    header("Location: login.php");
    exit();
}

// Check token in the database
$uid = (int)$_SESSION['user_id'];
$token = $_SESSION['session_token'];

$check = $conn->query("SELECT session_token, session_expires FROM users WHERE user_id = $uid");
$row = $check->fetch_assoc();

if (!$row || $row['session_token'] !== $token || strtotime($row['session_expires']) <= time()) {
    session_destroy();
    header("Location: login.php");
    exit();
}