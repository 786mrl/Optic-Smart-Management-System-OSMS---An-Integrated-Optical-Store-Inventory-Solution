<?php
// lisani_aos/ajax/upload_logistic_document.php
// Uploads one import document (shipper/custom/consignee) tied to an
// activity code. Folder is created on demand:
//   {AOS_STORAGE_BASE}/{activity relative_path}import_documents/{type}/
// Documents are keyed by activity_id (not logistic_id) because upload can
// happen before the logistics row itself is saved.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

$activityId   = (int) ($_POST['activity_id'] ?? 0);
$documentType = trim($_POST['document_type'] ?? '');
$documentName = trim($_POST['document_name'] ?? '');
$documentDate = trim($_POST['document_date'] ?? '');

$validTypes = ['shipper', 'custom', 'consignee'];

if ($activityId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Activity code belum dipilih.']);
    exit;
}
if (!in_array($documentType, $validTypes, true)) {
    echo json_encode(['ok' => false, 'message' => 'Jenis dokumen tidak valid.']);
    exit;
}
if ($documentName === '' || mb_strlen($documentName) > 150) {
    echo json_encode(['ok' => false, 'message' => 'Nama dokumen tidak valid.']);
    exit;
}
if ($documentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
    echo json_encode(['ok' => false, 'message' => 'Tanggal dokumen tidak valid.']);
    exit;
}
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => 'File tidak ditemukan atau gagal diupload.']);
    exit;
}

// Only allow uploading against a real, existing activity code.
$stmt = $lisani_conn->prepare('SELECT relative_path FROM activities WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $activityId);
$stmt->execute();
$stmt->bind_result($relativePath);
if (!$stmt->fetch()) {
    $stmt->close();
    echo json_encode(['ok' => false, 'message' => 'Activity code tidak ditemukan.']);
    exit;
}
$stmt->close();

// Build target folder: {relative_path}import_documents/{type}/
$folderRelative = rtrim($relativePath, '/') . '/import_documents/' . $documentType . '/';
$folderFull     = rtrim(AOS_STORAGE_BASE, '/') . '/' . $folderRelative;

if (!is_dir($folderFull)) {
    if (!mkdir($folderFull, 0755, true) && !is_dir($folderFull)) {
        echo json_encode(['ok' => false, 'message' => 'Gagal membuat folder di server.']);
        exit;
    }
}

// Sanitize original filename, prefix with timestamp so re-uploads with the
// same filename never overwrite each other.
$originalName = $_FILES['file']['name'];
$ext          = pathinfo($originalName, PATHINFO_EXTENSION);
$baseName     = pathinfo($originalName, PATHINFO_FILENAME);
$safeBase     = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $baseName);
$safeBase     = trim($safeBase, '_');
if ($safeBase === '') $safeBase = 'document';
$safeExt      = preg_replace('/[^A-Za-z0-9]+/', '', $ext);
$storedName   = date('YmdHis') . '_' . $safeBase . ($safeExt !== '' ? '.' . $safeExt : '');

$destFull     = $folderFull . $storedName;
$destRelative = $folderRelative . $storedName;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $destFull)) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan file ke server.']);
    exit;
}

$stmt = $lisani_conn->prepare(
    'INSERT INTO logistic_documents (activity_id, document_type, document_name, document_date, file_path, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$docDateOrNull = $documentDate !== '' ? $documentDate : null;
$stmt->bind_param('issssi', $activityId, $documentType, $documentName, $docDateOrNull, $destRelative, $_SESSION['user_id']);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan data dokumen ke database.']);
    exit;
}

echo json_encode([
    'ok'   => true,
    'data' => [
        'id'            => $stmt->insert_id,
        'document_type' => $documentType,
        'document_name' => $documentName,
        'document_date' => $documentDate,
        'file_path'     => $destRelative,
    ],
]);
$stmt->close();
