<?php
// blocking_time_ajax.php
// AJAX endpoint used by index.php's "triple-click on company name" fly window.
// Verifies the Main Admin password, then allows updating the
// db_backup_blocking_time setting. Only the currently configured
// Main Admin (settings.main_admin_username) may use this endpoint.
session_start();
header('Content-Type: application/json');

include 'db_config.php';

$response = ['success' => false, 'message' => ''];

// Must be logged in as an admin at all
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $response['message'] = 'Unauthorized.';
    echo json_encode($response);
    exit();
}

$action = $_POST['action'] ?? '';
$password = $_POST['password'] ?? '';

// --- Determine the current Main Admin username from settings ---
$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'main_admin_username'");
$stmt->execute();
$res = $stmt->get_result();
$main_admin_row = $res->fetch_assoc();
$stmt->close();

$main_admin_username = $main_admin_row['setting_value'] ?? '';

// Only the logged-in Main Admin may use this fly window
if ($main_admin_username === '' || ($_SESSION['username'] ?? '') !== $main_admin_username) {
    $response['message'] = 'Only the Main Admin can access this feature.';
    echo json_encode($response);
    close_db_connection($conn);
    exit();
}

// --- Verify the supplied password against the Main Admin's password hash ---
$stmt = $conn->prepare("SELECT password_hash FROM users WHERE username = ? AND role = 'admin'");
$stmt->bind_param("s", $main_admin_username);
$stmt->execute();
$res = $stmt->get_result();
$admin_row = $res->fetch_assoc();
$stmt->close();

if (!$admin_row || !password_verify($password, $admin_row['password_hash'])) {
    $response['message'] = 'Incorrect Main Admin password.';
    echo json_encode($response);
    close_db_connection($conn);
    exit();
}

if ($action === 'verify') {
    // Password confirmed: return the current blocking time so the second
    // fly window can be pre-filled with the existing value.
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'db_backup_blocking_time'");
    $stmt->execute();
    $res = $stmt->get_result();
    $bt_row = $res->fetch_assoc();
    $stmt->close();

    $response['success'] = true;
    $response['current_value'] = $bt_row['setting_value'] ?? '20:30';

} elseif ($action === 'update') {
    $new_time = $_POST['blocking_time'] ?? '';

    // Expecting HH:MM (24h) as produced by an <input type="time">
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $new_time)) {
        $response['message'] = 'Invalid time format.';
        echo json_encode($response);
        close_db_connection($conn);
        exit();
    }

    $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'db_backup_blocking_time'");
    $stmt->bind_param("s", $new_time);
    $stmt->execute();
    $stmt->close();

    $response['success'] = true;
    $response['message'] = 'Database Backup Blocking Time updated successfully.';

} else {
    $response['message'] = 'Invalid action.';
}

close_db_connection($conn);
echo json_encode($response);
