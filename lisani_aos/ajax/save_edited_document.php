<?php
// lisani_aos/ajax/save_edited_document.php
// Document Tools > Document Editor (Tahap 8, rangkuman_tool_ttd_dokumen.md).
//
// REVISI 18 Okt 2026 (multi-halaman + multi-ttd): frontend sekarang selalu
// mengirim SATU PNG per halaman lewat $_FILES['pages'][] (array, meski
// dokumennya cuma 1 halaman/gambar biasa - tetap dibungkus array berisi 1
// file), plus signature_ids[] berisi SEMUA id signature_library yang
// ditempel di halaman manapun (bisa kosong, bisa lebih dari satu, bisa
// duplikat - dirapikan di bawah). Semua halaman digabung jadi SATU file PDF
// dengan FPDF (sudah dipakai project ini untuk cetak invoice, lihat
// optic_pos/fpdf/fpdf.php), ukuran tiap halaman PDF mengikuti rasio gambar
// aslinya (bukan dipaksa A4) supaya tidak terdistorsi.
//
// REVISION 24 Oct 2026 (Stage 9 - Letterhead): letterhead_ids[] was added,
// following the EXACT SAME pattern as signature_ids - a CSV of ids in the
// letterhead_ids column (see migration_edited_documents_letterhead_ids.sql),
// validated against letterhead_library, allowed to be empty. The letterhead
// image itself is already flattened into each page's PNG by the frontend
// (Fabric) before it's sent here - so this endpoint does NOT place the
// letterhead separately; this column is purely metadata/history (same as
// signature_ids).
//
// Storage pattern sama seperti ajax/upload_signature.php (PROJECT_NOTES.md
// §13): storage/company_activity/{year}/{month}/{judul_kegiatan_slug}/edited_documents/{file}.pdf
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
// Shared with optic_pos (fpdf/fpdf.php sits at optic_pos/ root - see
// PROJECT_NOTES.md §2 folder map - lisani_aos/ is one level below that).
require_once dirname(__DIR__, 2) . '/fpdf/fpdf.php';

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

// Shared by signature_ids AND letterhead_ids: dedup + keep only positive
// integers, as a safety net against anything odd coming from the client.
function clean_id_list($raw): array
{
    $ids = [];
    if (is_array($raw)) {
        foreach ($raw as $v) {
            $v = (int) $v;
            if ($v > 0 && !in_array($v, $ids, true)) {
                $ids[] = $v;
            }
        }
    }
    return $ids;
}

// Confirms every id in $ids actually exists in $table, returns only the
// valid ones as a CSV string (or null if none valid/none given).
function valid_ids_csv(mysqli $conn, string $table, array $ids): ?string
{
    if (empty($ids)) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $valid = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $valid[] = (int) $row['id'];
    }
    return empty($valid) ? null : implode(',', $valid);
}

$documentName   = sanitize_doc_name($_POST['document_name'] ?? '');
$documentDate   = $_POST['document_date'] ?? '';
$activityTitle  = sanitize_activity_title_local($_POST['activity_title'] ?? '');
$sourceFilename = trim($_POST['source_filename'] ?? 'document.pdf');

$signatureIds = clean_id_list($_POST['signature_ids'] ?? []);
$letterheadIds = clean_id_list($_POST['letterhead_ids'] ?? []);

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

// Normalize $_FILES['pages'] (PHP's array-upload shape) into a flat list of
// [tmp_name, error] in the order the browser sent them - that order IS the
// page order, so it must be preserved exactly.
if (!isset($_FILES['pages']) || !is_array($_FILES['pages']['tmp_name']) || count($_FILES['pages']['tmp_name']) === 0) {
    echo json_encode(['success' => false, 'message' => 'No pages were uploaded.']);
    exit;
}

$pageCount = count($_FILES['pages']['tmp_name']);
$pageTmpPaths = [];
for ($i = 0; $i < $pageCount; $i++) {
    if ($_FILES['pages']['error'][$i] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload page ' . ($i + 1) . '.']);
        exit;
    }
    $pageTmpPaths[] = $_FILES['pages']['tmp_name'][$i];
}

// Confirm any signature/letterhead ids actually exist, silently dropping
// ones that don't instead of failing the whole save.
$signatureIdsCsv = valid_ids_csv($lisani_conn, 'signature_library', $signatureIds);
$letterheadIdsCsv = valid_ids_csv($lisani_conn, 'letterhead_library', $letterheadIds);

$year = $dateObj->format('Y');
$month = $dateObj->format('m');
$activitySlug = slugify_activity_local($activityTitle);

$relativeDir = "company_activity/{$year}/{$month}/{$activitySlug}/edited_documents";
$targetDir = AOS_STORAGE_BASE . '/' . $relativeDir;

if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

$baseName = preg_replace('/\s+/', '_', strtolower($documentName));
$baseName = preg_replace('/[^a-z0-9_\-]/', '', $baseName);
if ($baseName === '') {
    $baseName = 'document';
}

$storedFilename = $baseName . '.pdf';
$targetPath = $targetDir . '/' . $storedFilename;

$suffix = 1;
while (file_exists($targetPath)) {
    $storedFilename = $baseName . '-' . $suffix . '.pdf';
    $targetPath = $targetDir . '/' . $storedFilename;
    $suffix++;
}

// Build the combined PDF: one page per uploaded image, page size matched to
// that image's own aspect ratio (at an assumed 150 DPI, per the rangkuman's
// "150-200 DPI is enough to stay sharp when printed" note) so nothing gets
// stretched or letterboxed.
$ASSUMED_DPI = 150;
$pdf = new FPDF('P', 'mm');
$pdf->SetAutoPageBreak(false);

foreach ($pageTmpPaths as $i => $tmpPath) {
    $dims = @getimagesize($tmpPath);
    if (!$dims) {
        echo json_encode(['success' => false, 'message' => 'Page ' . ($i + 1) . ' is not a valid image.']);
        exit;
    }
    [$pxWidth, $pxHeight] = $dims;
    $mmWidth = $pxWidth / $ASSUMED_DPI * 25.4;
    $mmHeight = $pxHeight / $ASSUMED_DPI * 25.4;
    $orientation = $mmWidth > $mmHeight ? 'L' : 'P';

    $pdf->AddPage($orientation, [$mmWidth, $mmHeight]);
    $pdf->Image($tmpPath, 0, 0, $mmWidth, $mmHeight, 'PNG');
}

$pdf->Output('F', $targetPath);

if (!is_file($targetPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to write the combined PDF.']);
    exit;
}

$fileSize = filesize($targetPath) ?: 0;
$relativePath = $relativeDir . '/' . $storedFilename;

$stmt = $lisani_conn->prepare(
    "INSERT INTO edited_documents
        (document_name, document_date, activity_title, source_filename, signature_ids, letterhead_ids, page_count, stored_filename, file_path, file_size, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'ssssssissii'
    , $documentName
    , $documentDate
    , $activityTitle
    , $sourceFilename
    , $signatureIdsCsv
    , $letterheadIdsCsv
    , $pageCount
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

// Reuse the same shared activity list as Signature Library, so a title typed
// here also shows up in the dropdown there (and vice versa).
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

echo json_encode(['success' => true, 'id' => $stmt->insert_id, 'file_path' => $relativePath, 'page_count' => $pageCount]);