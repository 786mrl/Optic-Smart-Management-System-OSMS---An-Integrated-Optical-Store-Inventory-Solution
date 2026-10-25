<?php
// lisani_aos/ajax/delete_letterhead.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/_require_reverify.php';
aos_require_recent_reverify();

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$stmt = $lisani_conn->prepare('SELECT header_file_path, footer_file_path FROM letterhead_library WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Letterhead not found.']);
    exit;
}

$delStmt = $lisani_conn->prepare('DELETE FROM letterhead_library WHERE id = ?');
$delStmt->bind_param('i', $id);
if (!$delStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to delete letterhead record.']);
    exit;
}

// Same recycle-move pattern as delete_signature.php, applied to both files
// (header + footer when present).
foreach (['header_file_path', 'footer_file_path'] as $col) {
    $relPath = $row[$col];
    if (!$relPath) {
        continue;
    }
    $fullPath = AOS_STORAGE_BASE . '/' . $relPath;
    if (!is_file($fullPath)) {
        continue;
    }
    $recycleDir = AOS_STORAGE_BASE . '/recycle/' . dirname($relPath);
    if (!is_dir($recycleDir)) {
        @mkdir($recycleDir, 0755, true);
    }
    $recycleName = $id . '_' . time() . '_' . basename($fullPath);
    @rename($fullPath, $recycleDir . '/' . $recycleName);
}

echo json_encode(['success' => true]);
