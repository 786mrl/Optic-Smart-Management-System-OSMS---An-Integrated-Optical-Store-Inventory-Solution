<?php
// lisani_aos/ajax/delete_archived_document.php
// Document Tools > Document Archive. Same cleanup pattern as
// delete_letterhead.php/delete_signature.php: move the row's file to
// storage/recycle/ (NOT hard-delete), then remove the original folder if it
// ends up empty, walking up and stopping at the first ancestor that still
// has content (never deletes storage/company_activity itself).
//
// Password-gated via the shared docToolsConfirmPassword modal, which
// verifies against ajax/verify_password.php FIRST (pola (b),
// PROJECT_NOTES.md §3) and only then calls this endpoint with no password -
// same as delete_letterhead.php/delete_signature.php's actual pattern.
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

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document id.']);
    exit;
}

$stmt = $lisani_conn->prepare("SELECT file_path FROM archived_documents WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Document not found.']);
    exit;
}

$stmt = $lisani_conn->prepare("DELETE FROM archived_documents WHERE id = ?");
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to delete document record.']);
    exit;
}

$filePath = AOS_STORAGE_BASE . '/' . $row['file_path'];
if (is_file($filePath)) {
    $recycleDir = AOS_STORAGE_BASE . '/recycle/archived_documents';
    if (!is_dir($recycleDir)) {
        @mkdir($recycleDir, 0755, true);
    }
    $recyclePath = $recycleDir . '/' . time() . '_' . basename($filePath);
    @rename($filePath, $recyclePath);

    // Walk up from the file's original folder, removing each directory as
    // long as it's now empty, stopping at the first one that still has
    // something in it (or at storage/company_activity itself).
    $dir = dirname($filePath);
    $stopAt = realpath(AOS_STORAGE_BASE . '/company_activity');
    while ($dir && realpath($dir) !== $stopAt && is_dir($dir)) {
        $contents = @scandir($dir);
        if ($contents !== false && count($contents) <= 2) { // only "." and ".."
            @rmdir($dir);
            $dir = dirname($dir);
        } else {
            break;
        }
    }
}

echo json_encode(['success' => true]);
