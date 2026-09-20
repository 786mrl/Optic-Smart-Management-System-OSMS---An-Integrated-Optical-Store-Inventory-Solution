<?php
// lisani_aos/ajax/create_disbursement.php
// Saves one Disbursement transaction: slip file + row in `transactions`
// + row in `transaction_disbursements`.
// Called from transaction_content.php (btnCapSave) with multipart FormData.
//
// Response contract: { ok: true|false, message: "...", ... }

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

// Every exit goes through here: drop anything PHP printed by accident
// (notices/warnings) so the browser always receives clean JSON.
function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('create_disbursement.php stray output: ' . $noise);
    }
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"ok":false,"message":"Failed to encode the response."}';
    }
    echo $json;
    exit;
}

function aos_fail(string $message): void
{
    aos_json(['ok' => false, 'message' => $message]);
}

// ---------- Guard ----------
if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    aos_fail('Session expired. Please log in again.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aos_fail('Invalid request.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

// ---------- Helpers ----------
function post_str(string $key): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

// Accepts "1,234.50" or "1234.5"; returns a plain decimal string or null.
function clean_decimal(string $raw): ?string
{
    $raw = str_replace(',', '', trim($raw));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return (string)$raw;
}

// ---------- Read + validate input ----------
$activityId = (int)post_str('activity_id');
$cashflow   = post_str('cashflow_type');
$purpose    = mb_strtoupper(post_str('transaction_purpose'));
$txnDate    = post_str('transaction_date');

if ($activityId <= 0) {
    aos_fail('Activity Code is missing.');
}
if (!in_array($cashflow, ['inflow', 'outflow', 'in-out'], true)) {
    aos_fail('Cashflow Type is not valid.');
}
if ($purpose === '') {
    aos_fail('Transaction Purpose is required.');
}
if (mb_strlen($purpose) > 255) {
    aos_fail('Transaction Purpose is too long (max 255 characters).');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $txnDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $txnDate) {
    aos_fail('Date is not valid.');
}

// Free-text bank/account fields are always stored uppercase (project convention).
$sourceBank      = mb_strtoupper(post_str('source_bank'));
$destBank        = mb_strtoupper(post_str('destination_bank'));
$sourceAccNumber = mb_strtoupper(post_str('source_account_number'));
$sourceAccName   = mb_strtoupper(post_str('source_account_name'));
$destAccNumber   = mb_strtoupper(post_str('destination_account_number'));
$destAccName     = mb_strtoupper(post_str('destination_account_name'));
$notes           = mb_strtoupper(post_str('notes'));

$maxLens = [
    'Source Bank' => [$sourceBank, 100], 'Destination Bank' => [$destBank, 100],
    'Source Account Number' => [$sourceAccNumber, 60], 'Destination Account Number' => [$destAccNumber, 60],
    'Source Account Name' => [$sourceAccName, 150], 'Destination Account Name' => [$destAccName, 150],
    'Notes' => [$notes, 500],
];
foreach ($maxLens as $label => $pair) {
    if (mb_strlen($pair[0]) > $pair[1]) {
        aos_fail($label . ' is too long (max ' . $pair[1] . ' characters).');
    }
}

// Slips print local symbols; always store the ISO 4217 code (RM -> MYR, Rp -> IDR).
function normalize_currency(string $raw): string
{
    $aliases = [
        'RM' => 'MYR', 'RINGGIT' => 'MYR', 'MYR' => 'MYR',
        'RP' => 'IDR', 'RUPIAH' => 'IDR', 'IDR' => 'IDR',
        'US$' => 'USD', 'USD' => 'USD',
    ];
    $key = strtoupper(preg_replace('/[\s.]/', '', $raw));
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }
    return substr(preg_replace('/[^A-Z]/', '', $key), 0, 10);
}

$currency = normalize_currency(post_str('currency'));
if ($currency === '') {
    $currency = 'IDR';
}
if (strlen($currency) > 10) {
    aos_fail('Currency is not valid.');
}

$amount = clean_decimal(post_str('amount'));
if ($amount === null || (float)$amount <= 0) {
    aos_fail('Transaction Amount is not valid.');
}

$exchangeRate = null;
if ($currency !== 'IDR') {
    $exchangeRate = clean_decimal(post_str('exchange_rate'));
    if ($exchangeRate === null || (float)$exchangeRate <= 0) {
        aos_fail('Exchange Rate is required when Currency is not IDR.');
    }
}

