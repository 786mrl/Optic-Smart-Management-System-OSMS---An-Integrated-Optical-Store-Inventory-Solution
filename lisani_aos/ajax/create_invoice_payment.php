<?php
// lisani_aos/ajax/create_invoice_payment.php
// Saves one payment against an invoice: proof file + row in
// `invoice_payments`, then updates `invoices.paid_amount`/`status`/`paid_at`
// and `customers.total_paid` (all in one DB transaction).
// Called from transaction_content.php (btnPayCapSave) with multipart FormData.
// Same shape/pattern as create_disbursement.php — see PROJECT_NOTES.md.
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
        error_log('create_invoice_payment.php stray output: ' . $noise);
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
    http_response_code(401);
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

// Identical to create_customer.php's helper — needed here so a payment's
// proof lands in the SAME folder that file already created for this
// customer, instead of a differently-sanitized near-miss.
function sanitize_folder_name(string $name): string
{
    $clean = str_replace(['/', '\\'], ' ', $name);
    $clean = preg_replace('/\.\.+/', '', $clean);
    $clean = preg_replace('/[^A-Za-z0-9 _-]/', '', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    return $clean;
}

// ---------- Read + validate input ----------
$invoiceId = (int) post_str('invoice_id');
$paymentDate = post_str('payment_date');
$sourceBank              = mb_strtoupper(post_str('source_bank'));
$sourceAccountName       = mb_strtoupper(post_str('source_account_name'));
$destinationBank         = mb_strtoupper(post_str('destination_bank'));
$destinationAccountNumber = mb_strtoupper(post_str('destination_account_number'));
$destinationAccountName  = mb_strtoupper(post_str('destination_account_name'));
$notes = mb_strtoupper(post_str('notes'));

if ($invoiceId <= 0) {
    aos_fail('Invoice is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate) {
    aos_fail('Payment Date is not valid.');
}

// Column widths per DESCRIBE invoice_payments (see PROJECT_NOTES.md migration).
$bankFields = [
    'Source Bank' => [$sourceBank, 100],
    'Source Account Name' => [$sourceAccountName, 150],
    'Destination Bank' => [$destinationBank, 100],
    'Destination Account Number' => [$destinationAccountNumber, 100],
    'Destination Account Name' => [$destinationAccountName, 150],
];
foreach ($bankFields as $label => $pair) {
    if (mb_strlen($pair[0]) > $pair[1]) {
        aos_fail($label . ' is too long (max ' . $pair[1] . ' characters).');
    }
}
if (mb_strlen($notes) > 500) {
    aos_fail('Notes is too long (max 500 characters).');
}

$amountRaw = clean_decimal(post_str('amount'));
if ($amountRaw === null || (float)$amountRaw <= 0) {
    aos_fail('Amount is not valid.');
}
$amount = (float) $amountRaw;

// ---------- Uploaded proof ----------
if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
    $code = isset($_FILES['proof']) ? $_FILES['proof']['error'] : -1;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        aos_fail('The file is larger than the server upload limit.');
    }
    aos_fail('The proof of payment was not uploaded.');
}

