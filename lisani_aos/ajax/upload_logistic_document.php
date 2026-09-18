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
// Uppercased server-side as a safety net — client already forces uppercase
// on input, but this keeps stored document_name consistent even if the
// request bypasses the browser (e.g. direct API call, disabled JS).
$documentName = strtoupper($documentName);
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

// Document name must be unique within this activity code (case-insensitive).
$dupStmt = $lisani_conn->prepare(
    'SELECT id FROM logistic_documents WHERE activity_id = ? AND LOWER(document_name) = LOWER(?) LIMIT 1'
);
$dupStmt->bind_param('is', $activityId, $documentName);
$dupStmt->execute();
$dupStmt->store_result();
if ($dupStmt->num_rows > 0) {
    $dupStmt->close();
    echo json_encode(['ok' => false, 'message' => 'Nama dokumen sudah pernah dipakai untuk activity code ini, gunakan nama lain.']);
    exit;
}
$dupStmt->close();

// Build target folder: {relative_path}import_documents/{type}/
$folderRelative = rtrim($relativePath, '/') . '/import_documents/' . $documentType . '/';
$folderFull     = rtrim(AOS_STORAGE_BASE, '/') . '/' . $folderRelative;

if (!is_dir($folderFull)) {
    if (!mkdir($folderFull, 0755, true) && !is_dir($folderFull)) {
        echo json_encode(['ok' => false, 'message' => 'Gagal membuat folder di server.']);
        exit;
    }
}

// Stored filename follows the user-provided document_name (lowercased),
// NOT the original uploaded filename — keeps files on disk human-readable
// and consistent with what's shown in the UI. Extension still comes from
// the original upload. Prefix with timestamp so re-uploads (e.g. after a
// document_name gets reused following a delete) never overwrite each other.
$originalName = $_FILES['file']['name'];
$ext          = pathinfo($originalName, PATHINFO_EXTENSION);
$safeBase     = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $documentName);
$safeBase     = trim($safeBase, '_');
$safeBase     = strtolower($safeBase);
if ($safeBase === '') $safeBase = 'document';
$safeExt      = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $ext));
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