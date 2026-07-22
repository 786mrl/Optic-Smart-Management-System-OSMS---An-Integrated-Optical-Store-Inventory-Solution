<?php
    // check_logged_items.php
    // Receives an AJAX POST with a list of candidate items and returns only
    // the ones that are NOT yet present in activity_log (exact match), so
    // the affected-items confirmation modal can be skipped or filtered down
    // to just what's actually new.
    date_default_timezone_set('Asia/Jakarta');
    session_start();
    include 'db_config.php';
    include 'auth_check.php';

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit();
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!isset($data['list']) || !is_array($data['list']) || count($data['list']) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No list provided']);
        exit();
    }

    // Clean the incoming list first (unique, non-empty strings only).
    $items = [];
    foreach ($data['list'] as $item) {
        if (is_string($item) && trim($item) !== '' && !in_array($item, $items, true)) {
            $items[] = $item;
        }
    }

    // Exact match against activity_log, same as log_activity.php's delete
    // logic, so "already logged" is judged consistently across the app.
    $checkStmt = $conn->prepare("SELECT id FROM activity_log WHERE list = ? LIMIT 1");

    $unloggedItems = [];
    foreach ($items as $item) {
        $checkStmt->bind_param("s", $item);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        if (!$result || $result->num_rows === 0) {
            $unloggedItems[] = $item;
        }
    }
    $checkStmt->close();

    echo json_encode(['success' => true, 'unloggedItems' => $unloggedItems]);
?>
