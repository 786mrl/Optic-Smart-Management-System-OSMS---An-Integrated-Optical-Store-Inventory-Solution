<?php
// lisani_aos/ajax/upload_archived_document.php
// Document Tools > Document Archive (upload-only, no canvas/editing - see
// PROJECT_NOTES.md §6/§8). Unlike save_edited_document.php, this endpoint
// stores the ORIGINAL uploaded file as-is, WHATEVER ITS TYPE (DOCX, XLSX,
// ZIP, JPG, PNG, PDF, etc. - single file, no per-page re-render, no FPDF
// recombination) - "Document Editor minus the editing step". Same metadata
// shape as Document Editor's save form (document_name, document_date,
// activity_title) and the same shared document_activities.json datalist,
// so a title typed here also shows up there and vice versa.
//
// Storage pattern: storage/company_activity/{year}/{month}/{judul_kegiatan_slug}/archived_documents/{file}
//
// No file-extension whitelist (revised 24 Nov 2026, user request: "support
// semuanya, seperti docx dan lain-lain") - any extension is accepted as-is.
// An empty extension is kept too (e.g. a file with no dot in its name), so
// $storedFilename can end up without one; this is intentional, since there
// is no fixed format to enforce here.
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

function sanitize_doc_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\.\.+/', '.', $name);
    $name = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function sanitize_activity_title_local(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function slugify_activity_local(string $name): string
{
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'kegiatan';
}

$documentName  = sanitize_doc_name($_POST['document_name'] ?? '');
$documentDate  = $_POST['document_date'] ?? '';
$activityTitle = sanitize_activity_title_local($_POST['activity_title'] ?? '');

if ($documentName === '') {
    echo json_encode(['success' => false, 'message' => 'Document name is required.']);
    exit;
}

if ($activityTitle === '') {
    echo json_encode(['success' => false, 'message' => 'Activity title (judul kegiatan) is required.']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $documentDate);
if (!$dateObj) {
    echo json_encode(['success' => false, 'message' => 'Invalid document date.']);
    exit;
}

if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please choose a file to upload.']);
    exit;
}

$tmpPath = $_FILES['document_file']['tmp_name'];
$originalName = $_FILES['document_file']['name'];
// Sanitize the extension itself (keep only safe characters) so it can't be
// used to inject a path or an unexpected server-executed extension into
// $storedFilename below - but no whitelist of WHICH extensions are allowed,
// since this archive is meant to take any file type.
$fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$fileExt = preg_replace('/[^a-z0-9]/', '', $fileExt);

// 50 MB max per file - raised from the 20 MB used for single-image uploads
// elsewhere (PROJECT_NOTES.md §8), since this archive now also takes
// heavier file types (DOCX, XLSX, ZIP, etc.).
$maxBytes = 50 * 1024 * 1024;
if (($_FILES['document_file']['size'] ?? 0) > $maxBytes) {
    echo json_encode(['success' => false, 'message' => 'File is too large (max 50 MB).']);
    exit;
}

$year = $dateObj->format('Y');
$month = $dateObj->format('m');
$activitySlug = slugify_activity_local($activityTitle);

$relativeDir = "company_activity/{$year}/{$month}/{$activitySlug}/archived_documents";
$targetDir = AOS_STORAGE_BASE . '/' . $relativeDir;

if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

$baseName = preg_replace('/\s+/', '_', strtolower($documentName));
$baseName = preg_replace('/[^a-z0-9_\-]/', '', $baseName);
if ($baseName === '') {
    $baseName = 'document';
}

$extSuffix = $fileExt !== '' ? '.' . $fileExt : '';
$storedFilename = $baseName . $extSuffix;
$targetPath = $targetDir . '/' . $storedFilename;

$suffix = 1;
while (file_exists($targetPath)) {
    $storedFilename = $baseName . '-' . $suffix . $extSuffix;
    $targetPath = $targetDir . '/' . $storedFilename;
    $suffix++;
}

if (!move_uploaded_file($tmpPath, $targetPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to store the uploaded file.']);
    exit;
}

$fileSize = filesize($targetPath) ?: 0;
$relativePath = $relativeDir . '/' . $storedFilename;

$stmt = $lisani_conn->prepare(
    "INSERT INTO archived_documents
        (document_name, document_date, activity_title, original_filename, stored_filename, file_path, file_size, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'ssssssii'
    , $documentName
    , $documentDate
    , $activityTitle
    , $originalName
    , $storedFilename
    , $relativePath
    , $fileSize
    , $_SESSION['user_id']
);

if (!$stmt->execute()) {
    @unlink($targetPath);
    echo json_encode(['success' => false, 'message' => 'Failed to save document record.']);
    exit;
}

// Reuse the same shared activity list as Signature Library / Document
// Editor, so a title typed here also shows up in their dropdowns too.
$activitiesJson = dirname(__DIR__) . '/json_file/document_activities.json';
$dir = dirname($activitiesJson);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$activities = [];
if (is_file($activitiesJson)) {
    $decoded = json_decode(file_get_contents($activitiesJson), true);
    if (is_array($decoded) && isset($decoded['activities']) && is_array($decoded['activities'])) {
        $activities = $decoded['activities'];
    }
}
$exists = false;
foreach ($activities as $existing) {
    if (strcasecmp($existing, $activityTitle) === 0) { $exists = true; break; }
}
if (!$exists) {
    $activities[] = $activityTitle;
    sort($activities, SORT_FLAG_CASE | SORT_STRING);
    @file_put_contents($activitiesJson, json_encode(['activities' => $activities], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

echo json_encode(['success' => true, 'id' => $stmt->insert_id, 'file_path' => $relativePath]);