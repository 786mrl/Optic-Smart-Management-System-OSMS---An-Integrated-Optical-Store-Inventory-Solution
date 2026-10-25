<?php
// lisani_aos/ajax/download_letterhead.php
// Different from download_signature.php: one letterhead_library row has 2
// files (header/footer), so it needs an extra ?part=header|footer param
// (defaults to header). Also used by the <img> in the picker modal and by
// fabric.Image.fromURL() when inserting onto the canvas (see
// insertLetterheadOntoCanvas() in the JS) - that's why Content-Disposition
// is "inline" here, not "attachment" like download_signature.php (which is
// a plain download button).
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
$part = isset($_GET['part']) && $_GET['part'] === 'footer' ? 'footer' : 'header';

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid letterhead id.');
}

$stmt = $lisani_conn->prepare(
    'SELECT letterhead_name, has_footer,
            header_file_path, header_file_ext,
            footer_file_path, footer_file_ext
     FROM letterhead_library WHERE id = ?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    http_response_code(404);
    exit('Letterhead not found.');
}

if ($part === 'footer') {
    if (!$row['has_footer'] || $row['footer_file_path'] === null) {
        http_response_code(404);
        exit('This letterhead has no footer.');
    }
    $relPath = $row['footer_file_path'];
    $ext = $row['footer_file_ext'];
} else {
    $relPath = $row['header_file_path'];
    $ext = $row['header_file_ext'];
}

$fullPath = AOS_STORAGE_BASE . '/' . $relPath;
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found on disk.');
}

$mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ($ext === 'svg' ? 'image/svg+xml' : 'image/png');

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($relPath) . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');

readfile($fullPath);
exit;