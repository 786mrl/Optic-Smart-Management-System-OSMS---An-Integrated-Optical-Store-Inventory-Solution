<?php
// lisani_aos/ajax/create_booking.php
// Records ONE booking / DP: customer pays up front (fully or partially) for
// N product lines, stock is RESERVED immediately (remaining_primary_qty /
// defective_qty goes down, so the same stock can't be oversold elsewhere),
// but NO logistic_movements row is written and total_taken_qty /
// defective_taken_qty do NOT change — the goods have not left the warehouse
// yet. That happens later in a separate "release" step (not built yet).
//
// Same dry_run / confirm pattern as create_order.php.
//
// POST:
//   customer_id, booking_date (Y-m-d)
//   items      JSON  [ {"logistic_id": 10, "qty": 30, "stock_source": "normal"}, ... ]
//                    same rules as create_order.php: one stock_source per
//                    product, max 50 lines.
//   new_prices JSON  { "10": 145000 }   optional, same semantics as create_order.php
//   dry_run    "1" | "0"
//
// DP / payment is NOT handled here on purpose: the invoice this endpoint
// creates/reuses is a normal row in `invoices`, so it already shows up in
// the Customers tab's regular Invoices list with its own "+ Add Payment"
// button — the existing OCR slip-upload flow (create_invoice_payment.php)
// is reused as-is, which already supports paying in several installments
// (one invoice_payments row per upload).
//
// Response contract: { ok, message, ... }
//   ok: true  -> booking { customer, booking_date,
//                          invoice{id,number,is_new,total_before,total_after,
//                                   paid_before,paid_after},
//                          items[], grand_total, dry_run, booking_id? }
//   ok: false -> message, and for a missing price also
//                code: "missing_prices", missing: [ {logistic_id, product_name, unit_label} ]
//
// Effects when saved (all or nothing):
//   - bookings: 1 row (status 'active')
//   - booking_items: 1 row per product (qty_released = 0)
//   - stock_source='normal'   : logistics.remaining_primary_qty (-)
//   - stock_source='defective': logistics.defective_qty (-)
//     (total_taken_qty / defective_taken_qty are NOT touched — see note above)
//   - invoices: the customer's open invoice is reused, or a new one is created,
//     same numbering scheme as create_order.php; total_amount (+ grand_total)
//   - customers.total_inflow is deliberately NOT touched here (it only moves
//     when the goods actually leave, i.e. at release time)

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AosBookingError extends Exception
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
        error_log('create_booking.php stray output: ' . $noise);
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
    aos_fail('Session expired. Please log in again.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aos_fail('Invalid request.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

// ---------- Helpers (duplicated from create_order.php on purpose — see
// PROJECT_NOTES.md §3 on per-file helper duplication) ----------
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

function roman_month(int $m): string
{
    $map = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
            7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];
    return $map[$m] ?? (string) $m;
}

function customer_initials(string $name): string
{
    $out = '';
    foreach (preg_split('/\s+/u', trim($name)) as $word) {
        $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        if ($word !== '') {
            $out .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
        }
    }
    return $out === '' ? 'X' : $out;
}

// Same [n] scheme as create_order.php — scans /inv/, /ret/, /adj/ invoice
// numbers so a customer's [n] stays identical across every kind, booking
// invoices included (they are plain /inv/ invoices, nothing new to scan for).
function invoice_initials_index(mysqli $conn, int $customerId, string $initials): int
{
    $re = '#/(?:inv|ret|adj)/laj-([^/]+)-(\d+)/#u';

    $st = $conn->prepare('SELECT invoice_number FROM invoices WHERE customer_id = ? ORDER BY id DESC');
    $st->bind_param('i', $customerId);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        if (preg_match($re, $row['invoice_number'], $m) && $m[1] === $initials) {
            $st->close();
            return (int) $m[2];
        }
    }
    $st->close();

    $like = '%/laj-' . $initials . '-%';
    $st = $conn->prepare('SELECT invoice_number FROM invoices WHERE invoice_number LIKE ?');
    $st->bind_param('s', $like);
    $st->execute();
    $res = $st->get_result();
    $max = 0;
    while ($row = $res->fetch_assoc()) {
        if (preg_match($re, $row['invoice_number'], $m) && $m[1] === $initials) {
            $max = max($max, (int) $m[2]);
        }
    }
    $st->close();
    return $max + 1;
}

