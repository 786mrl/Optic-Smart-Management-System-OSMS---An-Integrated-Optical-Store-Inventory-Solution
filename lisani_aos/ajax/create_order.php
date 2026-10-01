<?php
// lisani_aos/ajax/create_order.php
// Records ONE Sales Transaction order (one customer, one date, N products)
// after the WhatsApp message was parsed and the user reviewed it.
//
// Two modes, SAME code path so the preview can never differ from what is saved:
//   dry_run = 1 (default) : computes everything (prices, stock, invoice), writes
//                           nothing, returns the order for the confirm window.
//   dry_run = 0           : writes it all in ONE DB transaction.
//
// POST:
//   customer_id, order_date (Y-m-d), driver_name, police_number,
//   items      JSON  [ {"logistic_id": 10, "qty": 30, "stock_source": "normal"}, ... ]
//                    stock_source is optional, "normal" (default) or
//                    "defective" — see "Kendala #1 & #2" in PROJECT_NOTES.md.
//                    A product can only have ONE stock_source per order (if
//                    the same product appears on two lines with different
//                    stock_source, the request is rejected — split it into
//                    two separate orders instead).
//   new_prices JSON  { "10": 145000 }   optional; only for products that have
//                    no price for this customer yet (saved to
//                    customer_item_prices with price_date = order_date)
//   dry_run    "1" | "0"
//
// Revised 22 Sep 2026 (defective stock, "Kendala #1 & #2"): a line can now
// draw from logistics.defective_qty instead of remaining_primary_qty.
// total_taken_qty (the combined "currently with the customer" balance) rises
// either way; defective_taken_qty additionally rises only for defective
// lines, as a sub-tracking of how much of that balance is defective stock.
//
// Revised 27 Sep 2026 ("Redesain besar: hapus tab Defective Stock..."): a
// defective line's price is now ALWAYS a fresh manual entry (new_prices),
// never looked up from customer_item_prices (that table isn't
// stock_source-aware, so it can't tell a normal price from a defective one
// for the same customer+product) and never saved back into it either. A
// "suggested_price" (the last price this product's defective stock actually
// sold for, to any customer) is returned as a hint only, taken from
// logistic_movements — logistics.defective_reference_price is no longer
// used anywhere in this file.
//
// Response contract: { ok, message, ... }
//   ok: true  -> order { customer, order_date, driver_name, police_number,
//                        invoice{id,number,is_new,total_before,total_after},
//                        items[], grand_total, dry_run, movement_ids? }
//   ok: false -> message, and for a missing price also
//                code: "missing_prices", missing: [ {logistic_id, product_name, unit_label} ]
//
// Effects when saved (all or nothing):
//   - logistic_movements: 1 row per product (movement_type 'out') with the price
//     snapshot and stock_source ('normal' or 'defective')
//   - stock_source='normal'   : logistics.remaining_primary_qty (-)
//   - stock_source='defective': logistics.defective_qty (-), logistics.defective_taken_qty (+)
//   - logistics.total_taken_qty (+) in BOTH cases — combined balance, unchanged behaviour
//   - customers.total_inflow (+ order value)
//   - invoices: the customer's open invoice is reused, or a new one is created
//     ([seq 3 digits]/inv/laj-[INITIALS]-[n]/[MONTH ROMAN]/[YEAR]); total_amount (+)
//     [n] = position of the customer among customers that share the same initials
//     (starts at 1). It is fixed at the customer's first invoice and reused for all
//     of that customer's invoices, so two customers with the same initials can never
//     produce the same invoice number, whatever order their invoices are settled in.

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// A business-rule failure: rolled back and reported to the browser as-is.
class AosOrderError extends Exception
{
    public $payload;

    public function __construct(string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->payload = array_merge(['ok' => false, 'message' => $message], $extra);
    }
}

// Every exit goes through here so the browser always receives clean JSON.
function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('create_order.php stray output: ' . $noise);
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

// ---------- Helpers ----------
function post_str(string $key): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
}

// Accepts "1,234.50" or "1234.5"; returns a float or null.
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

// 30.0 -> "30", 30.5 -> "30.5" (for messages)
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

// "CAHAYA MAJU JAYA" -> "CMJ" (first letter of every word)
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

