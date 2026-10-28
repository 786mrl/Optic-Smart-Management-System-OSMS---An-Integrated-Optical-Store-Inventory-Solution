<?php
// lisani_aos/ajax/release_booking.php
// Releases some or all of the still-reserved qty on booking_items of ONE
// booking: writes the real logistic_movements 'out' row(s) the goods were
// always going to need, and advances qty_released. Does NOT touch
// logistics.remaining_primary_qty / defective_qty — that stock was already
// taken out of circulation at booking time (create_booking.php); releasing
// just turns the reservation into an actual dispatch.
//
// Same dry_run / confirm pattern as create_order.php / create_booking.php.
//
// POST:
//   booking_id
//   items          JSON  [ {"booking_item_id": 5, "qty": 10}, ... ]
//                        qty may be less than that item's qty_remaining
//                        (partial release) — the rest stays reserved for a
//                        later release. Every booking_item_id must belong to
//                        booking_id.
//   movement_date  (Y-m-d)
//   driver_name, police_number   optional, same free-text fields as an order
//   dry_run        "1" | "0"
//
// Response: { ok, message, release: { booking_id, invoice{id,number,total_after},
//   items[], grand_total, dry_run, movement_ids?, booking_status } }
//
// Effects when saved (all or nothing):
//   - logistic_movements: 1 row per released line (movement_type 'out'),
//     invoice_id = the booking's invoice (so it prints/reports exactly like
//     an ordinary sale from here on)
//   - logistics.total_taken_qty (+), and defective_taken_qty (+) for
//     stock_source='defective' lines — remaining_primary_qty/defective_qty
//     are NOT touched (already reserved)
//   - booking_items.qty_released (+)
//   - booking_item_releases: 1 row per released line, recording which
//     logistic_movements 'out' row this release produced (so a later
//     return against it can be netted off — see
//     migration_booking_item_releases.sql and list_customer_bookings.php)
//   - bookings.status -> 'completed' once every item's qty_released reaches
//     its qty_booked
//   - customers.total_inflow (+ released value) — this is the point where
//     the sale actually counts as inflow (booking time deliberately did not)

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AosReleaseError extends Exception
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
        error_log('release_booking.php stray output: ' . $noise);
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

// ---------- Read + validate input ----------
$bookingId    = (int) post_str('booking_id');
$movementDate = post_str('movement_date');
$driver       = mb_strtoupper(post_str('driver_name'));
$police       = mb_strtoupper(post_str('police_number'));
$dryRun       = post_str('dry_run') !== '0';