$finalAmount = clean_decimal(post_str('final_amount_idr'));
if ($finalAmount === null || (float)$finalAmount <= 0) {
    aos_fail('Final Amount (IDR) is not valid.');
}

// ---------- Activity must exist (also gives us the storage folder) ----------
$stmt = $lisani_conn->prepare('SELECT id, relative_path FROM activities WHERE id = ?');
$stmt->bind_param('i', $activityId);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$activity) {
    aos_fail('Activity Code was not found.');
}

// relative_path looks like "input/2026/dates/001/" — normalise and refuse
// anything that could climb out of the storage root.
$relPath = trim(str_replace('\\', '/', (string)$activity['relative_path']), '/');
if ($relPath === '' || strpos($relPath, '..') !== false) {
    aos_fail('This Activity Code has an invalid storage path.');
}

// ---------- Uploaded slip ----------
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $code = isset($_FILES['document']) ? $_FILES['document']['error'] : -1;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        aos_fail('The file is larger than the server upload limit.');
    }
    aos_fail('The bank slip was not uploaded.');
}

$file = $_FILES['document'];
$maxBytes = 20 * 1024 * 1024;
if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
    aos_fail('The bank slip must be between 1 byte and 20 MB.');
}

$allowedMimeToExt = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($file['tmp_name']);
if (!isset($allowedMimeToExt[$realMime])) {
    aos_fail('Only PDF, JPG, PNG, or WEBP files are allowed.');
}
$ext = $allowedMimeToExt[$realMime];

$targetDir = AOS_STORAGE_BASE . '/' . $relPath . '/disbursement';
if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    aos_fail('Could not create the storage folder for this slip.');
}

// File name follows the Transaction Purpose (lowercase, safe characters),
// e.g. "PEMBELIAN KARDUS" on 2026-09-20 -> pembelian_kardus_20260920.pdf.
// Same purpose + same date twice gets _2, _3, ... instead of overwriting.
$slug = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $purpose)), '_');
$slug = rtrim(substr($slug, 0, 80), '_');
if ($slug === '') {
    $slug = 'disbursement';
}
$baseName = $slug . '_' . date('Ymd', strtotime($txnDate));
$storedName = $baseName . '.' . $ext;
for ($n = 2; file_exists($targetDir . '/' . $storedName); $n++) {
    $storedName = $baseName . '_' . $n . '.' . $ext;
}
$targetFull = $targetDir . '/' . $storedName;
$documentPath = $relPath . '/disbursement/' . $storedName; // relative to AOS_STORAGE_BASE

if (!move_uploaded_file($file['tmp_name'], $targetFull)) {
    aos_fail('Could not save the bank slip file.');
}

$originalName = mb_substr(basename((string)$file['name']), 0, 255);
$userId = (int)$_SESSION['user_id'];

// ---------- Two inserts, one DB transaction ----------
// Make mysqli throw on failure (default only from PHP 8.1) so the catch below always fires.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $lisani_conn->begin_transaction();

    $ins = $lisani_conn->prepare(
        'INSERT INTO transactions
           (category, transaction_date, source_bank, destination_bank,
            source_account_number, source_account_name,
            destination_account_number, destination_account_name,
            notes, currency, amount, exchange_rate, final_amount_idr,
            document_path, document_original_name, created_by)
         VALUES (\'disbursement\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param(
        'ssssssssssssssi',
        $txnDate, $sourceBank, $destBank,
        $sourceAccNumber, $sourceAccName,
        $destAccNumber, $destAccName,
        $notes, $currency, $amount, $exchangeRate, $finalAmount,
        $documentPath, $originalName, $userId
    );
    $ins->execute();
    $transactionId = $lisani_conn->insert_id;
    $ins->close();

    $ins2 = $lisani_conn->prepare(
        'INSERT INTO transaction_disbursements
           (transaction_id, activity_id, cashflow_type, transaction_purpose)
         VALUES (?, ?, ?, ?)'
    );
    $ins2->bind_param('iiss', $transactionId, $activityId, $cashflow, $purpose);
    $ins2->execute();
    $ins2->close();

    $lisani_conn->commit();
} catch (Throwable $e) {
    $lisani_conn->rollback();
    @unlink($targetFull); // don't leave an orphan file behind
    error_log('create_disbursement.php: ' . $e->getMessage());
    aos_fail('Failed to save the transaction.');
}

aos_json([
    'ok'            => true,
    'message'       => 'Disbursement saved.',
    'id'            => $transactionId,
    'document_path' => $documentPath,
]);