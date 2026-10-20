<?php
// lisani_aos/ajax/delete_signature.php
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

$sigStmt = $lisani_conn->prepare('SELECT file_path FROM signature_library WHERE id = ?');
$sigStmt->bind_param('i', $id);
$sigStmt->execute();
$sig = $sigStmt->get_result()->fetch_assoc();

if (!$sig) {
    echo json_encode(['success' => false, 'message' => 'Signature not found.']);
    exit;
}

$delStmt = $lisani_conn->prepare('DELETE FROM signature_library WHERE id = ?');
$delStmt->bind_param('i', $id);

if (!$delStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to delete signature record.']);
    exit;
}

$fullPath = AOS_STORAGE_BASE . '/' . $sig['file_path'];
if (is_file($fullPath)) {
    // Sama seperti delete_document.php / delete_customer.php: file yang
    // dihapus dipindah ke storage/recycle/... (mirror subpath aslinya),
    // bukan dihapus permanen. file_path sekarang nested
    // (company_activity/{year}/{month}/{slug}/file), jadi recycle dir
    // dibangun dinamis dari dirname(file_path), bukan hardcode, supaya
    // tetap benar walau path-nya berubah-ubah per kegiatan/tanggal.
    // Belum ada retention/cleanup untuk folder recycle ini - lihat PROJECT_NOTES.md.
    $recycleDir = AOS_STORAGE_BASE . '/recycle/' . dirname($sig['file_path']);
    if (!is_dir($recycleDir)) {
        @mkdir($recycleDir, 0755, true);
    }
    $recycleName = $id . '_' . time() . '_' . basename($fullPath);
    @rename($fullPath, $recycleDir . '/' . $recycleName);
}

echo json_encode(['success' => true]);