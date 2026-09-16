<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}
define('AOS_DOCUMENTS_DIR', AOS_STORAGE_BASE . '/company/legal_document');

function sanitize_document_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\.\.+/', '.', $name);
    $name = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

$documentName = sanitize_document_name($_POST['document_name'] ?? '');
$documentDate = $_POST['document_date'] ?? '';

if ($documentName === '') {
    echo json_encode(['success' => false, 'message' => 'Document name is required.']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $documentDate);
if (!$dateObj) {
    echo json_encode(['success' => false, 'message' => 'Invalid document date.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload failed.']);
    exit;
}

if (!is_dir(AOS_DOCUMENTS_DIR)) {
    @mkdir(AOS_DOCUMENTS_DIR, 0755, true);
}

$originalFilename = $_FILES['file']['name'];
$ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
$ext = preg_replace('/[^a-z0-9]/', '', $ext);

// Build stored filename from the (already edited-by-user) final document name.
$baseName = preg_replace('/\s+/', '_', strtolower($documentName));
$baseName = preg_replace('/[^a-z0-9_\-]/', '', $baseName);
if ($baseName === '') {
    $baseName = 'document';
}

$storedFilename = $baseName . ($ext !== '' ? '.' . $ext : '');
$targetPath = AOS_DOCUMENTS_DIR . '/' . $storedFilename;

// Avoid overwriting an existing file with a different upload.
$suffix = 1;
while (file_exists($targetPath)) {
    $storedFilename = $baseName . '-' . $suffix . ($ext !== '' ? '.' . $ext : '');
    $targetPath = AOS_DOCUMENTS_DIR . '/' . $storedFilename;
    $suffix++;
}

if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file.']);
    exit;
}

$fileSize = filesize($targetPath) ?: 0;
$relativePath = 'company/legal_document/' . $storedFilename;

$stmt = $lisani_conn->prepare(
    "INSERT INTO company_documents
        (document_name, document_date, original_filename, stored_filename, file_path, file_ext, file_size, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'ssssssii',
    $documentName,
    $documentDate,
    $originalFilename,
    $storedFilename,
    $relativePath,
    $ext,
    $fileSize,
    $_SESSION['user_id']
);

if (!$stmt->execute()) {
    @unlink($targetPath);
    echo json_encode(['success' => false, 'message' => 'Failed to save document record.']);
    exit;
}

echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