$file = $_FILES['proof'];
$maxBytes = 20 * 1024 * 1024;
if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
    aos_fail('The proof of payment must be between 1 byte and 20 MB.');
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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    // Lock the invoice row for the whole read-modify-write so two payments
    // saved at the same moment can't both read the same paid_amount.
    // Joined to customers for year/customer_name — needed to land the proof
    // inside the SAME folder create_customer.php already made for this
    // customer (storage/selling/{year}/{customer_name, sanitized}/...).
    $stmt = $lisani_conn->prepare(
        'SELECT i.id, i.customer_id, i.invoice_number, i.total_amount, i.paid_amount, i.status,
                c.year AS customer_year, c.customer_name
         FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        $lisani_conn->rollback();
        aos_fail('Invoice was not found.');
    }

    $customerId   = (int) $invoice['customer_id'];
    $totalAmount  = (float) $invoice['total_amount'];
    $paidAmount   = (float) $invoice['paid_amount'];
    $newPaid      = round($paidAmount + (float) $amount, 2);

    // Deliberately rejected rather than silently clamped: an amount this far
    // over the outstanding balance usually means the wrong invoice (or wrong
    // amount) was picked. 0.01 tolerance for float rounding only.
    if ($newPaid > $totalAmount + 0.01) {
        $outstanding = round($totalAmount - $paidAmount, 2);
        $lisani_conn->rollback();
        aos_fail('Amount exceeds the outstanding balance (' . number_format($outstanding, 2) . ').');
    }

    $newStatus = ($newPaid >= $totalAmount - 0.01) ? 'paid' : 'open';

    // Same folder create_customer.php already made for this customer:
    // storage/selling/{customers.year}/{customer_name, sanitized+lowercased}/payments/
    // (customers.year, not invoices.period_year — that's the column
    // create_customer.php itself uses to build the path).
    $customerYear = (int) $invoice['customer_year'];
    $folderName = strtolower(sanitize_folder_name((string) $invoice['customer_name']));
    if ($folderName === '') {
        $folderName = 'customer_' . $customerId;
    }
    $relPath = 'selling/' . $customerYear . '/' . $folderName . '/payments';

    $targetDir = AOS_STORAGE_BASE . '/' . $relPath;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        $lisani_conn->rollback();
        aos_fail('Could not create the storage folder for this payment.');
    }

    // File name follows the invoice number + payment date; same combination
    // twice gets _2, _3, ... instead of overwriting.
    $invoiceSlug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $invoice['invoice_number']), '_');
    if ($invoiceSlug === '') {
        $invoiceSlug = 'invoice_' . $invoiceId;
    }
    $baseName = $invoiceSlug . '_' . date('Ymd', strtotime($paymentDate));
    $storedName = $baseName . '.' . $ext;
    for ($n = 2; file_exists($targetDir . '/' . $storedName); $n++) {
        $storedName = $baseName . '_' . $n . '.' . $ext;
    }
    $targetFull = $targetDir . '/' . $storedName;
    $documentPath = $relPath . '/' . $storedName; // relative to AOS_STORAGE_BASE

    if (!move_uploaded_file($file['tmp_name'], $targetFull)) {
        $lisani_conn->rollback();
        aos_fail('Could not save the proof of payment file.');
    }

    $originalName = mb_substr(basename((string) $file['name']), 0, 255);
    $userId = (int) $_SESSION['user_id'];

    $ins = $lisani_conn->prepare(
        'INSERT INTO invoice_payments
           (invoice_id, customer_id, payment_date, amount,
            source_bank, source_account_name,
            destination_bank, destination_account_number, destination_account_name,
            notes, proof_path, proof_original_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param(
        'iisdssssssssi',
        $invoiceId, $customerId, $paymentDate, $amount,
        $sourceBank, $sourceAccountName,
        $destinationBank, $destinationAccountNumber, $destinationAccountName,
        $notes, $documentPath, $originalName, $userId
    );
    $ins->execute();
    $paymentId = $lisani_conn->insert_id;
    $ins->close();

    $paidAtSql = ($newStatus === 'paid') ? 'NOW()' : 'NULL';
    $upd = $lisani_conn->prepare(
        "UPDATE invoices SET paid_amount = ?, status = ?, paid_at = $paidAtSql WHERE id = ?"
    );
    $upd->bind_param('dsi', $newPaid, $newStatus, $invoiceId);
    $upd->execute();
    $upd->close();

    $updCust = $lisani_conn->prepare('UPDATE customers SET total_paid = total_paid + ? WHERE id = ?');
    $updCust->bind_param('di', $amount, $customerId);
    $updCust->execute();
    $updCust->close();

    $lisani_conn->commit();
} catch (Throwable $e) {
    $lisani_conn->rollback();
    if (isset($targetFull) && file_exists($targetFull)) {
        @unlink($targetFull); // don't leave an orphan file behind
    }
    error_log('create_invoice_payment.php: ' . $e->getMessage());
    aos_fail('Failed to save the payment.');
}

aos_json([
    'ok'            => true,
    'message'       => 'Payment saved.',
    'id'            => $paymentId,
    'invoice_status' => $newStatus,
    'paid_amount'   => $newPaid,
]);