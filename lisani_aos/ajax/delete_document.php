<?php
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

$docStmt = $lisani_conn->prepare('SELECT file_path FROM company_documents WHERE id = ?');
$docStmt->bind_param('i', $id);
$docStmt->execute();
$doc = $docStmt->get_result()->fetch_assoc();

if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'Document not found.']);
    exit;
}

$delStmt = $lisani_conn->prepare('DELETE FROM company_documents WHERE id = ?');
$delStmt->bind_param('i', $id);

if (!$delStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to delete document record.']);
    exit;
}

$fullPath = AOS_STORAGE_BASE . '/' . $doc['file_path'];
if (is_file($fullPath)) {
    // Same convention as delete_customer.php: deleted files are moved into
    // storage/recycle/... (mirroring their original subpath) instead of
    // being permanently removed. No retention/cleanup policy exists yet for
    // this folder - see PROJECT_NOTES.md.
    $recycleDir = AOS_STORAGE_BASE . '/recycle/company/legal_document';
    if (!is_dir($recycleDir)) {
        @mkdir($recycleDir, 0755, true);
    }
    $recycleName = $id . '_' . time() . '_' . basename($fullPath);
    @rename($fullPath, $recycleDir . '/' . $recycleName);
}

echo json_encode(['success' => true]);