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
//   credit_action  "credit_balance" | "refund_queue" — ONLY read/required
//                  when the invoice already has a DP paid on it that is now
//                  MORE than the new, smaller total_amount after cancelling
//                  (overpaid). Omit it on the first dry_run; if the server
//                  comes back with code "credit_action_required" ask the
//                  user which they want and resend with this filled in.
//                  Same two choices as create_return.php / create_price_adjustment.php:
//                  "credit_balance" (old behaviour) or "refund_queue"
//                  (owed back for real, see customer_refund_queue /
//                  create_disbursement.php).
//
// Response: { ok, message, cancel: { booking_id, invoice{id,number,total_before,total_after,
//   paid_amount,overpaid_amount}, items[ {booking_item_id, product_name, unit_label, stock_source,
//           qty_released, qty_cancelled, price} ], total_cancelled_value, credit, refund_queue, dry_run } }
//
// Effects when saved (all or nothing):
//   - logistics.remaining_primary_qty / defective_qty (+ qty_cancelled per line)
//   - booking_items.qty_booked = qty_released (per line)
//   - invoices.total_amount (- sum(qty_cancelled * price))
//   - bookings.status = 'cancelled'
//   - Revised 31 Okt 2026: if the invoice had been paid MORE than its new,
//     smaller total_amount (a DP that now overshoots what's actually owed),
//     the overpaid portion is pulled off invoices.paid_amount (capped down
//     to the new total_amount, so the invoice's own numbers stay internally
//     consistent) and handed to the customer either as credit_balance or as
//     a customer_refund_queue row, per credit_action — see PROJECT_NOTES.md.

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
// Only matters when cancelling would leave the invoice overpaid — empty is
// fine otherwise (see header comment).
$creditAction = post_str('credit_action');
if ($creditAction !== '' && $creditAction !== 'credit_balance' && $creditAction !== 'refund_queue') {
    aos_fail('Invalid choice for how to handle the overpaid amount.');
}

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
    $customerId = (int) $booking['customer_id'];
    $invoiceId  = (int) $booking['invoice_id'];

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

    $st = $lisani_conn->prepare('SELECT id, invoice_number, total_amount, paid_amount FROM invoices WHERE id = ?');
    $st->bind_param('i', $invoiceId);
    $st->execute();
    $invoice = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$invoice) {
        throw new AosCancelBookingError('Invoice for this booking was not found.');
    }
    $totalBefore = (float) $invoice['total_amount'];
    $paidAmount  = (float) $invoice['paid_amount'];
    $totalAfter  = round($totalBefore - $totalCancelled, 2);

    // Already-paid DP that now exceeds what's actually owed after cancelling
    // — needs to go back to the customer somehow (see header comment).
    $overpaid = round($paidAmount - $totalAfter, 2);
    if ($overpaid < 0.0001) {
        $overpaid = 0.0;
    }
    if ($overpaid > 0.0001 && $creditAction === '') {
        throw new AosCancelBookingError(
            'This invoice has already been paid more than what remains after cancelling — choose how to handle the difference.',
            ['code' => 'credit_action_required', 'amount' => $overpaid]
        );
    }

    $customer = null;
    if ($overpaid > 0.0001) {
        $st = $lisani_conn->prepare('SELECT id, credit_balance FROM customers WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $customerId);
        $st->execute();
        $customer = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$customer) {
            throw new AosCancelBookingError('Customer for this booking was not found.');
        }
    }
    $creditBefore = $customer ? (float) $customer['credit_balance'] : 0.0;

    $cancel = [
        'dry_run'    => $dryRun,
        'booking_id' => $bookingId,
        'invoice'    => [
            'id'              => (int) $invoice['id'],
            'number'          => $invoice['invoice_number'],
            'total_before'    => $totalBefore,
            'total_after'     => $totalAfter,
            'paid_amount'     => $paidAmount,
            'overpaid_amount' => $overpaid,
            // Only true once the write actually happens (dry_run preview
            // still shows the invoice's current, pre-write status elsewhere
            // in the UI) — included so the confirm screen can say plainly
            // that this invoice will be closed for good.
            'will_close'      => $overpaid > 0.0001,
        ],
        'items'                 => $lines,
        'total_cancelled_value' => $totalCancelled,
        'credit'                => ($overpaid > 0.0001 && $creditAction === 'credit_balance') ? [
            'balance_before' => $creditBefore,
            'balance_after'  => round($creditBefore + $overpaid, 2),
        ] : null,
        'refund_queue'          => ($overpaid > 0.0001 && $creditAction === 'refund_queue') ? [
            'amount' => $overpaid,
            'status' => 'pending',
        ] : null,
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

    // Overpaid DP: cap this invoice's own paid_amount down to its new,
    // smaller total_amount, and hand the difference to the customer per
    // credit_action (see header comment / "No open invoice" in
    // create_return.php for the same two choices).
    //
    // Capping paid_amount here always makes it land exactly equal to
    // total_amount (that's the definition of "overpaid" being resolved) —
    // so this invoice is, from this point on, genuinely fully paid and must
    // be closed for real: status = 'paid', same formula/fields
    // apply_customer_credit.php uses elsewhere. Leaving status untouched
    // (stale 'open') was the actual bug reported 31 Okt 2026 — the invoice
    // LOOKED closed (paid_amount == total_amount) but the 'open' status
    // column meant later code that picks "the customer's open invoice" (a
    // brand new order, for instance) kept silently reusing and piling onto
    // this already-resolved invoice, producing confused totals.
    if ($overpaid > 0.0001) {
        $ta = money($totalAfter);
        $st = $lisani_conn->prepare("UPDATE invoices SET paid_amount = ?, status = 'paid', paid_at = NOW() WHERE id = ?");
        $st->bind_param('si', $ta, $invoiceId);
        $st->execute();
        $st->close();

        $userId = (int) $_SESSION['user_id'];
        $o = money($overpaid);
        if ($creditAction === 'refund_queue') {
            $qIns = $lisani_conn->prepare(
                "INSERT INTO customer_refund_queue (customer_id, source_type, source_ref, amount, queued_date, created_by)
                 VALUES (?, 'cancel_booking', ?, ?, CURDATE(), ?)"
            );
            $qIns->bind_param('iisi', $customerId, $bookingId, $o, $userId);
            $qIns->execute();
            $qIns->close();
        } else {
            $st = $lisani_conn->prepare('UPDATE customers SET credit_balance = credit_balance + ? WHERE id = ?');
            $st->bind_param('si', $o, $customerId);
            $st->execute();
            $st->close();
        }
    }

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