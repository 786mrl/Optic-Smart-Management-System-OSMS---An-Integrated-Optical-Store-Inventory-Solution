<?php
// lisani_aos/ajax/list_customer_bookings.php
// Lists bookings for one customer, newest first, each with its items and how
// much of each is still reserved-but-not-released (qty_remaining = qty_booked
// - qty_released). Used by the Customers tab (Sales Transaction) to show an
// "Active Bookings" section with a Release action per booking.
//
// GET: customer_id
//
// Response: { ok, data: [ { id, booking_date, status, invoice_id, invoice_number,
//   invoice_total_amount, invoice_paid_amount, invoice_payment_count,
//   items: [ { booking_item_id, logistic_id, product_name, unit_label, stock_source,
//              qty_booked, qty_released, qty_remaining, price } ] } ] }
//
// invoice_payment_count = COUNT(*) of invoice_payments for that invoice so
// far — the frontend's "+ Add Payment" button needs this (not just the
// amounts) to word the auto-filled note correctly ("First/Second/... payment
// for invoice ..."), the same way list_customer_orders.php's inv.payments
// does for every other invoice.

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('list_customer_bookings.php stray output: ' . $noise);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
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

$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

try {
    $st = $lisani_conn->prepare(
        'SELECT b.id, b.booking_date, b.status, b.invoice_id, i.invoice_number,
                i.total_amount, i.paid_amount
         FROM bookings b
         JOIN invoices i ON i.id = b.invoice_id
         WHERE b.customer_id = ?
         ORDER BY b.id DESC'
    );
    $st->bind_param('i', $customerId);
    $st->execute();
    $res = $st->get_result();
    $bookings = [];
    $byId = [];
    while ($row = $res->fetch_assoc()) {
        $b = [
            'id'                    => (int) $row['id'],
            'booking_date'          => $row['booking_date'],
            'status'                => $row['status'],
            'invoice_id'            => (int) $row['invoice_id'],
            'invoice_number'        => $row['invoice_number'],
            'invoice_total_amount'  => (float) $row['total_amount'],
            'invoice_paid_amount'   => (float) $row['paid_amount'],
            'items'                 => [],
        ];
        $bookings[] = $b;
        $byId[(int) $row['id']] = count($bookings) - 1;
    }
    $st->close();

    if ($bookings) {
        $ids = array_keys($byId);
        $marks = implode(',', array_fill(0, count($ids), '?'));

        $invoiceIds = [];
        foreach ($bookings as $b) {
            $invoiceIds[$b['invoice_id']] = true;
        }
        $invoiceIds = array_keys($invoiceIds);
        $invMarks = implode(',', array_fill(0, count($invoiceIds), '?'));
        $payCounts = [];
        $st = $lisani_conn->prepare(
            'SELECT invoice_id, COUNT(*) AS total FROM invoice_payments WHERE invoice_id IN (' . $invMarks . ') GROUP BY invoice_id'
        );
        $st->bind_param(str_repeat('i', count($invoiceIds)), ...$invoiceIds);
        $st->execute();
        $pr = $st->get_result();
        while ($row = $pr->fetch_assoc()) {
            $payCounts[(int) $row['invoice_id']] = (int) $row['total'];
        }
        $st->close();
        foreach ($bookings as $i => $b) {
            $bookings[$i]['invoice_payment_count'] = $payCounts[$b['invoice_id']] ?? 0;
        }

        $st = $lisani_conn->prepare(
            'SELECT bi.id, bi.booking_id, bi.logistic_id, bi.stock_source,
                    bi.qty_booked, bi.qty_released, bi.price,
                    l.product_name, l.primary_unit_label
             FROM booking_items bi
             JOIN logistics l ON l.id = bi.logistic_id
             WHERE bi.booking_id IN (' . $marks . ')
             ORDER BY bi.id'
        );
        $st->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st->execute();
        $r2 = $st->get_result();
        while ($row = $r2->fetch_assoc()) {
            $bid = (int) $row['booking_id'];
            if (!isset($byId[$bid])) {
                continue;
            }
            $qtyBooked   = (float) $row['qty_booked'];
            $qtyReleased = (float) $row['qty_released'];
            $bookings[$byId[$bid]]['items'][] = [
                'booking_item_id' => (int) $row['id'],
                'logistic_id'     => (int) $row['logistic_id'],
                'product_name'    => $row['product_name'],
                'unit_label'      => $row['primary_unit_label'],
                'stock_source'    => $row['stock_source'],
                'qty_booked'      => $qtyBooked,
                'qty_released'    => $qtyReleased,
                'qty_remaining'   => round($qtyBooked - $qtyReleased, 2),
                'price'           => (float) $row['price'],
            ];
        }
        $st->close();
    }

    aos_json(['ok' => true, 'data' => $bookings]);
} catch (Throwable $e) {
    error_log('list_customer_bookings.php: ' . $e->getMessage());
    aos_fail('Failed to load bookings.');
}