// [n] in "001/inv/laj-CMJ-[n]/IX/2026": which customer, among those sharing the same
// initials, this one is. Stable: once a customer has an invoice with these initials,
// its [n] is reused; a customer's first invoice takes the highest [n] in use + 1
// (so it starts at 1 and is never reused, even if another customer is deleted).
// Scans ALL THREE invoice kinds that share the `invoices` table — orders
// (/inv/), returns (/ret/), and price adjustments (/adj/, added 22 Sep 2026)
// — so a customer's [n] stays the same no matter which kind of invoice they
// happen to have first, and two customers with the same initials can never
// collide regardless of the mix of invoice kinds each has.
function invoice_initials_index(mysqli $conn, int $customerId, string $initials): int
{
    $re = '#/(?:inv|ret|adj)/laj-([^/]+)-(\d+)/#u';

    // 1) This customer already has an invoice with these initials -> same [n].
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

    // 2) First invoice of this customer -> next free [n] for these initials.
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
$customerId = (int) post_str('customer_id');
$orderDate  = post_str('order_date');
$driver     = mb_strtoupper(post_str('driver_name'));
$police     = mb_strtoupper(post_str('police_number'));
$dryRun     = post_str('dry_run') !== '0'; // anything except an explicit "0" is a preview

if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $orderDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $orderDate) {
    aos_fail('Order date is not valid.');
}
$periodMonth = (int) $dateObj->format('n');
$periodYear  = (int) $dateObj->format('Y');

if (mb_strlen($driver) > 150) {
    aos_fail('Driver name is too long (max 150 characters).');
}
if (mb_strlen($police) > 30) {
    aos_fail('Police number is too long (max 30 characters).');
}

$rawItems = json_decode(post_str('items'), true);
if (!is_array($rawItems) || count($rawItems) === 0) {
    aos_fail('The order has no products.');
}
if (count($rawItems) > 50) {
    aos_fail('An order can have at most 50 product lines.');
}

// Same product on two lines -> one movement with the summed quantity.
// stock_source must be the SAME across every line of the same product
// (a single logistic_movements row can only carry one bucket).
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
        aos_fail('The same product cannot mix normal and defective stock in one order — split it into two orders.');
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

