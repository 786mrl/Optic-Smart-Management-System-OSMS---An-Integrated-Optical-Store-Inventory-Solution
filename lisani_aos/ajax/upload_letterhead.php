<?php
// lisani_aos/ajax/upload_letterhead.php
// Document Tools > Letterhead Library (Stage 9). Same pattern as
// upload_signature.php: storage/letterhead/{company_slug}/{origin_slug}/{file}.
// Difference from signature: 1 entry = 2 files (header REQUIRED, footer
// OPTIONAL - a footer isn't always used, see PROJECT_NOTES.md §8). No
// canvas editor like the Signature Editor - the user just uploads a
// ready-made letterhead PNG (per rangkuman_tool_ttd_dokumen.md §5: "Save
// the letterhead design as a PNG... in the letterhead library").
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

function sanitize_letterhead_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\.\.+/', '.', $name);
    $name = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function sanitize_folder_label(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function slugify_folder(string $name): string
{
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'unspecified';
}

// Saves one uploaded file ($field) into $targetDir with filename
// "{prefix}_{baseName}[-N].{ext}". Returns [original, stored, ext, size] or
// null if the field wasn't sent (used for the optional footer).
function save_letterhead_part(string $field, string $prefix, string $baseName, string $targetDir): ?array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed for ' . $field . '.'];
    }

    $originalFilename = $_FILES[$field]['name'];
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);

    $storedFilename = $prefix . '_' . $baseName . ($ext !== '' ? '.' . $ext : '');
    $targetPath = $targetDir . '/' . $storedFilename;
    $suffix = 1;
    while (file_exists($targetPath)) {
        $storedFilename = $prefix . '_' . $baseName . '-' . $suffix . ($ext !== '' ? '.' . $ext : '');
        $targetPath = $targetDir . '/' . $storedFilename;
        $suffix++;
    }

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $targetPath)) {
        return ['error' => 'Failed to save ' . $field . '.'];
    }

    return [
        'original' => $originalFilename,
        'stored' => $storedFilename,
        'ext' => $ext,
        'size' => filesize($targetPath) ?: 0,
    ];
}

// strtoupper() as a server-side backstop for the client's .input-uppercase
// class (project convention, PROJECT_NOTES.md §3) - keeps data uppercase
// even if the request didn't come through the normal form (API client,
// JS disabled, etc).
$letterheadName = strtoupper(sanitize_letterhead_name($_POST['letterhead_name'] ?? ''));
$companyName = strtoupper(sanitize_folder_label($_POST['company_name'] ?? ''));
$origin = strtoupper(sanitize_folder_label($_POST['origin'] ?? ''));
$isMain = (isset($_POST['is_main']) && $_POST['is_main'] === '1') ? 1 : 0;

if ($letterheadName === '') {
    echo json_encode(['success' => false, 'message' => 'Letterhead name is required.']);
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
if (!isset($_FILES['header_file']) || $_FILES['header_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Header image is required.']);
    exit;
}

$companySlug = slugify_folder($companyName);
$originSlug = slugify_folder($origin);
$relativeDir = "letterhead/{$companySlug}/{$originSlug}";
$targetDir = AOS_STORAGE_BASE . '/' . $relativeDir;
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

$baseName = preg_replace('/\s+/', '_', strtolower($letterheadName));
$baseName = preg_replace('/[^a-z0-9_\-]/', '', $baseName);
if ($baseName === '') {
    $baseName = 'letterhead';
}

$header = save_letterhead_part('header_file', 'header', $baseName, $targetDir);
if ($header === null || isset($header['error'])) {
    echo json_encode(['success' => false, 'message' => $header['error'] ?? 'Header image is required.']);
    exit;
}

$footer = save_letterhead_part('footer_file', 'footer', $baseName, $targetDir);
if ($footer !== null && isset($footer['error'])) {
    @unlink($targetDir . '/' . $header['stored']);
    echo json_encode(['success' => false, 'message' => $footer['error']]);
    exit;
}

$headerRelPath = $relativeDir . '/' . $header['stored'];
$hasFooter = $footer !== null ? 1 : 0;
$footerRelPath = $footer !== null ? $relativeDir . '/' . $footer['stored'] : null;

$stmt = $lisani_conn->prepare(
    "INSERT INTO letterhead_library
        (letterhead_name, company_name, origin, is_main,
         header_original_filename, header_stored_filename, header_file_path, header_file_ext, header_file_size,
         has_footer, footer_original_filename, footer_stored_filename, footer_file_path, footer_file_ext, footer_file_size,
         uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'sssissssiissssii',
    $letterheadName,
    $companyName,
    $origin,
    $isMain,
    $header['original'],
    $header['stored'],
    $headerRelPath,
    $header['ext'],
    $header['size'],
    $hasFooter,
    $footer['original'],
    $footer['stored'],
    $footerRelPath,
    $footer['ext'],
    $footer['size'],
    $_SESSION['user_id']
);

if (!$stmt->execute()) {
    @unlink($targetDir . '/' . $header['stored']);
    if ($footer !== null) {
        @unlink($targetDir . '/' . $footer['stored']);
    }
    echo json_encode(['success' => false, 'message' => 'Failed to save letterhead record.']);
    exit;
}

echo json_encode(['success' => true, 'id' => $stmt->insert_id]);