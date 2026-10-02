<?php
// lisani_aos/ajax/apply_customer_credit.php
// Applies part/all of a customer's credit_balance (see create_refund.php /
// PROJECT_NOTES.md, 1 Okt 2026) to reduce one of their OPEN invoices.
// Recorded as a normal invoice_payments row (payment_method = "CREDIT
// BALANCE", no proof file — nothing was uploaded, it's not new cash) so it
// shows up in that invoice's existing payment history.
// Unlike create_invoice_payment.php, this does NOT touch customers.total_paid
// — no new cash came in, this just reallocates credit already on the books.
//
// Response contract: { ok: true|false, message: "...", ... }

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('apply_customer_credit.php stray output: ' . $noise);
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

$customerId = (int) post_str('customer_id');
$invoiceId  = (int) post_str('invoice_id');
$notes      = mb_strtoupper(post_str('notes'));

if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}
if ($invoiceId <= 0) {
    aos_fail('Invoice is missing.');
}
if (mb_strlen($notes) > 500) {
    aos_fail('Notes is too long (max 500 characters).');
}

$amountRaw = clean_decimal(post_str('amount'));
if ($amountRaw === null || (float)$amountRaw <= 0) {
    aos_fail('Amount is not valid.');
}
$amount = (float) $amountRaw;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    $stmt = $lisani_conn->prepare('SELECT id, credit_balance FROM customers WHERE id = ? FOR UPDATE');
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

    $stmt = $lisani_conn->prepare(
        "SELECT id, customer_id, total_amount, paid_amount
         FROM invoices WHERE id = ? AND customer_id = ? AND status = 'open' FOR UPDATE"
    );
    $stmt->bind_param('ii', $invoiceId, $customerId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice) {
        $lisani_conn->rollback();
        aos_fail('Selected invoice is not an open invoice for this customer.');
    }

    $totalAmount = (float) $invoice['total_amount'];
    $paidAmount  = (float) $invoice['paid_amount'];
    $newPaid     = round($paidAmount + $amount, 2);
    if ($newPaid > $totalAmount + 0.01) {
        $outstanding = round($totalAmount - $paidAmount, 2);
        $lisani_conn->rollback();
        aos_fail('Amount exceeds this invoice\'s outstanding balance (' . number_format($outstanding, 2) . ').');
    }
    $newStatus = ($newPaid >= $totalAmount - 0.01) ? 'paid' : 'open';

    $userId = (int) $_SESSION['user_id'];
    $amountStr = (string) $amount;
    $today = date('Y-m-d');

    $ins = $lisani_conn->prepare(
        'INSERT INTO invoice_payments
           (invoice_id, customer_id, payment_date, amount, payment_method, notes,
            proof_path, proof_original_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?)'
    );
    $method = 'CREDIT BALANCE';
    $ins->bind_param('iissssi', $invoiceId, $customerId, $today, $amountStr, $method, $notes, $userId);
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

    // NOT total_paid — no new cash came in, this just moves existing credit
    // from the pool onto this specific invoice.
    $updCust = $lisani_conn->prepare('UPDATE customers SET credit_balance = credit_balance - ? WHERE id = ?');
    $updCust->bind_param('si', $amountStr, $customerId);
    $updCust->execute();
    $updCust->close();

    $lisani_conn->commit();
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('apply_customer_credit.php: ' . $e->getMessage());
    aos_fail('Failed to apply the credit.');
}

aos_json([
    'ok'             => true,
    'message'        => 'Credit applied.',
    'payment_id'     => $paymentId,
    'invoice_status' => $newStatus,
]);
