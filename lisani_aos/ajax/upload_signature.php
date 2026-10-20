<?php
// lisani_aos/ajax/upload_signature.php
// Document Tools > Signature Library.
//
// Storage pattern (16 Okt 2026, berlaku untuk seluruh Document Tools ke
// depan, bukan cuma Signature Library - lihat PROJECT_NOTES.md §13):
//   storage/company_activity/{year}/{month}/{judul_kegiatan_slug}/{file}
// - year/month diambil dari signature_date (tanggal file), bukan tanggal upload.
// - judul_kegiatan diinput manual oleh user, dan daftarnya disimpan ke
//   json_file/document_activities.json supaya bisa dipilih ulang lain kali
//   (lihat ajax/list_activities.php). Nama folder = versi slug dari judul
//   itu; judul aslinya (apa adanya) disimpan di kolom activity_title.
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
if (!defined('AOS_ACTIVITIES_JSON')) {
    define('AOS_ACTIVITIES_JSON', dirname(__DIR__) . '/json_file/document_activities.json');
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

// Same cleanup as the signature name, but allows a slightly wider charset
// (title case, punctuation people actually type in an activity name) since
// this one is only ever shown to the user, never used raw as a path.
function sanitize_activity_title(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// Filesystem-safe folder name derived from the activity title.
function slugify_activity(string $name): string
{
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'kegiatan';
}

// Append $title to the known-activities JSON list if it's not already
// there (case-insensitive compare). Best-effort: a race here just means a
// duplicate entry in the dropdown next time, never a hard failure.
function remember_activity_title(string $title): void
{
    $dir = dirname(AOS_ACTIVITIES_JSON);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $activities = [];
    if (is_file(AOS_ACTIVITIES_JSON)) {
        $raw = file_get_contents(AOS_ACTIVITIES_JSON);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['activities']) && is_array($decoded['activities'])) {
            $activities = $decoded['activities'];
        }
    }

    $exists = false;
    foreach ($activities as $existing) {
        if (strcasecmp($existing, $title) === 0) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $activities[] = $title;
        sort($activities, SORT_FLAG_CASE | SORT_STRING);
        @file_put_contents(
            AOS_ACTIVITIES_JSON,
            json_encode(['activities' => $activities], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

$signatureName = sanitize_signature_name($_POST['signature_name'] ?? '');
$signatureDate = $_POST['signature_date'] ?? '';
$activityTitle = sanitize_activity_title($_POST['activity_title'] ?? '');

if ($signatureName === '') {
    echo json_encode(['success' => false, 'message' => 'Signature name is required.']);
    exit;
}

if ($activityTitle === '') {
    echo json_encode(['success' => false, 'message' => 'Activity title (judul kegiatan) is required.']);
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

$year = $dateObj->format('Y');
$month = $dateObj->format('m');
$activitySlug = slugify_activity($activityTitle);

$relativeDir = "company_activity/{$year}/{$month}/{$activitySlug}";
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
        (signature_name, signature_date, activity_title, original_filename, stored_filename, file_path, file_ext, file_size, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'sssssssii',
    $signatureName,
    $signatureDate,
    $activityTitle,
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

remember_activity_title($activityTitle);

echo json_encode(['success' => true, 'id' => $stmt->insert_id]);