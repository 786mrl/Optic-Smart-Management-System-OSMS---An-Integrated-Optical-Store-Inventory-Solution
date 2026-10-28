<?php
// lisani_aos/ajax/edit_booking.php
// Edits the quantities on an ACTIVE booking. Scope is deliberately narrow:
// this only changes qty_booked on the booking's EXISTING lines — it cannot
// add a new product or remove a line outright (set a line's qty down to its
// qty_released, effectively 0 if nothing has been released yet, to drop it
// to "nothing left to release"). Price per line is not editable here either
// (it was fixed when the booking was created / first priced).
//
// Same dry_run / confirm pattern as create_booking.php.
//
// POST:
//   booking_id
//   items     JSON  [ {"booking_item_id": 5, "qty": 25}, ... ]
//                   MUST list every booking_item belonging to this booking,
//                   no more, no less (so there's never ambiguity about a
//                   line that was silently left out). Each qty must be >=
//                   that line's qty_released (can't shrink below what's
//                   already actually gone out) and > 0 is NOT required —
//                   0 is allowed when qty_released is 0 (line canceled).
//   dry_run   "1" | "0"
//
// Response: { ok, message, edit: { booking_id, invoice{id,number,total_before,total_after},
//   items[ {booking_item_id, product_name, unit_label, qty_before, qty_after, delta,
//           stock_source, remaining_before, remaining_after} ], dry_run } }
//
// Effects when saved (all or nothing):
//   - logistics.remaining_primary_qty / defective_qty adjusted by -delta
//     per line (delta > 0 reserves more, delta < 0 un-reserves)
//   - booking_items.qty_booked = qty_after
//   - invoices.total_amount adjusted by sum(delta * price)

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AosEditBookingError extends Exception
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
        error_log('edit_booking.php stray output: ' . $noise);
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

function clean_number(string $raw): ?float
{
    $raw = str_replace(',', '', trim($raw));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return (float) $raw;
}

function money(float $n): string
{
    return number_format($n, 2, '.', '');
}

