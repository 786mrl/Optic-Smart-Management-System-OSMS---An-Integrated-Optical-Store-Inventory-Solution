<?php
// lisani_aos/ajax/list_letterheads.php
// Main letterhead (is_main=1) sorted first, same convention as
// list_signatures.php.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';

$rows = [];
$result = $lisani_conn->query(
    "SELECT id, letterhead_name, company_name, origin, is_main, has_footer, created_at
     FROM letterhead_library
     ORDER BY is_main DESC, id DESC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['is_main'] = (int) $row['is_main'];
        $row['has_footer'] = (int) $row['has_footer'];
        $rows[] = $row;
    }
}

echo json_encode(['success' => true, 'letterheads' => $rows]);
