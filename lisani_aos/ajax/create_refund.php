<?php
// lisani_aos/ajax/create_refund.php
// Cash refund of part/all of a customer's credit_balance (credit comes from
// create_return.php / create_price_adjustment.php having no open invoice to
// reduce — see PROJECT_NOTES.md, 1 Okt 2026). Proof is OPTIONAL: sometimes
// it's a bank transfer with a slip, sometimes just a note.
// Decreases customers.credit_balance AND customers.total_paid (money is
// actually leaving the business, so net cash received from this customer
// goes down).
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

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('create_refund.php stray output: ' . $noise);
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

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    aos_fail('Session expired. Please log in again.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aos_fail('Invalid request.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

function post_str(string $key): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

function clean_decimal(string $raw): ?string
{
    $raw = str_replace(',', '', trim($raw));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return (string)$raw;
}

// Identical to create_customer.php's helper — so a refund's proof (if any)
// lands in the same folder that file already created for this customer.
function sanitize_folder_name(string $name): string
{
    $clean = str_replace(['/', '\\'], ' ', $name);
    $clean = preg_replace('/\.\.+/', '', $clean);
    $clean = preg_replace('/[^A-Za-z0-9 _-]/', '', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    return $clean;
}

$customerId = (int) post_str('customer_id');
$refundDate = post_str('refund_date');
$method     = mb_strtoupper(post_str('method'));
$notes      = mb_strtoupper(post_str('notes'));

if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $refundDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $refundDate) {
    aos_fail('Refund Date is not valid.');
}
if (mb_strlen($method) > 100) {
    aos_fail('Method is too long (max 100 characters).');
}
if (mb_strlen($notes) > 500) {
    aos_fail('Notes is too long (max 500 characters).');
}

$amountRaw = clean_decimal(post_str('amount'));
if ($amountRaw === null || (float)$amountRaw <= 0) {
    aos_fail('Amount is not valid.');
}
$amount = (float) $amountRaw;

// Source account is OPTIONAL: some refunds are approved by management with
// no transfer at all (cash, or just a write-off), so these can all be NULL.
$sourceBank      = post_str('source_bank') === '' ? null : mb_strtoupper(post_str('source_bank'));
$sourceAccNumber = post_str('source_account_number') === '' ? null : mb_strtoupper(post_str('source_account_number'));
$sourceAccName   = post_str('source_account_name') === '' ? null : mb_strtoupper(post_str('source_account_name'));

foreach (['Source Bank' => [$sourceBank, 100], 'Source Account Number' => [$sourceAccNumber, 60],
          'Source Account Name' => [$sourceAccName, 150]] as $label => $pair) {
    if ($pair[0] !== null && mb_strlen($pair[0]) > $pair[1]) {
        aos_fail($label . ' is too long (max ' . $pair[1] . ' characters).');
    }
}

// ---------- Proof is optional ----------
$hasProof = isset($_FILES['proof']) && $_FILES['proof']['error'] !== UPLOAD_ERR_NO_FILE;
$documentPath = null;
$originalName = null;
$targetFull = null;

if ($hasProof) {
    if ($_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['proof']['error'];
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            aos_fail('The file is larger than the server upload limit.');
        }
        aos_fail('Could not read the uploaded proof file.');
    }
    $file = $_FILES['proof'];
    $maxBytes = 20 * 1024 * 1024;
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        aos_fail('The proof file must be between 1 byte and 20 MB.');
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
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    $stmt = $lisani_conn->prepare(
        'SELECT id, customer_name, year, credit_balance FROM customers WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        $lisani_conn->rollback();
        aos_fail('Customer was not found.');
    }

    $creditBalance = (float) $customer['credit_balance'];
    if ($amount > $creditBalance + 0.01) {
        $lisani_conn->rollback();
        aos_fail('Amount exceeds the available credit balance (' . number_format($creditBalance, 2) . ').');
    }

    if ($hasProof) {
        $folderName = strtolower(sanitize_folder_name((string) $customer['customer_name']));
        if ($folderName === '') {
            $folderName = 'customer_' . $customerId;
        }
        $relPath = 'selling/' . (int) $customer['year'] . '/' . $folderName . '/refunds';
        $targetDir = AOS_STORAGE_BASE . '/' . $relPath;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $lisani_conn->rollback();
            aos_fail('Could not create the storage folder for this refund.');
        }
        $baseName = 'refund_' . date('Ymd', strtotime($refundDate));
        $storedName = $baseName . '.' . $ext;
        for ($n = 2; file_exists($targetDir . '/' . $storedName); $n++) {
            $storedName = $baseName . '_' . $n . '.' . $ext;
        }
        $targetFull = $targetDir . '/' . $storedName;
        $documentPath = $relPath . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $targetFull)) {
            $lisani_conn->rollback();
            aos_fail('Could not save the proof file.');
        }
        $originalName = mb_substr(basename((string) $file['name']), 0, 255);
    }

    $userId = (int) $_SESSION['user_id'];

    $ins = $lisani_conn->prepare(
        'INSERT INTO invoice_refunds
           (customer_id, refund_date, amount, method, source_bank, source_account_number,
            source_account_name, notes, proof_path, proof_original_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $amountStr = (string) $amount;
    $ins->bind_param(
        'isssssssssi',
        $customerId, $refundDate, $amountStr, $method, $sourceBank, $sourceAccNumber,
        $sourceAccName, $notes, $documentPath, $originalName, $userId
    );
    $ins->execute();
    $refundId = $lisani_conn->insert_id;
    $ins->close();

    $upd = $lisani_conn->prepare(
        'UPDATE customers SET credit_balance = credit_balance - ?, total_paid = total_paid - ? WHERE id = ?'
    );
    $upd->bind_param('ssi', $amountStr, $amountStr, $customerId);
    $upd->execute();
    $upd->close();

    $lisani_conn->commit();
} catch (Throwable $e) {
    $lisani_conn->rollback();
    if ($targetFull && file_exists($targetFull)) {
        @unlink($targetFull);
    }
    error_log('create_refund.php: ' . $e->getMessage());
    aos_fail('Failed to save the refund.');
}

aos_json([
    'ok'      => true,
    'message' => 'Refund saved.',
    'id'      => $refundId,
]);