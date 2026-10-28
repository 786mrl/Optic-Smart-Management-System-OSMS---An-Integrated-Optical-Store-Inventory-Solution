<?php
// lisani_aos/ajax/cancel_booking.php
// Cancels the UNRELEASED remainder of a booking — whatever has already been
// released (real logistic_movements, already-dispatched goods) is left
// exactly as-is; only the still-reserved qty on each line is given back to
// stock. Each booking_item's qty_booked is set down to its qty_released
// (closing the line at whatever was actually fulfilled), and the booking's
// status becomes 'cancelled'. There is no partial/per-line cancel — this
// cancels everything still outstanding on the booking in one go (use Edit
// instead for a partial qty change on an active booking).
//
// Same dry_run / confirm pattern as the other booking endpoints.
//
// POST: booking_id, dry_run ("1" | "0")
//
// Response: { ok, message, cancel: { booking_id, invoice{id,number,total_before,total_after},
//   items[ {booking_item_id, product_name, unit_label, stock_source,
//           qty_released, qty_cancelled, price} ], total_cancelled_value, dry_run } }
//
// Effects when saved (all or nothing):
//   - logistics.remaining_primary_qty / defective_qty (+ qty_cancelled per line)
//   - booking_items.qty_booked = qty_released (per line)
//   - invoices.total_amount (- sum(qty_cancelled * price))
//   - bookings.status = 'cancelled'

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AosCancelBookingError extends Exception
{
    public $payload;

    public function __construct(string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->payload = array_merge(['ok' => false, 'message' => $message], $extra);
    }
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('cancel_booking.php stray output: ' . $noise);
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aos_fail('Invalid request.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

function post_str(string $key): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
}

function money(float $n): string
{
    return number_format($n, 2, '.', '');
}

$bookingId = (int) post_str('booking_id');
$dryRun    = post_str('dry_run') !== '0';

if ($bookingId <= 0) {
    aos_fail('Booking is missing.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    $st = $lisani_conn->prepare('SELECT id, customer_id, invoice_id, status FROM bookings WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $bookingId);
    $st->execute();
    $booking = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$booking) {
        throw new AosCancelBookingError('Booking was not found.');
    }
    if ($booking['status'] !== 'active') {
        throw new AosCancelBookingError('This booking is no longer active (' . $booking['status'] . ').');
    }
    $invoiceId = (int) $booking['invoice_id'];

    $st = $lisani_conn->prepare(
        'SELECT bi.id, bi.logistic_id, bi.stock_source, bi.qty_booked, bi.qty_released, bi.price,
                l.product_name, l.primary_unit_label
         FROM booking_items bi
         JOIN logistics l ON l.id = bi.logistic_id
         WHERE bi.booking_id = ?
         ORDER BY bi.id
         FOR UPDATE'
    );
    $st->bind_param('i', $bookingId);
    $st->execute();
    $res = $st->get_result();
    $lines = [];
    $totalCancelled = 0.0;
    while ($row = $res->fetch_assoc()) {
        $cancelled = round((float) $row['qty_booked'] - (float) $row['qty_released'], 2);
        if ($cancelled <= 0.0001) {
            continue; // already fully released — nothing left to cancel on this line
        }
        $price = (float) $row['price'];
        $totalCancelled = round($totalCancelled + ($cancelled * $price), 2);
        $lines[] = [
            'booking_item_id' => (int) $row['id'],
            'logistic_id'     => (int) $row['logistic_id'],
            'product_name'    => $row['product_name'],
            'unit_label'      => $row['primary_unit_label'],
            'stock_source'    => $row['stock_source'],
            'qty_released'    => (float) $row['qty_released'],
            'qty_cancelled'   => $cancelled,
            'price'           => $price,
        ];
    }
    $st->close();

    if (!$lines) {
        throw new AosCancelBookingError('Nothing left to cancel \u2014 this booking is already fully released.');
    }

    $st = $lisani_conn->prepare('SELECT id, invoice_number, total_amount FROM invoices WHERE id = ?');
    $st->bind_param('i', $invoiceId);
    $st->execute();
    $invoice = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$invoice) {
        throw new AosCancelBookingError('Invoice for this booking was not found.');
    }
    $totalBefore = (float) $invoice['total_amount'];

    $cancel = [
        'dry_run'    => $dryRun,
        'booking_id' => $bookingId,
        'invoice'    => [
            'id'           => (int) $invoice['id'],
            'number'       => $invoice['invoice_number'],
            'total_before' => $totalBefore,
            'total_after'  => round($totalBefore - $totalCancelled, 2),
        ],
        'items'                 => $lines,
        'total_cancelled_value' => $totalCancelled,
    ];

    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Cancel is ready to be saved.', 'cancel' => $cancel]);
    }

    // ================= WRITES =================
    $itemUpd = $lisani_conn->prepare('UPDATE booking_items SET qty_booked = qty_released WHERE id = ?');
    $logUpdNormal = $lisani_conn->prepare(
        'UPDATE logistics SET remaining_primary_qty = remaining_primary_qty + ? WHERE id = ?'
    );
    $logUpdDefective = $lisani_conn->prepare(
        'UPDATE logistics SET defective_qty = defective_qty + ? WHERE id = ?'
    );
    foreach ($lines as $ln) {
        $q = money($ln['qty_cancelled']);
        if ($ln['stock_source'] === 'defective') {
            $logUpdDefective->bind_param('si', $q, $ln['logistic_id']);
            $logUpdDefective->execute();
        } else {
            $logUpdNormal->bind_param('si', $q, $ln['logistic_id']);
            $logUpdNormal->execute();
        }
        $itemUpd->bind_param('i', $ln['booking_item_id']);
        $itemUpd->execute();
    }
    $itemUpd->close();
    $logUpdNormal->close();
    $logUpdDefective->close();

    $tc = money($totalCancelled);
    $st = $lisani_conn->prepare('UPDATE invoices SET total_amount = total_amount - ? WHERE id = ?');
    $st->bind_param('si', $tc, $invoiceId);
    $st->execute();
    $st->close();

    $st = $lisani_conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
    $st->bind_param('i', $bookingId);
    $st->execute();
    $st->close();

    $lisani_conn->commit();

    aos_json(['ok' => true, 'message' => 'Booking cancelled.', 'cancel' => $cancel]);
} catch (AosCancelBookingError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('cancel_booking.php: ' . $e->getMessage());
    aos_fail('Failed to cancel the booking.');
}
