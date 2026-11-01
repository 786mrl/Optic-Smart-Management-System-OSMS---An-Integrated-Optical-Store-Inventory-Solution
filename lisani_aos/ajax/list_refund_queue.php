<?php
// lisani_aos/ajax/list_refund_queue.php
// Lists customer_refund_queue rows, grouped by customer — used by the
// Disbursement form (Input Transaction tab) when Activity Code "00-A" /
// department "Refund & Settlement" is selected, to let the user tick which
// pending refund(s) a disbursement is actually paying out (one disbursement
// can settle 1+ rows at once, see migration_refund_queue.sql).
//
// GET: status ("pending" default | "returned" | "all"), customer_id (optional filter)
//
// Response: { ok, data: { customers: [
//   { customer_id, customer_name, total_pending,
//     items: [ { id, source_type, source_ref, amount, queued_date, status,
//                transaction_id, returned_at } ] }
// ] } }
// Customers with zero matching rows are simply not included.

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('list_refund_queue.php stray output: ' . $noise);
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
    aos_fail('Session expired. Please log in again.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

$status     = isset($_GET['status']) ? trim((string) $_GET['status']) : 'pending';
$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;

if (!in_array($status, ['pending', 'returned', 'all'], true)) {
    aos_fail('Invalid status.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $sql = "SELECT q.id, q.customer_id, c.customer_name, q.source_type, q.source_ref, q.amount,
                   q.queued_date, q.status, q.transaction_id, q.returned_at
            FROM customer_refund_queue q
            JOIN customers c ON c.id = q.customer_id
            WHERE 1 = 1";
    $types  = '';
    $params = [];
    if ($status !== 'all') {
        $sql .= ' AND q.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($customerId > 0) {
        $sql .= ' AND q.customer_id = ?';
        $types .= 'i';
        $params[] = $customerId;
    }
    $sql .= ' ORDER BY c.customer_name ASC, q.queued_date ASC, q.id ASC';

    $st = $lisani_conn->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result();

    $byCustomer = [];
    while ($row = $res->fetch_assoc()) {
        $cid = (int) $row['customer_id'];
        if (!isset($byCustomer[$cid])) {
            $byCustomer[$cid] = [
                'customer_id'   => $cid,
                'customer_name' => $row['customer_name'],
                'total_pending' => 0.0,
                'items'         => [],
            ];
        }
        $amount = (float) $row['amount'];
        if ($row['status'] === 'pending') {
            $byCustomer[$cid]['total_pending'] = round($byCustomer[$cid]['total_pending'] + $amount, 2);
        }
        $byCustomer[$cid]['items'][] = [
            'id'             => (int) $row['id'],
            'source_type'    => $row['source_type'],
            'source_ref'     => $row['source_ref'] !== null ? (int) $row['source_ref'] : null,
            'amount'         => $amount,
            'queued_date'    => $row['queued_date'],
            'status'         => $row['status'],
            'transaction_id' => $row['transaction_id'] !== null ? (int) $row['transaction_id'] : null,
            'returned_at'    => $row['returned_at'],
        ];
    }
    $st->close();

    aos_json(['ok' => true, 'data' => ['customers' => array_values($byCustomer)]]);
} catch (Throwable $e) {
    error_log('list_refund_queue.php: ' . $e->getMessage());
    aos_fail('Failed to load the refund queue.');
}
