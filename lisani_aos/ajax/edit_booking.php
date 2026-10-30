<?php
// lisani_aos/ajax/edit_booking.php
// Edits an ACTIVE booking. Two things can happen in one call, mixed freely:
//   1. Change qty_booked on EXISTING booking lines (cannot remove a line
//      outright — set its qty down to its qty_released, effectively 0 if
//      nothing has been released yet, to drop it to "nothing left to
//      release"). Price per existing line is not editable here (it was
//      fixed when the booking was created / first priced).
//   2. ADD a product that was not part of the original booking. This
//      reserves stock for it exactly like create_booking.php does (same
//      price resolution: customer_item_prices with a fallback to a
//      manually-typed price that then gets saved for this customer; a
//      defective-stock line always needs a manually-typed price). A
//      product/stock_source combination already on the booking cannot be
//      "added" again — that is just editing the existing line's qty.
//
// Same dry_run / confirm pattern as create_booking.php.
//
// POST:
//   booking_id
//   items       JSON  array of line objects, each EITHER:
//                 existing line : {"booking_item_id": 5, "qty": 25}
//                   MUST list every booking_item already on this booking,
//                   no more, no less (so there's never ambiguity about a
//                   line that was silently left out). qty must be >= that
//                   line's qty_released; 0 is allowed when qty_released is 0
//                   (line canceled); > 0 is NOT required.
//                 new line      : {"logistic_id": 10, "qty": 15,
//                                  "stock_source": "normal"}
//                   qty must be > 0. stock_source defaults to "normal" when
//                   omitted. At most 50 lines total (existing + new).
//   new_prices  JSON  { "10": 145000 }  optional — price for a NEW line
//                   whose product has no price yet for this customer (same
//                   semantics as create_booking.php's new_prices).
//   dry_run     "1" | "0"
//
// Response: { ok, message, edit: { booking_id, invoice{id,number,total_before,total_after},
//   items[ {booking_item_id, logistic_id, product_name, unit_label, qty_before, qty_after,
//           delta, stock_source, remaining_before, remaining_after, is_new,
//           price?, price_date?, price_source? } ], dry_run } }
//   ok: false -> message, and for a missing price on a NEW line also
//                code: "missing_prices", missing: [ {logistic_id, product_name, unit_label, stock_source?} ]
//
// Effects when saved (all or nothing):
//   - logistics.remaining_primary_qty / defective_qty adjusted by -delta
//     per line (delta > 0 reserves more, delta < 0 un-reserves); this covers
//     both existing-line changes and the full qty of a new line
//   - booking_items.qty_booked = qty_after (existing lines) or a new row is
//     inserted (qty_booked = qty, qty_released = 0) for new lines
//   - customer_item_prices gets a new row when a new line's price came from
//     new_prices (same as create_booking.php), never for existing lines
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
    aos_fail('At most 50 lines can be edited/added at once.');
}

// ---- Split the payload into "existing line" edits and "new line" adds ----
$qtyByItemId = [];
$newQtyByLid = [];
$newSsByLid  = [];
foreach ($rawItems as $it) {
    $bid = isset($it['booking_item_id']) ? (int) $it['booking_item_id'] : 0;
    $lid = isset($it['logistic_id']) ? (int) $it['logistic_id'] : 0;

    if ($bid > 0 && $lid > 0) {
        aos_fail('A line cannot be both an existing line and a new product.');
    }

    if ($bid > 0) {
        $qty = isset($it['qty']) ? clean_number((string) $it['qty']) : null;
        if ($qty === null || $qty < 0 || $qty > 99999.99) {
            aos_fail('Every line needs a valid quantity (0 or more).');
        }
        if (isset($qtyByItemId[$bid])) {
            aos_fail('The same booking line was sent twice.');
        }
        $qtyByItemId[$bid] = round($qty, 2);
        continue;
    }

    if ($lid > 0) {
        $qty = isset($it['qty']) ? clean_number((string) $it['qty']) : null;
        if ($qty === null || $qty <= 0 || $qty > 99999.99) {
            aos_fail('Every new product needs a quantity above zero.');
        }
        $ss = (isset($it['stock_source']) && $it['stock_source'] === 'defective') ? 'defective' : 'normal';
        if (isset($newSsByLid[$lid]) && $newSsByLid[$lid] !== $ss) {
            aos_fail('The same new product cannot mix normal and defective stock — add it as two separate lines is not supported here, use one.');
        }
        $newSsByLid[$lid]  = $ss;
        $newQtyByLid[$lid] = round(($newQtyByLid[$lid] ?? 0) + $qty, 2);
        continue;
    }

    aos_fail('Every line needs either an existing booking line or a product to add.');
}

