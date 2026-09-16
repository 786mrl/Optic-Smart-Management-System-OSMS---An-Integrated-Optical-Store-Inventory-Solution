<?php
session_start();

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}

require_once __DIR__ . '/../db_config.php';

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid document id.');
}

$stmt = $lisani_conn->prepare('SELECT document_name, file_path, file_ext FROM company_documents WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$fullPath = AOS_STORAGE_BASE . '/' . $doc['file_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found on disk.');
}

$downloadName = $doc['document_name'] . ($doc['file_ext'] !== '' ? '.' . $doc['file_ext'] : '');

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');

readfile($fullPath);
exit;
