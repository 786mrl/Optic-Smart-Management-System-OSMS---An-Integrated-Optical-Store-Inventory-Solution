<?php
    // log_activity.php
    // Receives an AJAX POST from inventory.php's affected-items confirmation modal
    // and records the confirmed list of affected items into activity_log.
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

    $username = $_SESSION['username'] ?? 'User';

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!isset($data['list']) || !is_array($data['list']) || count($data['list']) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No list provided']);
        exit();
    }

    $changedAt = date('Y-m-d H:i:s');

    $deleteStmt = $conn->prepare("DELETE FROM activity_log WHERE list = ?");
    $insertStmt = $conn->prepare("INSERT INTO activity_log (changed_by, changed_at, list) VALUES (?, ?, ?)");

    // Clean the incoming list first (unique, non-empty strings only).
    $items = [];
    foreach ($data['list'] as $item) {
        if (is_string($item) && trim($item) !== '' && !in_array($item, $items, true)) {
            $items[] = $item;
        }
    }

    // Pass 1: delete ALL previous log entries for every item in this request
    // first, before any insert happens. Doing delete+insert per item in the
    // same loop risked one item's pattern matching and wiping out a row
    // that was just inserted for another item earlier in the same request.
    // Exact match (=) is used instead of LIKE, since each row already stores
    // exactly one item as-is; LIKE with wildcards previously caused a
    // substring item (e.g. "qrcodes [folder]") to also match and delete an
    // unrelated row containing it as a substring (e.g. "main_qrcodes
    // [folder]"), which must never happen.
    foreach ($items as $item) {
        $deleteStmt->bind_param("s", $item);
        $deleteStmt->execute();
    }

    // Pass 2: insert all items as fresh rows now that old entries for the
    // whole batch are guaranteed gone, so no duplicates/leftovers remain.
    // Each affected item is still stored as its own separate row, instead of
    // being combined together into a single field, so items stay
    // independently trackable.
    foreach ($items as $item) {
        $insertStmt->bind_param("sss", $username, $changedAt, $item);
        $insertStmt->execute();
    }

    $deleteStmt->close();
    $insertStmt->close();

    echo json_encode(['success' => true]);
?>