$newPrices = [];
$rawNew = json_decode(post_str('new_prices'), true);
if (is_array($rawNew)) {
    foreach ($rawNew as $lid => $val) {
        $p = clean_number((string) $val);
        if ($p !== null && $p > 0 && $p <= 9999999999999.99) {
            $newPrices[(int) $lid] = round($p, 2);
        }
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    $st = $lisani_conn->prepare('SELECT id, customer_id, invoice_id, booking_date, status FROM bookings WHERE id = ? FOR UPDATE');
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
    $customerId  = (int) $booking['customer_id'];
    $invoiceId   = (int) $booking['invoice_id'];
    $bookingDate = $booking['booking_date'];

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
        throw new AosEditBookingError('The edit must include every existing product line of this booking, exactly once each.');
    }

    // A (logistic_id, stock_source) combo already on the booking cannot be
    // "added" again — that is just editing the existing line's qty.
    $existingCombos = [];
    foreach ($items as $it) {
        $existingCombos[(int) $it['logistic_id'] . '|' . $it['stock_source']] = true;
    }

    $newIds = array_keys($newQtyByLid);
    sort($newIds);
    foreach ($newIds as $lid) {
        if (isset($existingCombos[$lid . '|' . $newSsByLid[$lid]])) {
            throw new AosEditBookingError('This product is already on the booking — edit its existing line instead of adding it again.');
        }
    }

    // ---- Lock the logistics rows for any NEW products ----
    $newLogistics = [];
    if ($newIds) {
        $marks = implode(',', array_fill(0, count($newIds), '?'));
        $st = $lisani_conn->prepare(
            'SELECT id, remaining_primary_qty, defective_qty, primary_unit_label, product_name
             FROM logistics
             WHERE id IN (' . $marks . ')
             ORDER BY id
             FOR UPDATE'
        );
        $st->bind_param(str_repeat('i', count($newIds)), ...$newIds);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $newLogistics[(int) $row['id']] = $row;
        }
        $st->close();
        foreach ($newIds as $lid) {
            if (!isset($newLogistics[$lid])) {
                throw new AosEditBookingError('A product you tried to add was not found.');
            }
        }
    }

    $lines      = [];
    $errors     = [];
    $deltaTotal = 0.0;

    // ---- Existing lines: qty change only, price is fixed ----
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
            'is_new'           => false,
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

    // ---- New lines: full reservation + price resolution (same rule as
    //      create_booking.php) ----
    $priceStmt = $lisani_conn->prepare(
        'SELECT price, price_date
         FROM customer_item_prices
         WHERE customer_id = ? AND logistic_id = ? AND price_date <= ?
         ORDER BY price_date DESC, created_at DESC, id DESC
         LIMIT 1'
    );

    $newLines = [];
    $missing  = [];
    foreach ($newIds as $lid) {
        $ss  = $newSsByLid[$lid];
        $qty = $newQtyByLid[$lid];
        $log = $newLogistics[$lid];

        $remainingBefore = $ss === 'defective' ? (float) $log['defective_qty'] : (float) $log['remaining_primary_qty'];
        if ($qty > $remainingBefore + 0.0001) {
            $errors[] = 'Not enough ' . ($ss === 'defective' ? 'defective stock' : 'stock') . ' for ' . $log['product_name']
                . ': requested ' . fmt_qty($qty) . ', remaining ' . fmt_qty($remainingBefore) . ' ' . $log['primary_unit_label'] . '.';
            continue;
        }

        if ($ss === 'normal') {
            $priceStmt->bind_param('iis', $customerId, $lid, $bookingDate);
            $priceStmt->execute();
            $found = $priceStmt->get_result()->fetch_assoc();

            if ($found) {
                $price     = (float) $found['price'];
                $priceDate = $found['price_date'];
                $source    = 'history';
            } elseif (isset($newPrices[$lid])) {
                $price     = $newPrices[$lid];
                $priceDate = $bookingDate;
                $source    = 'new';
            } else {
                $missing[] = [
                    'logistic_id'  => $lid,
                    'product_name' => $log['product_name'],
                    'unit_label'   => $log['primary_unit_label'],
                ];
                continue;
            }
        } else {
            if (isset($newPrices[$lid])) {
                $price     = $newPrices[$lid];
                $priceDate = $bookingDate;
                $source    = 'defective_manual';
            } else {
                $missing[] = [
                    'logistic_id'  => $lid,
                    'product_name' => $log['product_name'],
                    'unit_label'   => $log['primary_unit_label'],
                    'stock_source' => 'defective',
                ];
                continue;
            }
        }

        $total      = round($qty * $price, 2);
        $deltaTotal = round($deltaTotal + $total, 2);
        $newLines[] = [
            'is_new'           => true,
            'booking_item_id'  => null,
            'logistic_id'      => $lid,
            'product_name'     => $log['product_name'],
            'unit_label'       => $log['primary_unit_label'],
            'stock_source'     => $ss,
            'qty_before'       => 0.0,
            'qty_after'        => $qty,
            'delta'            => $qty,
            'price'            => $price,
            'price_date'       => $priceDate,
            'price_source'     => $source,
            'total_price'      => $total,
            'remaining_before' => $remainingBefore,
            'remaining_after'  => round($remainingBefore - $qty, 2),
        ];
    }
    $priceStmt->close();

    if ($errors) {
        throw new AosEditBookingError(implode("\n", $errors));
    }
    if ($missing) {
        throw new AosEditBookingError(
            'Some new products have no price for this customer yet.',
            ['code' => 'missing_prices', 'missing' => $missing]
        );
    }

    $allLines = array_merge($lines, $newLines);

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
        'items' => $allLines,
    ];

    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Edit is ready to be saved.', 'edit' => $edit]);
    }

    // ================= WRITES =================
    $itemUpd = $lisani_conn->prepare('UPDATE booking_items SET qty_booked = ? WHERE id = ?');
    $itemIns = $lisani_conn->prepare(
        'INSERT INTO booking_items (booking_id, logistic_id, stock_source, qty_booked, price)
         VALUES (?, ?, ?, ?, ?)'
    );
    $priceIns = $lisani_conn->prepare(
        'INSERT INTO customer_item_prices (customer_id, logistic_id, price, price_date, unit_label)
         VALUES (?, ?, ?, ?, ?)'
    );
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

    foreach ($newLines as $ln) {
        if ($ln['price_source'] === 'new') {
            $p = money($ln['price']);
            $priceIns->bind_param('iisss', $customerId, $ln['logistic_id'], $p, $bookingDate, $ln['unit_label']);
            $priceIns->execute();
        }

        $q  = money($ln['qty_after']);
        $p  = money($ln['price']);
        $ss = $ln['stock_source'];
        $itemIns->bind_param('iisss', $bookingId, $ln['logistic_id'], $ss, $q, $p);
        $itemIns->execute();

        if (abs($ln['delta']) > 0.0001) {
            $d = money($ln['delta']);
            if ($ss === 'defective') {
                $logUpdDefective->bind_param('si', $d, $ln['logistic_id']);
                $logUpdDefective->execute();
            } else {
                $logUpdNormal->bind_param('si', $d, $ln['logistic_id']);
                $logUpdNormal->execute();
            }
        }
    }

    $itemUpd->close();
    $itemIns->close();
    $priceIns->close();
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