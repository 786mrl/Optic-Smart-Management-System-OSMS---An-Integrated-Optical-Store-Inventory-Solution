<?php
// lisani_aos/ajax/download_signature.php
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
    exit('Invalid signature id.');
}

$stmt = $lisani_conn->prepare('SELECT signature_name, file_path, file_ext FROM signature_library WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$sig = $stmt->get_result()->fetch_assoc();

if (!$sig) {
    http_response_code(404);
    exit('Signature not found.');
}

$fullPath = AOS_STORAGE_BASE . '/' . $sig['file_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found on disk.');
}

$downloadName = $sig['signature_name'] . ($sig['file_ext'] !== '' ? '.' . $sig['file_ext'] : '');

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');

readfile($fullPath);
exit;