// ---------- Read + validate input ----------
$customerId  = (int) post_str('customer_id');
$bookingDate = post_str('booking_date');
$dryRun      = post_str('dry_run') !== '0';

if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $bookingDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $bookingDate) {
    aos_fail('Booking date is not valid.');
}
$periodMonth = (int) $dateObj->format('n');
$periodYear  = (int) $dateObj->format('Y');

$rawItems = json_decode(post_str('items'), true);
if (!is_array($rawItems) || count($rawItems) === 0) {
    aos_fail('The booking has no products.');
}
if (count($rawItems) > 50) {
    aos_fail('A booking can have at most 50 product lines.');
}

$qtyByLogistic         = [];
$stockSourceByLogistic = [];
foreach ($rawItems as $it) {
    $lid = isset($it['logistic_id']) ? (int) $it['logistic_id'] : 0;
    $qty = isset($it['qty']) ? clean_number((string) $it['qty']) : null;
    if ($lid <= 0 || $qty === null || $qty <= 0 || $qty > 99999.99) {
        aos_fail('Every product line needs a product and a quantity above zero.');
    }
    $ss = (isset($it['stock_source']) && $it['stock_source'] === 'defective') ? 'defective' : 'normal';
    if (isset($stockSourceByLogistic[$lid]) && $stockSourceByLogistic[$lid] !== $ss) {
        aos_fail('The same product cannot mix normal and defective stock in one booking — split it into two bookings.');
    }
    $stockSourceByLogistic[$lid] = $ss;
    $qtyByLogistic[$lid] = round(($qtyByLogistic[$lid] ?? 0) + $qty, 2);
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

$userId = (int) $_SESSION['user_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    // ---- Customer (locked, same reasoning as create_order.php) ----
    $st = $lisani_conn->prepare('SELECT id, customer_name FROM customers WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $customerId);
    $st->execute();
    $customer = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$customer) {
        throw new AosBookingError('Customer was not found.');
    }

    // ---- Products (locked, always in id order) ----
    $ids = array_keys($qtyByLogistic);
    sort($ids);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $lisani_conn->prepare(
        'SELECT l.id, l.remaining_primary_qty, l.defective_qty,
                l.primary_unit_label, l.product_name AS activity_name
         FROM logistics l
         JOIN activities a ON a.id = l.activity_id
         WHERE l.id IN (' . $marks . ')
         ORDER BY l.id
         FOR UPDATE'
    );
    $st->bind_param(str_repeat('i', count($ids)), ...$ids);
    $st->execute();
    $logistics = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $logistics[(int) $row['id']] = $row;
    }
    $st->close();

    foreach ($ids as $lid) {
        if (!isset($logistics[$lid])) {
            throw new AosBookingError('A product in this booking was not found.');
        }
    }

    // ---- Stock: reserved immediately, so it must fit what is left NOW ----
    $shortages = [];
    foreach ($ids as $lid) {
        $ss = $stockSourceByLogistic[$lid];
        if ($ss === 'defective') {
            $remaining = (float) ($logistics[$lid]['defective_qty'] ?? 0);
            $bucketLabel = 'defective stock';
        } else {
            $remaining = (float) ($logistics[$lid]['remaining_primary_qty'] ?? 0);
            $bucketLabel = 'stock';
        }
        if ($qtyByLogistic[$lid] > $remaining + 0.0001) {
            $shortages[] = 'Not enough ' . $bucketLabel . ' for ' . $logistics[$lid]['activity_name']
                . ': requested ' . fmt_qty($qtyByLogistic[$lid])
                . ', remaining ' . fmt_qty($remaining) . ' ' . $logistics[$lid]['primary_unit_label'] . '.';
        }
    }
    if ($shortages) {
        throw new AosBookingError(implode("\n", $shortages));
    }

    // ---- Prices (same resolution as create_order.php; no defective-suggestion
    //      lookup here since that reads from logistic_movements of actual
    //      sales — a booking line still needs a fresh manual price for defective) ----
    $priceStmt = $lisani_conn->prepare(
        'SELECT price, price_date
         FROM customer_item_prices
         WHERE customer_id = ? AND logistic_id = ? AND price_date <= ?
         ORDER BY price_date DESC, created_at DESC, id DESC
         LIMIT 1'
    );

    $lines   = [];
    $missing = [];
    $grand   = 0.0;
    foreach ($ids as $lid) {
        $ss = $stockSourceByLogistic[$lid];

        if ($ss === 'normal') {
            $priceStmt->bind_param('iis', $customerId, $lid, $bookingDate);
            $priceStmt->execute();
            $found = $priceStmt->get_result()->fetch_assoc();

            if ($found) {
                $price = (float) $found['price'];
                $priceDate = $found['price_date'];
                $source = 'history';
            } elseif (isset($newPrices[$lid])) {
                $price = $newPrices[$lid];
                $priceDate = $bookingDate;
                $source = 'new';
            } else {
                $missing[] = [
                    'logistic_id'  => $lid,
                    'product_name' => $logistics[$lid]['activity_name'],
                    'unit_label'   => $logistics[$lid]['primary_unit_label'],
                ];
                continue;
            }
        } else {
            if (isset($newPrices[$lid])) {
                $price = $newPrices[$lid];
                $priceDate = $bookingDate;
                $source = 'defective_manual';
            } else {
                $missing[] = [
                    'logistic_id'  => $lid,
                    'product_name' => $logistics[$lid]['activity_name'],
                    'unit_label'   => $logistics[$lid]['primary_unit_label'],
                    'stock_source' => 'defective',
                ];
                continue;
            }
        }

        $qty   = $qtyByLogistic[$lid];
        $total = round($qty * $price, 2);
        $grand = round($grand + $total, 2);
        $remainingBefore = $ss === 'defective'
            ? (float) ($logistics[$lid]['defective_qty'] ?? 0)
            : (float) ($logistics[$lid]['remaining_primary_qty'] ?? 0);

        $lines[] = [
            'logistic_id'      => $lid,
            'product_name'     => $logistics[$lid]['activity_name'],
            'unit_label'       => $logistics[$lid]['primary_unit_label'],
            'qty'              => $qty,
            'price'            => $price,
            'price_date'       => $priceDate,
            'price_source'     => $source,
            'total_price'      => $total,
            'stock_source'     => $ss,
            'remaining_before' => $remainingBefore,
            'remaining_after'  => round($remainingBefore - $qty, 2),
        ];
    }
    $priceStmt->close();

    if ($missing) {
        throw new AosBookingError(
            'Some products have no price for this customer yet.',
            ['code' => 'missing_prices', 'missing' => $missing]
        );
    }

    // ---- Invoice: a booking ALWAYS gets its own new invoice — unlike
    //      New Order/Returns, it never reuses the customer's open invoice
    //      (so a booking's bill never gets silently merged into whatever
    //      other open invoice happens to exist for that customer). Same
    //      numbering scheme as create_order.php (§5.11), just always the
    //      "new invoice" branch.
    $invoiceId     = null;
    $invoiceNumber = '';
    $invoiceSeq    = 0;
    $invoiceIsNew  = true;
    $totalBefore   = 0.0;
    $paidBefore    = 0.0;

    {
        $st = $lisani_conn->prepare(
            'SELECT COALESCE(MAX(sequence_number), 0) AS max_seq
             FROM invoices
             WHERE customer_id = ?'
        );
        $st->bind_param('i', $customerId);
        $st->execute();
        $invoiceSeq = (int) $st->get_result()->fetch_assoc()['max_seq'] + 1;
        $st->close();

        $initials      = customer_initials($customer['customer_name']);
        $initialsIndex = invoice_initials_index($lisani_conn, $customerId, $initials);
        $invoiceNumber = sprintf('%03d', $invoiceSeq) . '/inv/laj-' . $initials . '-' . $initialsIndex
            . '/' . roman_month($periodMonth) . '/' . $periodYear;

        $chk = $lisani_conn->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $chk->bind_param('s', $invoiceNumber);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        if ($taken) {
            throw new AosBookingError('Could not create a new invoice number.');
        }
        if ($invoiceSeq > 65535) {
            throw new AosBookingError('Could not create a new invoice number.');
        }
    }

    $booking = [
        'dry_run'      => $dryRun,
        'customer'     => ['id' => $customerId, 'name' => $customer['customer_name']],
        'booking_date' => $bookingDate,
        'invoice'      => [
            'id'           => $invoiceId,
            'number'       => $invoiceNumber,
            'is_new'       => $invoiceIsNew,
            'total_before' => $totalBefore,
            'total_after'  => round($totalBefore + $grand, 2),
            'paid_before'  => $paidBefore,
            'paid_after'   => $paidBefore, // unchanged here — DP goes through + Add Payment
        ],
        'items'        => $lines,
        'grand_total'  => $grand,
    ];

    // ---- Preview only ----
    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Booking is ready to be saved.', 'booking' => $booking]);
    }

    // ================= WRITES =================
    if ($invoiceIsNew) {
        $st = $lisani_conn->prepare(
            "INSERT INTO invoices
               (customer_id, invoice_number, sequence_number, period_month, period_year,
                status, total_amount, paid_amount)
             VALUES (?, ?, ?, ?, ?, 'open', 0, 0)"
        );
        $st->bind_param('isiii', $customerId, $invoiceNumber, $invoiceSeq, $periodMonth, $periodYear);
        $st->execute();
        $invoiceId = (int) $lisani_conn->insert_id;
        $st->close();
    }

    $priceIns = $lisani_conn->prepare(
        'INSERT INTO customer_item_prices (customer_id, logistic_id, price, price_date, unit_label)
         VALUES (?, ?, ?, ?, ?)'
    );
    $itemIns = $lisani_conn->prepare(
        'INSERT INTO booking_items (booking_id, logistic_id, stock_source, qty_booked, price)
         VALUES (?, ?, ?, ?, ?)'
    );
    $logUpdNormal = $lisani_conn->prepare(
        'UPDATE logistics SET remaining_primary_qty = remaining_primary_qty - ? WHERE id = ?'
    );
    $logUpdDefective = $lisani_conn->prepare(
        'UPDATE logistics SET defective_qty = defective_qty - ? WHERE id = ?'
    );

    $st = $lisani_conn->prepare(
        'INSERT INTO bookings (customer_id, invoice_id, booking_date, status, created_by)
         VALUES (?, ?, ?, \'active\', ?)'
    );
    $st->bind_param('iisi', $customerId, $invoiceId, $bookingDate, $userId);
    $st->execute();
    $bookingId = (int) $lisani_conn->insert_id;
    $st->close();

    foreach ($lines as $ln) {
        $lid = $ln['logistic_id'];

        if ($ln['price_source'] === 'new') {
            $p = money($ln['price']);
            $priceIns->bind_param('iisss', $customerId, $lid, $p, $bookingDate, $ln['unit_label']);
            $priceIns->execute();
        }

        $q  = money($ln['qty']);
        $p  = money($ln['price']);
        $ss = $ln['stock_source'];
        $itemIns->bind_param('iisss', $bookingId, $lid, $ss, $q, $p);
        $itemIns->execute();

        if ($ss === 'defective') {
            $logUpdDefective->bind_param('si', $q, $lid);
            $logUpdDefective->execute();
        } else {
            $logUpdNormal->bind_param('si', $q, $lid);
            $logUpdNormal->execute();
        }
    }
    $priceIns->close();
    $itemIns->close();
    $logUpdNormal->close();
    $logUpdDefective->close();

    $g = money($grand);
    $st = $lisani_conn->prepare('UPDATE invoices SET total_amount = total_amount + ? WHERE id = ?');
    $st->bind_param('si', $g, $invoiceId);
    $st->execute();
    $st->close();

    $lisani_conn->commit();

    $booking['invoice']['id'] = $invoiceId;
    $booking['booking_id']    = $bookingId;

    aos_json(['ok' => true, 'message' => 'Booking saved.', 'booking' => $booking]);
} catch (AosBookingError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('create_booking.php: ' . $e->getMessage());
    aos_fail('Failed to save the booking.');
}