function fmt_qty(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

$bookingId = (int) post_str('booking_id');
$dryRun    = post_str('dry_run') !== '0';

if ($bookingId <= 0) {
    aos_fail('Booking is missing.');
}

$rawItems = json_decode(post_str('items'), true);
if (!is_array($rawItems) || count($rawItems) === 0) {
    aos_fail('No product lines were sent.');
}
if (count($rawItems) > 50) {
    aos_fail('At most 50 lines can be edited at once.');
}

$qtyByItemId = [];
foreach ($rawItems as $it) {
    $bid = isset($it['booking_item_id']) ? (int) $it['booking_item_id'] : 0;
    $qty = isset($it['qty']) ? clean_number((string) $it['qty']) : null;
    if ($bid <= 0 || $qty === null || $qty < 0 || $qty > 99999.99) {
        aos_fail('Every line needs a valid quantity (0 or more).');
    }
    if (isset($qtyByItemId[$bid])) {
        aos_fail('The same booking line was sent twice.');
    }
    $qtyByItemId[$bid] = round($qty, 2);
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
        throw new AosEditBookingError('Booking was not found.');
    }
    if ($booking['status'] !== 'active') {
        throw new AosEditBookingError('This booking is no longer active (' . $booking['status'] . ').');
    }
    $customerId = (int) $booking['customer_id'];
    $invoiceId  = (int) $booking['invoice_id'];

    // ---- Every booking_item of this booking (locked, id order) — the
    //      payload must cover exactly this set, see the header comment ----
    $st = $lisani_conn->prepare(
        'SELECT bi.id, bi.logistic_id, bi.stock_source, bi.qty_booked, bi.qty_released, bi.price,
                l.product_name, l.primary_unit_label, l.remaining_primary_qty, l.defective_qty
         FROM booking_items bi
         JOIN logistics l ON l.id = bi.logistic_id
         WHERE bi.booking_id = ?
         ORDER BY bi.id
         FOR UPDATE'
    );
    $st->bind_param('i', $bookingId);
    $st->execute();
    $items = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[(int) $row['id']] = $row;
    }
    $st->close();

    $existingIds = array_keys($items);
    sort($existingIds);
    $payloadIds = array_keys($qtyByItemId);
    sort($payloadIds);
    if ($existingIds !== $payloadIds) {
        throw new AosEditBookingError('The edit must include every product line of this booking, exactly once each.');
    }

    $lines    = [];
    $errors   = [];
    $deltaTotal = 0.0;
    foreach ($existingIds as $iid) {
        $it        = $items[$iid];
        $qtyBefore = (float) $it['qty_booked'];
        $qtyAfter  = $qtyByItemId[$iid];
        $released  = (float) $it['qty_released'];
        $price     = (float) $it['price'];
        $ss        = $it['stock_source'];

        if ($qtyAfter < $released - 0.0001) {
            $errors[] = $it['product_name'] . ': cannot go below ' . fmt_qty($released)
                . ' ' . $it['primary_unit_label'] . ' — that much has already been released.';
            continue;
        }

        $delta = round($qtyAfter - $qtyBefore, 2);
        $remainingBefore = $ss === 'defective' ? (float) $it['defective_qty'] : (float) $it['remaining_primary_qty'];

        if ($delta > 0 && $delta > $remainingBefore + 0.0001) {
            $errors[] = 'Not enough ' . ($ss === 'defective' ? 'defective stock' : 'stock') . ' for ' . $it['product_name']
                . ': need ' . fmt_qty($delta) . ' more, remaining ' . fmt_qty($remainingBefore) . ' ' . $it['primary_unit_label'] . '.';
            continue;
        }

        $deltaTotal = round($deltaTotal + ($delta * $price), 2);
        $lines[] = [
            'booking_item_id'  => $iid,
            'logistic_id'      => (int) $it['logistic_id'],
            'product_name'     => $it['product_name'],
            'unit_label'       => $it['primary_unit_label'],
            'stock_source'     => $ss,
            'qty_before'       => $qtyBefore,
            'qty_after'        => $qtyAfter,
            'delta'            => $delta,
            'remaining_before' => $remainingBefore,
            'remaining_after'  => round($remainingBefore - $delta, 2),
        ];
    }
    if ($errors) {
        throw new AosEditBookingError(implode("\n", $errors));
    }

    $st = $lisani_conn->prepare('SELECT id, invoice_number, total_amount FROM invoices WHERE id = ?');
    $st->bind_param('i', $invoiceId);
    $st->execute();
    $invoice = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$invoice) {
        throw new AosEditBookingError('Invoice for this booking was not found.');
    }
    $totalBefore = (float) $invoice['total_amount'];

    $edit = [
        'dry_run'    => $dryRun,
        'booking_id' => $bookingId,
        'invoice'    => [
            'id'           => (int) $invoice['id'],
            'number'       => $invoice['invoice_number'],
            'total_before' => $totalBefore,
            'total_after'  => round($totalBefore + $deltaTotal, 2),
        ],
        'items' => $lines,
    ];

    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Edit is ready to be saved.', 'edit' => $edit]);
    }

    // ================= WRITES =================
    $itemUpd = $lisani_conn->prepare('UPDATE booking_items SET qty_booked = ? WHERE id = ?');
    $logUpdNormal = $lisani_conn->prepare(
        'UPDATE logistics SET remaining_primary_qty = remaining_primary_qty - ? WHERE id = ?'
    );
    $logUpdDefective = $lisani_conn->prepare(
        'UPDATE logistics SET defective_qty = defective_qty - ? WHERE id = ?'
    );
    foreach ($lines as $ln) {
        if (abs($ln['delta']) > 0.0001) {
            $d = money($ln['delta']);
            if ($ln['stock_source'] === 'defective') {
                $logUpdDefective->bind_param('si', $d, $ln['logistic_id']);
                $logUpdDefective->execute();
            } else {
                $logUpdNormal->bind_param('si', $d, $ln['logistic_id']);
                $logUpdNormal->execute();
            }
        }
        $q = money($ln['qty_after']);
        $itemUpd->bind_param('si', $q, $ln['booking_item_id']);
        $itemUpd->execute();
    }
    $itemUpd->close();
    $logUpdNormal->close();
    $logUpdDefective->close();

    if (abs($deltaTotal) > 0.0001) {
        $dt = money($deltaTotal);
        $st = $lisani_conn->prepare('UPDATE invoices SET total_amount = total_amount + ? WHERE id = ?');
        $st->bind_param('si', $dt, $invoiceId);
        $st->execute();
        $st->close();
    }

    $lisani_conn->commit();

    aos_json(['ok' => true, 'message' => 'Booking updated.', 'edit' => $edit]);
} catch (AosEditBookingError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('edit_booking.php: ' . $e->getMessage());
    aos_fail('Failed to update the booking.');
}