// Make mysqli throw on failure (default only from PHP 8.1) so the catch below always fires.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    // ---- Customer (locked: serializes orders of the same customer, which keeps
    //      invoice numbering and total_inflow consistent) ----
    $st = $lisani_conn->prepare('SELECT id, customer_name FROM customers WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $customerId);
    $st->execute();
    $customer = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$customer) {
        throw new AosOrderError('Customer was not found.');
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
            throw new AosOrderError('A product in this order was not found.');
        }
    }

    // ---- Stock: an order may never take more than what is left, checked
    //      against the bucket (normal / defective) each line asked for ----
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
        throw new AosOrderError(implode("\n", $shortages));
    }

    // ---- Prices ----
    // 'normal' lines: latest customer_item_prices entry with price_date <=
    // the order date (unchanged behaviour).
    // 'defective' lines: customer_item_prices is deliberately NEVER consulted
    // — that table has no stock_source column, so it mixes normal and
    // defective prices for the same customer+product. Trusting it for a
    // defective line risked silently reusing that customer's NORMAL price
    // (the bug reported 27 Sep 2026 — see PROJECT_NOTES.md, "Redesain besar:
    // hapus tab Defective Stock..."), and saving a defective price INTO it
    // would just as silently corrupt future normal-price lookups the other
    // way. So a defective line always needs a fresh manual price via
    // new_prices (source 'defective_manual', never written back to
    // customer_item_prices — see the WRITES section below). A "suggested"
    // price is offered as a hint only — the LAST price this activity code's
    // defective stock was actually sold at, to ANY customer — computed from
    // logistic_movements, never auto-applied.
    $priceStmt = $lisani_conn->prepare(
        'SELECT price, price_date
         FROM customer_item_prices
         WHERE customer_id = ? AND logistic_id = ? AND price_date <= ?
         ORDER BY price_date DESC, created_at DESC, id DESC
         LIMIT 1'
    );
    $suggestStmt = $lisani_conn->prepare(
        "SELECT price, movement_date, customer_name
         FROM logistic_movements
         WHERE logistic_id = ? AND movement_type = 'out' AND stock_source = 'defective'
         ORDER BY movement_date DESC, created_at DESC, id DESC
         LIMIT 1"
    );

    $lines   = [];
    $missing = [];
    $grand   = 0.0;
    foreach ($ids as $lid) {
        $ss = $stockSourceByLogistic[$lid];

        if ($ss === 'normal') {
            $priceStmt->bind_param('iis', $customerId, $lid, $orderDate);
            $priceStmt->execute();
            $found = $priceStmt->get_result()->fetch_assoc();

            if ($found) {
                $price = (float) $found['price'];
                $priceDate = $found['price_date'];
                $source = 'history';
            } elseif (isset($newPrices[$lid])) {
                $price = $newPrices[$lid];
                $priceDate = $orderDate;
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
            // Defective: figure out the suggestion regardless (used both in
            // the "missing" hint and kept on the saved line for reference).
            $suggestStmt->bind_param('i', $lid);
            $suggestStmt->execute();
            $sg = $suggestStmt->get_result()->fetch_assoc();
            $suggestedPrice        = $sg ? (float) $sg['price'] : null;
            $suggestedPriceDate    = $sg ? $sg['movement_date'] : null;
            $suggestedCustomerName = $sg ? $sg['customer_name'] : null;

            if (isset($newPrices[$lid])) {
                $price = $newPrices[$lid];
                $priceDate = $orderDate;
                $source = 'defective_manual';
            } else {
                $missing[] = [
                    'logistic_id'             => $lid,
                    'product_name'            => $logistics[$lid]['activity_name'],
                    'unit_label'              => $logistics[$lid]['primary_unit_label'],
                    'stock_source'            => 'defective',
                    'suggested_price'         => $suggestedPrice,
                    'suggested_price_date'    => $suggestedPriceDate,
                    'suggested_customer_name' => $suggestedCustomerName,
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
            // Available in the OTHER bucket too, so the client can decide
            // whether to offer a normal<->defective toggle for this line at
            // all (only worth asking when defective_qty > 0).
            'defective_qty'    => (float) ($logistics[$lid]['defective_qty'] ?? 0),
            'remaining_before' => $remainingBefore,
            'remaining_after'  => round($remainingBefore - $qty, 2),
        ];
    }
    $priceStmt->close();
    $suggestStmt->close();

    if ($missing) {
        throw new AosOrderError(
            'Some products have no price for this customer yet.',
            ['code' => 'missing_prices', 'missing' => $missing]
        );
    }

    // ---- Invoice: reuse the customer's open one, otherwise plan a new one ----
    $st = $lisani_conn->prepare(
        "SELECT id, invoice_number, total_amount
         FROM invoices
         WHERE customer_id = ? AND status = 'open'
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $st->bind_param('i', $customerId);
    $st->execute();
    $invoice = $st->get_result()->fetch_assoc();
    $st->close();

    $invoiceId     = null;
    $invoiceNumber = '';
    $invoiceSeq    = 0;
    $invoiceIsNew  = false;
    $totalBefore   = 0.0;

    if ($invoice) {
        $invoiceId     = (int) $invoice['id'];
        $invoiceNumber = $invoice['invoice_number'];
        $totalBefore   = (float) $invoice['total_amount'];
    } else {
        $invoiceIsNew = true;

        // Sequence restarts per customer per month, starting at 001.
        $st = $lisani_conn->prepare(
            'SELECT COALESCE(MAX(sequence_number), 0) AS max_seq
             FROM invoices
             WHERE customer_id = ? AND period_month = ? AND period_year = ?'
        );
        $st->bind_param('iii', $customerId, $periodMonth, $periodYear);
        $st->execute();
        $invoiceSeq = (int) $st->get_result()->fetch_assoc()['max_seq'] + 1;
        $st->close();

        $initials      = customer_initials($customer['customer_name']);
        $initialsIndex = invoice_initials_index($lisani_conn, $customerId, $initials);
        $invoiceNumber = sprintf('%03d', $invoiceSeq) . '/inv/laj-' . $initials . '-' . $initialsIndex
            . '/' . roman_month($periodMonth) . '/' . $periodYear;

        // Safety net only: [n] already keeps different customers apart, so this should
        // never fire. If it does, stop instead of guessing another number.
        $chk = $lisani_conn->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $chk->bind_param('s', $invoiceNumber);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        if ($taken) {
            throw new AosOrderError('Could not create a new invoice number.');
        }
        if ($invoiceSeq > 65535) {
            throw new AosOrderError('Could not create a new invoice number.');
        }
    }

    $order = [
        'dry_run'       => $dryRun,
        'customer'      => ['id' => $customerId, 'name' => $customer['customer_name']],
        'order_date'    => $orderDate,
        'driver_name'   => $driver !== '' ? $driver : null,
        'police_number' => $police !== '' ? $police : null,
        'invoice'       => [
            'id'           => $invoiceId,
            'number'       => $invoiceNumber,
            'is_new'       => $invoiceIsNew,
            'total_before' => $totalBefore,
            'total_after'  => round($totalBefore + $grand, 2),
        ],
        'items'         => $lines,
        'grand_total'   => $grand,
    ];

    // ---- Preview only: nothing was written, release the locks ----
    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Order is ready to be saved.', 'order' => $order]);
    }

    // ================= WRITES =================
    $driverDb = $driver !== '' ? $driver : null;
    $policeDb = $police !== '' ? $police : null;

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
    $movIns = $lisani_conn->prepare(
        "INSERT INTO logistic_movements
           (logistic_id, customer_id, movement_type, stock_source, movement_date, customer_name,
            driver_name, police_number, qty_primary_package, price, total_price,
            invoice_id, created_by)
         VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    // Two variants, one per bucket — a single row can only touch one of
    // remaining_primary_qty / defective_qty, so this stays a plain UPDATE
    // instead of a CASE (simpler to read, and each is prepared once).
    $logUpdNormal = $lisani_conn->prepare(
        'UPDATE logistics
         SET remaining_primary_qty = remaining_primary_qty - ?,
             total_taken_qty = total_taken_qty + ?
         WHERE id = ?'
    );
    $logUpdDefective = $lisani_conn->prepare(
        'UPDATE logistics
         SET defective_qty = defective_qty - ?,
             defective_taken_qty = defective_taken_qty + ?,
             total_taken_qty = total_taken_qty + ?
         WHERE id = ?'
    );

    $movementIds = [];
    foreach ($lines as $ln) {
        $lid = $ln['logistic_id'];

        // Only a NORMAL manual price is saved to customer_item_prices.
        // 'defective_manual' is deliberately never written here — see the
        // big comment above the price-resolution loop for why mixing
        // defective prices into that table would corrupt future normal
        // price lookups for the same customer+product.
        if ($ln['price_source'] === 'new') {
            $p = money($ln['price']);
            $priceIns->bind_param('iisss', $customerId, $lid, $p, $orderDate, $ln['unit_label']);
            $priceIns->execute();
        }

        $q  = money($ln['qty']);
        $p  = money($ln['price']);
        $t  = money($ln['total_price']);
        $ss = $ln['stock_source'];
        $movIns->bind_param(
            'iissssssssii',
            $lid, $customerId, $ss, $orderDate, $customer['customer_name'],
            $driverDb, $policeDb, $q, $p, $t, $invoiceId, $userId
        );
        $movIns->execute();
        $movementIds[] = (int) $lisani_conn->insert_id;

        if ($ss === 'defective') {
            $logUpdDefective->bind_param('sssi', $q, $q, $q, $lid);
            $logUpdDefective->execute();
        } else {
            $logUpdNormal->bind_param('ssi', $q, $q, $lid);
            $logUpdNormal->execute();
        }
    }
    $priceIns->close();
    $movIns->close();
    $logUpdNormal->close();
    $logUpdDefective->close();

    // batch_id = explicit "one order" identifier shared by every row written
    // by this call (lowest movement id of the order). update_order.php reuses
    // it for lines added later, so the order stays one group when revised.
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

    $st = $lisani_conn->prepare('UPDATE invoices SET total_amount = total_amount + ? WHERE id = ?');
    $st->bind_param('si', $g, $invoiceId);
    $st->execute();
    $st->close();

    $lisani_conn->commit();

    $order['invoice']['id'] = $invoiceId;
    $order['movement_ids']  = $movementIds;

    aos_json(['ok' => true, 'message' => 'Order saved.', 'order' => $order]);
} catch (AosOrderError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('create_order.php: ' . $e->getMessage());
    aos_fail('Failed to save the order.');
}