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
// Optional bank_fee (8 Nov 2026): transfer fee the bank deducted on top of
// the refund itself. Tracked in its own table (refund_bank_fees) so it can
// be isolated in reports; it is NOT subtracted from $amount here — the
// client (Transfer capture form) already sends $amount as the NET refund,
// having subtracted the fee from the OCR-scanned gross slip total itself.
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

// Bank Fee is OPTIONAL (defaults to 0). This is NOT part of $amount above —
// $amount is already the NET refund (what reduces the customer's
// credit_balance, unchanged contract from before this feature). The fee is
// purely a record of what the bank additionally deducted from the company's
// source account for this transfer, kept in its own table
// (refund_bank_fees) so the Ledger/Finance Report can isolate it separately
// from the refund itself (user request, 8 Nov 2026). Empty/missing ->
// treated as 0, same as how Source Bank etc. are optional above.
$bankFeeRaw = post_str('bank_fee');
if ($bankFeeRaw === '') {
    $bankFee = 0.0;
} else {
    $bankFeeClean = clean_decimal($bankFeeRaw);
    if ($bankFeeClean === null || (float)$bankFeeClean < 0) {
        aos_fail('Bank Fee is not valid.');
    }
    $bankFee = (float) $bankFeeClean;
}

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

    // ---------- Record the bank fee (if any), separate table ----------
    // See the comment above $bankFeeRaw: this is purely informational / for
    // reporting, does not touch customers.credit_balance or .total_paid
    // (those are adjusted by $amount below, same as always).
    if ($bankFee > 0.0001) {
        $feeIns = $lisani_conn->prepare(
            'INSERT INTO refund_bank_fees (invoice_refund_id, fee_amount) VALUES (?, ?)'
        );
        $bankFeeStr = (string) round($bankFee, 2);
        $feeIns->bind_param('is', $refundId, $bankFeeStr);
        $feeIns->execute();
        $feeIns->close();
    }

    // ---------- Trace this refund back to its credit_source(s), FIFO ----------
    // Mirrors how customer_credit_applications already attributes credit
    // used toward an invoice (create_order.php) — this is the same idea for
    // credit refunded as cash, so the Ledger Report can show which
    // "Customer Credit Balance" row a refund belongs to (user request, 8
    // Nov 2026). Oldest source first. Best-effort only: if this customer's
    // credit_balance includes money from before customer_credit_sources
    // existed (migration_customer_credit_sources.sql), or the sources'
    // remaining_amount total is otherwise short, the untraceable portion is
    // simply not recorded here — customers.credit_balance below stays the
    // real source of truth for the refund amount regardless, same as it
    // always was before this table existed.
    $toAllocate = $amount;
    $srcStmt = $lisani_conn->prepare(
        'SELECT id, remaining_amount FROM customer_credit_sources
         WHERE customer_id = ? AND remaining_amount > 0
         ORDER BY created_date ASC, id ASC FOR UPDATE'
    );
    $srcStmt->bind_param('i', $customerId);
    $srcStmt->execute();
    $sources = $srcStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $srcStmt->close();

    if ($sources) {
        $refIns = $lisani_conn->prepare(
            'INSERT INTO customer_credit_refunds
               (invoice_refund_id, credit_source_id, amount_refunded, remaining_after_amount)
             VALUES (?, ?, ?, ?)'
        );
        $updSrc = $lisani_conn->prepare(
            'UPDATE customer_credit_sources SET remaining_amount = ? WHERE id = ?'
        );
        foreach ($sources as $src) {
            if ($toAllocate <= 0.0001) {
                break;
            }
            $available = (float) $src['remaining_amount'];
            $take = min($available, $toAllocate);
            $srcId = (int) $src['id'];
            $takeStr = (string) round($take, 2);
            $newRemainingStr = (string) round($available - $take, 2);

            $refIns->bind_param('iiss', $refundId, $srcId, $takeStr, $newRemainingStr);
            $refIns->execute();

            $updSrc->bind_param('si', $newRemainingStr, $srcId);
            $updSrc->execute();

            $toAllocate = round($toAllocate - $take, 2);
        }
        $refIns->close();
        $updSrc->close();
    }

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