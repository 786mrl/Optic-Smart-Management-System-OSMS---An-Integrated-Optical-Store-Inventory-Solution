<?php
// lisani_aos/ajax/view_proof.php
// Opens a transaction's proof file (bukti transaksi) inline in a new tab,
// from the Finance Report ledger's Description column (user request, 20 Nov
// 2026). Not a JSON endpoint — this URL is used directly as a link target
// (target="_blank"), so errors are plain text + HTTP status, not {ok:false}.
//
// GET params: source (disbursement|refund|invoice_payment|invoice_payment_credit),
//             ref_id (int)
//
// Column map (confirmed via DESCRIBE, 20 Nov 2026):
//   - disbursement            -> transactions.document_path / document_original_name
//   - refund                  -> invoice_refunds.proof_path / proof_original_name
//   - invoice_payment,
//     invoice_payment_credit  -> invoice_payments.proof_path / proof_original_name
//     (a credit-balance row is sliced from an invoice_payments row, so it
//     shares that same payment's ref_id and proof — see get_finance_report.php)
//
// All three *_path columns are relative paths under AOS_STORAGE_BASE (confirmed
// with the user, 20 Nov 2026). AOS_STORAGE_BASE itself is NOT in db_config.php —
// confirmed (20 Nov 2026) by reading create_invoice_payment.php: each file that
// needs it defines it itself, guarded by defined(), as dirname(__DIR__).'/storage'
// (i.e. lisani_aos/storage). Same pattern used below.

ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function proof_fail(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    proof_fail(401, 'Session expired. Please log in again.');
}

// Same guarded define() as create_invoice_payment.php / create_disbursement.php
// (confirmed 20 Nov 2026 — AOS_STORAGE_BASE is NOT in db_config.php, each file
// that needs it defines it itself this way).
if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

require_once dirname(__DIR__) . '/db_config.php'; // $lisani_conn (mysqli)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$refIdRaw = isset($_GET['ref_id']) ? trim((string) $_GET['ref_id']) : '';

if (!ctype_digit($refIdRaw)) {
    proof_fail(400, 'Invalid ref_id.');
}
$refId = (int) $refIdRaw;

// Fixed whitelist — table/column names below are never built from user
// input, only looked up through this map.
$sourceMap = [
    'disbursement'           => ['table' => 'transactions',      'path_col' => 'document_path', 'name_col' => 'document_original_name', 'extra_where' => " AND category = 'disbursement'"],
    'refund'                 => ['table' => 'invoice_refunds',   'path_col' => 'proof_path',     'name_col' => 'proof_original_name',    'extra_where' => ''],
    'invoice_payment'        => ['table' => 'invoice_payments',  'path_col' => 'proof_path',     'name_col' => 'proof_original_name',    'extra_where' => ''],
    'invoice_payment_credit' => ['table' => 'invoice_payments',  'path_col' => 'proof_path',     'name_col' => 'proof_original_name',    'extra_where' => ''],
];

if (!isset($sourceMap[$source])) {
    proof_fail(400, 'Unknown source.');
}
$map = $sourceMap[$source];

$sql = "SELECT {$map['path_col']} AS proof_path, {$map['name_col']} AS proof_name
        FROM {$map['table']} WHERE id = ?" . $map['extra_where'];
$stmt = $lisani_conn->prepare($sql);
$stmt->bind_param('i', $refId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || trim((string) $row['proof_path']) === '') {
    proof_fail(404, 'Proof file not found.');
}

$relativePath = ltrim(str_replace('\\', '/', (string) $row['proof_path']), '/');
$fullPath = rtrim(AOS_STORAGE_BASE, '/') . '/' . $relativePath;

// Resolve symlinks/"..".and make sure it still lands inside AOS_STORAGE_BASE
// (defence in depth — $relativePath comes from the DB, not the request, but
// cheap to check).
$realBase = realpath(AOS_STORAGE_BASE);
$realFull = realpath($fullPath);
if ($realBase === false || $realFull === false || strpos($realFull, $realBase) !== 0) {
    proof_fail(404, 'Proof file not found.');
}

$displayName = trim((string) $row['proof_name']) !== '' ? (string) $row['proof_name'] : basename($realFull);

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $realFull);
        if ($detected) { $mime = $detected; }
        finfo_close($finfo);
    }
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $displayName) . '"');
header('Content-Length: ' . filesize($realFull));
header('X-Content-Type-Options: nosniff');
readfile($realFull);
exit;