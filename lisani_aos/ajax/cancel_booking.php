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
// Response: { ok, message, cancel: { booking_id, invoice{id,number,total_before,total_after,
//   paid_amount,overpaid_amount}, items[ {booking_item_id, product_name, unit_label, stock_source,
//           qty_released, qty_cancelled, price} ], total_cancelled_value, credit, dry_run } }
//
// Effects when saved (all or nothing):
//   - logistics.remaining_primary_qty / defective_qty (+ qty_cancelled per line)
//   - booking_items.qty_booked = qty_released (per line)
//   - invoices.total_amount (- sum(qty_cancelled * price))
//   - bookings.status = 'cancelled'
//   - Revised 31 Okt 2026: if the invoice had been paid MORE than its new,
//     smaller total_amount (a DP that now overshoots what's actually owed),
//     the overpaid portion is pulled off invoices.paid_amount (capped down
//     to the new total_amount) and added to customers.credit_balance.
//     invoices.status becomes 'cancelled' (NOT 'paid' — see the inline
//     comment where this happens) so this invoice is never silently reused
//     by a later order.
//   - Revised 4 Nov 2026: also inserts a customer_credit_sources row, traced
//     back to this invoice's real payment(s) via trace_credit_to_real_payments()
//     (credit_sources_helper.php) — same as create_return.php /
//     create_price_adjustment.php, so this credit's origin and FIFO spend are
//     tracked consistently everywhere credit_balance can come from. There
//     used to be a second choice here (credit_action: "credit_balance" |
//     "refund_queue") — removed at the user's request, see create_return.php's
//     header comment for the rationale.

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
require_once __DIR__ . '/credit_sources_helper.php'; // trace_credit_to_real_payments()

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
        'credit'                => $overpaid > 0.0001 ? [
            'balance_before' => $creditBefore,
            'balance_after'  => round($creditBefore + $overpaid, 2),
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
    // smaller total_amount, and add the difference to customers.credit_balance
    // (traced to its real account(s) below).
    //
    // Capping paid_amount here always makes it land exactly equal to
    // total_amount (that's the definition of "overpaid" being resolved) — so
    // this invoice must be closed for real so later code that picks "the
    // customer's open invoice" (a brand new order, for instance) stops
    // reusing and piling onto it. Leaving status untouched (stale 'open')
    // was the first bug found here (31 Okt 2026). Status is 'cancelled', NOT
    // 'paid' (fixed same day, migration_invoice_cancelled_status.sql) — this
    // invoice was never actually paid off, the money just moved to
    // credit_balance; 'paid' would misrepresent that everywhere else in the
    // app that reads invoice status as "really settled". paid_at stays
    // untouched (NULL or whatever it already was) — it means "when was this
    // paid off for real", which never happened here.
    if ($overpaid > 0.0001) {
        $ta = money($totalAfter);
        $st = $lisani_conn->prepare("UPDATE invoices SET paid_amount = ?, status = 'cancelled' WHERE id = ?");
        $st->bind_param('si', $ta, $invoiceId);
        $st->execute();
        $st->close();

        $userId = (int) $_SESSION['user_id'];
        $o = money($overpaid);
        $st = $lisani_conn->prepare('UPDATE customers SET credit_balance = credit_balance + ? WHERE id = ?');
        $st->bind_param('si', $o, $customerId);
        $st->execute();
        $st->close();

        // ---- customer_credit_sources: trace this overpaid DP back to the
        //      real bank account(s) that paid THIS invoice (the booking's
        //      own invoice_payments — there's only one source invoice here,
        //      unlike create_return.php which can mix several), FIFO oldest
        //      first. See trace_credit_to_real_payments() in
        //      credit_sources_helper.php. ----
        $slices = trace_credit_to_real_payments($lisani_conn, $invoiceId, $overpaid);
        if ($slices) {
            $ccsCustomerId = $customerId;
            $ccsBank = $ccsAccNo = $ccsAccName = null;
            $ccsAmount = $ccsRemaining = '0.00';
            $ccsOriginInvoiceId = $invoiceId;
            $ccsOriginPaymentId = null;
            $ccsDate = date('Y-m-d');
            $ccsUserId = $userId;
            $ccsIns = $lisani_conn->prepare(
                'INSERT INTO customer_credit_sources
                   (customer_id, source_bank, source_account_number, source_account_name,
                    amount, remaining_amount, origin_type, origin_ref_invoice_id,
                    origin_invoice_payment_id, created_date, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, \'booking_cancel\', ?, ?, ?, ?)'
            );
            $ccsIns->bind_param(
                'isssssiisi',
                $ccsCustomerId, $ccsBank, $ccsAccNo, $ccsAccName,
                $ccsAmount, $ccsRemaining, $ccsOriginInvoiceId, $ccsOriginPaymentId,
                $ccsDate, $ccsUserId
            );
            foreach ($slices as $slice) {
                if ($slice['amount'] <= 0.0001) {
                    continue;
                }
                $ccsBank = $slice['source_bank'];
                $ccsAccNo = $slice['source_account_number'];
                $ccsAccName = $slice['source_account_name'];
                $ccsAmount = money($slice['amount']);
                $ccsRemaining = $ccsAmount;
                $ccsOriginPaymentId = $slice['origin_invoice_payment_id'];
                $ccsIns->execute();
            }
            $ccsIns->close();
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