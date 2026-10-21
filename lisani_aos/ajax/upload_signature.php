<?php
// lisani_aos/ajax/upload_signature.php
// Document Tools > Signature Library.
//
// Storage pattern (21 Okt 2026, menggantikan pola company_activity khusus
// untuk Signature - lihat PROJECT_NOTES.md §8):
//   storage/signature/{company_name_slug}/{origin_slug}/{file}
// - company_name & origin diinput manual oleh user di Signature Editor,
//   disimpan apa adanya (hanya dirapikan whitespace/slash) ke kolom
//   company_name/origin, DAN dislugify untuk nama foldernya.
// - Judul Kegiatan/activity_title SUDAH DIHAPUS dari Signature (tabel
//   signature_library tidak lagi punya kolom ini - lihat
//   sql/migration_signature_library_company_origin.sql). Document Editor's
//   own activity_title (json_file/document_activities.json) tidak kena
//   dampak, itu fitur terpisah.
// - Signature Library sekarang HANYA bisa diisi lewat Signature Editor
//   (canvas Upload Photo + Crop + Erase Part + remove background), form
//   upload manual lama sudah dihapus dari UI - endpoint ini masih menerima
//   file lewat field 'file' (sekarang selalu PNG blob hasil export canvas).
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

function sanitize_signature_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\.\.+/', '.', $name);
    $name = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// Same light cleanup for Company Name / Origin as the old activity title:
// only shown to the user / stored in the DB, never used raw as a path
// (slugify_folder() below handles that separately).
function sanitize_folder_label(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// Filesystem-safe folder segment derived from a label.
function slugify_folder(string $name): string
{
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'unspecified';
}

$signatureName = sanitize_signature_name($_POST['signature_name'] ?? '');
$signatureDate = $_POST['signature_date'] ?? '';
$companyName = sanitize_folder_label($_POST['company_name'] ?? '');
$origin = sanitize_folder_label($_POST['origin'] ?? '');
$isMain = (isset($_POST['is_main']) && $_POST['is_main'] === '1') ? 1 : 0;

if ($signatureName === '') {
    echo json_encode(['success' => false, 'message' => 'Signature name is required.']);
    exit;
}

if ($companyName === '') {
    echo json_encode(['success' => false, 'message' => 'Company Name is required.']);
    exit;
}

if ($origin === '') {
    echo json_encode(['success' => false, 'message' => 'Origin is required.']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $signatureDate);
if (!$dateObj) {
    echo json_encode(['success' => false, 'message' => 'Invalid signature date.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload failed.']);
    exit;
}

$companySlug = slugify_folder($companyName);
$originSlug = slugify_folder($origin);

$relativeDir = "signature/{$companySlug}/{$originSlug}";
$targetDir = AOS_STORAGE_BASE . '/' . $relativeDir;

if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

$originalFilename = $_FILES['file']['name'];
$ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
$ext = preg_replace('/[^a-z0-9]/', '', $ext);

// Build stored filename from the (already edited-by-user) final signature name.
$baseName = preg_replace('/\s+/', '_', strtolower($signatureName));
$baseName = preg_replace('/[^a-z0-9_\-]/', '', $baseName);
if ($baseName === '') {
    $baseName = 'signature';
}

$storedFilename = $baseName . ($ext !== '' ? '.' . $ext : '');
$targetPath = $targetDir . '/' . $storedFilename;

// Avoid overwriting an existing file with a different upload.
$suffix = 1;
while (file_exists($targetPath)) {
    $storedFilename = $baseName . '-' . $suffix . ($ext !== '' ? '.' . $ext : '');
    $targetPath = $targetDir . '/' . $storedFilename;
    $suffix++;
}

if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file.']);
    exit;
}

$fileSize = filesize($targetPath) ?: 0;
$relativePath = $relativeDir . '/' . $storedFilename;

$stmt = $lisani_conn->prepare(
    "INSERT INTO signature_library
        (signature_name, signature_date, company_name, origin, is_main, original_filename, stored_filename, file_path, file_ext, file_size, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'ssssissssii',
    $signatureName,
    $signatureDate,
    $companyName,
    $origin,
    $isMain,
    $originalFilename,
    $storedFilename,
    $relativePath,
    $ext,
    $fileSize,
    $_SESSION['user_id']
);

if (!$stmt->execute()) {
    @unlink($targetPath);
    echo json_encode(['success' => false, 'message' => 'Failed to save signature record.']);
    exit;
}

echo json_encode(['success' => true, 'id' => $stmt->insert_id]);