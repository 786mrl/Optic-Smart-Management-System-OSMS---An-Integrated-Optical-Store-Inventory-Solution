<?php
// lisani_aos/ajax/view_proof.php
// Opens a transaction's proof file (bukti transaksi) inline in a new tab,
// from the Finance Report ledger's Description column (user request, 20 Nov
// 2026). Not a JSON endpoint — this URL is used directly as a link target
// (target="_blank"), so errors are plain text + HTTP status, not {ok:false}.
//
// GET params: source (disbursement|refund|invoice_payment|invoice_payment_credit),
//             ref_id (int)
//             doc (optional — see "Multiple documents" below)
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
//
// Multiple documents (24 Nov 2026, user request): a `disbursement` row can
// now have Additional Documents on top of its one transfer slip (see
// disbursement_additional_documents, migration_disbursement_additional_documents.sql
// and create_disbursement.php's header comment). When that table has 1+ rows
// for this ref_id, this endpoint — with no `doc` param — returns a small HTML
// list page (slip + each additional document, one link per file, each link
// pointing back at THIS SAME endpoint with `doc=slip` or `doc=<id>` added) instead
// of streaming a file directly, so the user can open every file for that
// disbursement, not just the first one. `doc=slip` streams the transfer slip
// itself (transactions.document_path — the original single-file behaviour);
// `doc=<disbursement_additional_documents.id>` streams that one additional
// file. Sources other than `disbursement`, and a `disbursement` row with no
// additional documents, are completely unaffected: straight to the existing
// single-file stream below, `doc` is simply never looked at for them.

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

$doc = isset($_GET['doc']) ? trim((string) $_GET['doc']) : '';

// ---------- Disbursement with Additional Documents: list page ----------
// Only reached for source=disbursement with NO `doc` param yet (the list
// page's own links always carry `doc=...`, see below) — checked before the
// normal single-file query so a disbursement with additional documents never
// falls through to streaming the slip directly.
if ($source === 'disbursement' && $doc === '') {
    $stmt = $lisani_conn->prepare(
        'SELECT id, original_name FROM disbursement_additional_documents
         WHERE transaction_id = ? ORDER BY id ASC'
    );
    $stmt->bind_param('i', $refId);
    $stmt->execute();
    $additionalRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($additionalRows)) {
        // Need the slip's own display name for its link label — same
        // columns the single-file path below already selects.
        $stmt = $lisani_conn->prepare(
            "SELECT document_path, document_original_name FROM transactions
             WHERE id = ? AND category = 'disbursement'"
        );
        $stmt->bind_param('i', $refId);
        $stmt->execute();
        $txnRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$txnRow || trim((string) $txnRow['document_path']) === '') {
            proof_fail(404, 'Proof file not found.');
        }

        $slipName = trim((string) $txnRow['document_original_name']) !== ''
            ? (string) $txnRow['document_original_name']
            : basename((string) $txnRow['document_path']);

        $selfUrl = 'view_proof.php?source=' . rawurlencode($source) . '&ref_id=' . rawurlencode((string) $refId);

        $items = [];
        $items[] = ['label' => $slipName . ' (Transfer Slip)', 'url' => $selfUrl . '&doc=slip'];
        foreach ($additionalRows as $ar) {
            $label = trim((string) $ar['original_name']) !== '' ? (string) $ar['original_name'] : ('Document #' . $ar['id']);
            $items[] = ['label' => $label, 'url' => $selfUrl . '&doc=' . rawurlencode((string) $ar['id'])];
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<title>Disbursement Documents</title>';
        echo '<style>body{font-family:system-ui,sans-serif;background:#1a1a2e;color:#eee;padding:32px;}';
        echo 'h1{font-size:18px;margin-bottom:20px;}a{display:block;padding:12px 16px;margin-bottom:8px;';
        echo 'background:#25253f;border-radius:8px;color:#8ab4ff;text-decoration:none;}a:hover{background:#30304f;}</style>';
        echo '</head><body>';
        echo '<h1>Documents for this Disbursement</h1>';
        foreach ($items as $it) {
            echo '<a href="' . htmlspecialchars($it['url'], ENT_QUOTES, 'UTF-8') . '" target="_blank">' .
                htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</body></html>';
        exit;
    }
}

// ---------- Single file: resolve which row to stream ----------
// Default behaviour (doc empty, or no additional documents above): the
// transfer slip / the source's own single proof column, exactly as before.
// doc=slip is the explicit form of the same thing, used by the list page's
// own first link. doc=<id> (disbursement-only) streams one additional
// document instead.
if ($source === 'disbursement' && $doc !== '' && $doc !== 'slip') {
    if (!ctype_digit($doc)) {
        proof_fail(400, 'Invalid doc.');
    }
    $docId = (int) $doc;
    $stmt = $lisani_conn->prepare(
        'SELECT document_path AS proof_path, original_name AS proof_name
         FROM disbursement_additional_documents WHERE id = ? AND transaction_id = ?'
    );
    $stmt->bind_param('ii', $docId, $refId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $sql = "SELECT {$map['path_col']} AS proof_path, {$map['name_col']} AS proof_name
            FROM {$map['table']} WHERE id = ?" . $map['extra_where'];
    $stmt = $lisani_conn->prepare($sql);
    $stmt->bind_param('i', $refId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

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