if ($bookingId <= 0) {
    aos_fail('Booking is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $movementDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $movementDate) {
    aos_fail('Release date is not valid.');
}
if (mb_strlen($driver) > 150) {
    aos_fail('Driver name is too long (max 150 characters).');
}
if (mb_strlen($police) > 30) {
    aos_fail('Police number is too long (max 30 characters).');
}

$rawItems = json_decode(post_str('items'), true);
if (!is_array($rawItems) || count($rawItems) === 0) {
    aos_fail('Select at least one product line to release.');
}
if (count($rawItems) > 50) {
    aos_fail('At most 50 lines can be released at once.');
}

$qtyByItemId = [];
foreach ($rawItems as $it) {
    $bid = isset($it['booking_item_id']) ? (int) $it['booking_item_id'] : 0;
    $qty = isset($it['qty']) ? clean_number((string) $it['qty']) : null;
    if ($bid <= 0 || $qty === null || $qty <= 0 || $qty > 99999.99) {
        aos_fail('Every release line needs a product and a quantity above zero.');
    }
    if (isset($qtyByItemId[$bid])) {
        aos_fail('The same booking line was sent twice.');
    }
    $qtyByItemId[$bid] = round($qty, 2);
}

$userId = (int) $_SESSION['user_id'];
$driverDb = $driver !== '' ? $driver : null;
$policeDb = $police !== '' ? $police : null;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    // ---- Booking (locked) ----
    $st = $lisani_conn->prepare('SELECT id, customer_id, invoice_id, status FROM bookings WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $bookingId);
    $st->execute();
    $booking = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$booking) {
        throw new AosReleaseError('Booking was not found.');
    }
    if ($booking['status'] !== 'active') {
        throw new AosReleaseError('This booking is no longer active (' . $booking['status'] . ').');
    }
    $customerId = (int) $booking['customer_id'];
    $invoiceId  = (int) $booking['invoice_id'];

    // ---- Customer (locked, keeps total_inflow consistent under concurrency) ----
    $st = $lisani_conn->prepare('SELECT id, customer_name FROM customers WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $customerId);
    $st->execute();
    $customer = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$customer) {
        throw new AosReleaseError('Customer was not found.');
    }

    // ---- Booking items of THIS booking (locked, id order) ----
    $itemIds = array_keys($qtyByItemId);
    sort($itemIds);
    $marks = implode(',', array_fill(0, count($itemIds), '?'));
    $st = $lisani_conn->prepare(
        'SELECT bi.id, bi.booking_id, bi.logistic_id, bi.stock_source,
                bi.qty_booked, bi.qty_released, bi.price,
                l.product_name, l.primary_unit_label
         FROM booking_items bi
         JOIN logistics l ON l.id = bi.logistic_id
         WHERE bi.id IN (' . $marks . ')
         ORDER BY bi.id
         FOR UPDATE'
    );
    $st->bind_param(str_repeat('i', count($itemIds)), ...$itemIds);
    $st->execute();
    $items = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[(int) $row['id']] = $row;
    }
    $st->close();

    $overs = [];
    foreach ($itemIds as $iid) {
        if (!isset($items[$iid]) || (int) $items[$iid]['booking_id'] !== $bookingId) {
            throw new AosReleaseError('A selected line does not belong to this booking.');
        }
        $remaining = round((float) $items[$iid]['qty_booked'] - (float) $items[$iid]['qty_released'], 2);
        if ($qtyByItemId[$iid] > $remaining + 0.0001) {
            $overs[] = 'Cannot release ' . fmt_qty($qtyByItemId[$iid]) . ' of ' . $items[$iid]['product_name']
                . ' — only ' . fmt_qty($remaining) . ' ' . $items[$iid]['primary_unit_label'] . ' still reserved.';
        }
    }
    if ($overs) {
        throw new AosReleaseError(implode("\n", $overs));
    }

    $lines = [];
    $grand = 0.0;
    foreach ($itemIds as $iid) {
        $it    = $items[$iid];
        $qty   = $qtyByItemId[$iid];
        $price = (float) $it['price'];
        $total = round($qty * $price, 2);
        $grand = round($grand + $total, 2);

        $lines[] = [
            'booking_item_id' => $iid,
            'logistic_id'     => (int) $it['logistic_id'],
            'product_name'    => $it['product_name'],
            'unit_label'      => $it['primary_unit_label'],
            'stock_source'    => $it['stock_source'],
            'qty'             => $qty,
            'price'           => $price,
            'total_price'     => $total,
            'qty_remaining_before' => round((float) $it['qty_booked'] - (float) $it['qty_released'], 2),
            'qty_remaining_after'  => round((float) $it['qty_booked'] - (float) $it['qty_released'] - $qty, 2),
        ];
    }

    // ---- Invoice (just for the response — total_amount was already set at
    //      booking time, release does not change it) ----
    $st = $lisani_conn->prepare('SELECT id, invoice_number, total_amount FROM invoices WHERE id = ?');
    $st->bind_param('i', $invoiceId);
    $st->execute();
    $invoice = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$invoice) {
        throw new AosReleaseError('Invoice for this booking was not found.');
    }

    $release = [
        'dry_run'       => $dryRun,
        'booking_id'    => $bookingId,
        'customer'      => ['id' => $customerId, 'name' => $customer['customer_name']],
        'movement_date' => $movementDate,
        'driver_name'   => $driverDb,
        'police_number' => $policeDb,
        'invoice'       => [
            'id'     => (int) $invoice['id'],
            'number' => $invoice['invoice_number'],
        ],
        'items'       => $lines,
        'grand_total' => $grand,
    ];

    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Release is ready to be saved.', 'release' => $release]);
    }

    // ================= WRITES =================
    $movIns = $lisani_conn->prepare(
        "INSERT INTO logistic_movements
           (logistic_id, customer_id, movement_type, stock_source, movement_date, customer_name,
            driver_name, police_number, qty_primary_package, price, total_price,
            invoice_id, created_by)
         VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $itemUpd = $lisani_conn->prepare(
        'UPDATE booking_items SET qty_released = qty_released + ? WHERE id = ?'
    );
    // Records which logistic_movements row this release produced, so a
    // later return against it (source_movement_id) can be netted off when
    // computing "Taken" — see migration_booking_item_releases.sql.
    $relIns = $lisani_conn->prepare(
        'INSERT INTO booking_item_releases (booking_item_id, movement_id, qty) VALUES (?, ?, ?)'
    );
    $logUpdNormal = $lisani_conn->prepare(
        'UPDATE logistics SET total_taken_qty = total_taken_qty + ? WHERE id = ?'
    );
    $logUpdDefective = $lisani_conn->prepare(
        'UPDATE logistics SET total_taken_qty = total_taken_qty + ?, defective_taken_qty = defective_taken_qty + ? WHERE id = ?'
    );

    $movementIds = [];
    foreach ($lines as $ln) {
        $lid = $ln['logistic_id'];
        $q   = money($ln['qty']);
        $p   = money($ln['price']);
        $t   = money($ln['total_price']);
        $ss  = $ln['stock_source'];

        $movIns->bind_param(
            'iissssssssii',
            $lid, $customerId, $ss, $movementDate, $customer['customer_name'],
            $driverDb, $policeDb, $q, $p, $t, $invoiceId, $userId
        );
        $movIns->execute();
        $newMovementId = (int) $lisani_conn->insert_id;
        $movementIds[] = $newMovementId;

        $relIns->bind_param('iis', $ln['booking_item_id'], $newMovementId, $q);
        $relIns->execute();

        if ($ss === 'defective') {
            $logUpdDefective->bind_param('ssi', $q, $q, $lid);
            $logUpdDefective->execute();
        } else {
            $logUpdNormal->bind_param('si', $q, $lid);
            $logUpdNormal->execute();
        }

        $itemUpd->bind_param('di', $ln['qty'], $ln['booking_item_id']);
        $itemUpd->execute();
    }
    $movIns->close();
    $itemUpd->close();
    $relIns->close();
    $logUpdNormal->close();
    $logUpdDefective->close();

    if ($movementIds) {
        $batchId = min($movementIds);
        $stampMarks = implode(',', array_fill(0, count($movementIds), '?'));
        $st = $lisani_conn->prepare(
            'UPDATE logistic_movements SET batch_id = ? WHERE id IN (' . $stampMarks . ')'
        );
        $st->bind_param('i' . str_repeat('i', count($movementIds)), $batchId, ...$movementIds);
        $st->execute();
        $st->close();
    }

    $g = money($grand);
    $st = $lisani_conn->prepare('UPDATE customers SET total_inflow = total_inflow + ? WHERE id = ?');
    $st->bind_param('si', $g, $customerId);
    $st->execute();
    $st->close();

    // ---- Booking completion check: every item of this booking fully released? ----
    $st = $lisani_conn->prepare(
        'SELECT COALESCE(SUM(qty_booked - qty_released), 0) AS still_open
         FROM booking_items WHERE booking_id = ?'
    );
    $st->bind_param('i', $bookingId);
    $st->execute();
    $stillOpen = (float) $st->get_result()->fetch_assoc()['still_open'];
    $st->close();

    $bookingStatus = 'active';
    if ($stillOpen <= 0.0001) {
        $bookingStatus = 'completed';
        $st = $lisani_conn->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
        $st->bind_param('i', $bookingId);
        $st->execute();
        $st->close();
    }

    $lisani_conn->commit();

    $release['movement_ids']   = $movementIds;
    $release['booking_status'] = $bookingStatus;

    aos_json(['ok' => true, 'message' => 'Released.', 'release' => $release]);
} catch (AosReleaseError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('release_booking.php: ' . $e->getMessage());
    aos_fail('Failed to release the booking.');
}