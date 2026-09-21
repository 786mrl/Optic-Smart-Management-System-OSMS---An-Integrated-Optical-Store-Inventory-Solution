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
//   items      JSON  [ {"logistic_id": 10, "qty": 30}, ... ]
//   new_prices JSON  { "10": 145000 }   optional; only for products that have
//                    no price for this customer yet (saved to
//                    customer_item_prices with price_date = order_date)
//   dry_run    "1" | "0"
//
// Response contract: { ok, message, ... }
//   ok: true  -> order { customer, order_date, driver_name, police_number,
//                        invoice{id,number,is_new,total_before,total_after},
//                        items[], grand_total, dry_run, movement_ids? }
//   ok: false -> message, and for a missing price also
//                code: "missing_prices", missing: [ {logistic_id, product_name, unit_label} ]
//
// Effects when saved (all or nothing):
//   - logistic_movements: 1 row per product (movement_type 'out') with the price snapshot
//   - logistics.remaining_primary_qty (-), logistics.total_taken_qty (+)
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
function invoice_initials_index(mysqli $conn, int $customerId, string $initials): int
{
    $re = '#/inv/laj-([^/]+)-(\d+)/#u';

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
    $like = '%/inv/laj-' . $initials . '-%';
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
$qtyByLogistic = [];
foreach ($rawItems as $it) {
    $lid = isset($it['logistic_id']) ? (int) $it['logistic_id'] : 0;
    $qty = isset($it['qty']) ? clean_number((string) $it['qty']) : null;
    if ($lid <= 0 || $qty === null || $qty <= 0 || $qty > 99999.99) {
        aos_fail('Every product line needs a product and a quantity above zero.');
    }
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
        'SELECT l.id, l.remaining_primary_qty, l.primary_unit_label, a.activity_name
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

    // ---- Stock: an order may never take more than what is left ----
    $shortages = [];
    foreach ($ids as $lid) {
        $remaining = (float) ($logistics[$lid]['remaining_primary_qty'] ?? 0);
        if ($qtyByLogistic[$lid] > $remaining + 0.0001) {
            $shortages[] = 'Not enough stock for ' . $logistics[$lid]['activity_name']
                . ': requested ' . fmt_qty($qtyByLogistic[$lid])
                . ', remaining ' . fmt_qty($remaining) . ' ' . $logistics[$lid]['primary_unit_label'] . '.';
        }
    }
    if ($shortages) {
        throw new AosOrderError(implode("\n", $shortages));
    }

    // ---- Prices: latest price_date that is still <= the order date ----
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

        $qty   = $qtyByLogistic[$lid];
        $total = round($qty * $price, 2);
        $grand = round($grand + $total, 2);
        $remainingBefore = (float) ($logistics[$lid]['remaining_primary_qty'] ?? 0);

        $lines[] = [
            'logistic_id'      => $lid,
            'product_name'     => $logistics[$lid]['activity_name'],
            'unit_label'       => $logistics[$lid]['primary_unit_label'],
            'qty'              => $qty,
            'price'            => $price,
            'price_date'       => $priceDate,
            'price_source'     => $source,
            'total_price'      => $total,
            'remaining_before' => $remainingBefore,
            'remaining_after'  => round($remainingBefore - $qty, 2),
        ];
    }
    $priceStmt->close();

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
           (logistic_id, customer_id, movement_type, movement_date, customer_name,
            driver_name, police_number, qty_primary_package, price, total_price,
            invoice_id, created_by)
         VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $logUpd = $lisani_conn->prepare(
        'UPDATE logistics
         SET remaining_primary_qty = remaining_primary_qty - ?,
             total_taken_qty = total_taken_qty + ?
         WHERE id = ?'
    );

    $movementIds = [];
    foreach ($lines as $ln) {
        $lid = $ln['logistic_id'];

        if ($ln['price_source'] === 'new') {
            $p = money($ln['price']);
            $priceIns->bind_param('iisss', $customerId, $lid, $p, $orderDate, $ln['unit_label']);
            $priceIns->execute();
        }

        $q = money($ln['qty']);
        $p = money($ln['price']);
        $t = money($ln['total_price']);
        $movIns->bind_param(
            'iisssssssii',
            $lid, $customerId, $orderDate, $customer['customer_name'],
            $driverDb, $policeDb, $q, $p, $t, $invoiceId, $userId
        );
        $movIns->execute();
        $movementIds[] = (int) $lisani_conn->insert_id;

        $logUpd->bind_param('ssi', $q, $q, $lid);
        $logUpd->execute();
    }
    $priceIns->close();
    $movIns->close();
    $logUpd->close();

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