<?php
// lisani_aos/ajax/download_archived_document.php
// Document Tools > Document Archive. Streams the original stored file
// (JPG/PNG/PDF, whatever extension it was uploaded as) back to the browser.
session_start();

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Not authorized.';
    exit;
}

require_once __DIR__ . '/../db_config.php';

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid document id.';
    exit;
}

$stmt = $lisani_conn->prepare("SELECT original_filename, stored_filename, file_path FROM archived_documents WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

$fullPath = AOS_STORAGE_BASE . '/' . $row['file_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    echo 'File is missing from storage.';
    exit;
}

$ext = strtolower(pathinfo($row['stored_filename'], PATHINFO_EXTENSION));
$mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($row['original_filename']) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
