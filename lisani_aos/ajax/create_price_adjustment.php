<?php
// lisani_aos/ajax/create_price_adjustment.php
// Records ONE price adjustment (one customer, one date, N products) — for
// goods that stay with the customer (e.g. defective, but they took it
// anyway), compensated with a lower price. This is deliberately NOT a
// return: goods do not come back, so stock is never touched at all
// (logistics.remaining_primary_qty, defective_qty, total_taken_qty,
// defective_taken_qty — none of them change here).
//
// New 22 Sep 2026 — see "Kendala #1 & #2" in PROJECT_NOTES.md. Structurally
// mirrors create_return.php's lot-based allocation pattern (same picker,
// same source_movement_id traceability, same dry_run/save split), on
// purpose, for consistency — but is a SEPARATE endpoint (user decision,
// 22 Sep 2026) rather than a mode flag on create_return.php, so the two
// stay easy to reason about independently.
//
// Two modes, SAME code path so the preview can never differ from what is saved:
//   dry_run = 1 (default) : computes everything, writes nothing, returns the
//                           adjustment for the confirm window.
//   dry_run = 0           : writes it all in ONE DB transaction.
//
// POST:
//   customer_id, adjustment_date (Y-m-d), driver_name, police_number,
//   items JSON  [ {"logistic_id": 10, "allocations": [
//                    {"source_movement_id": 55, "qty": 2,
//                     "old_price": 170000, "new_price": 150000},
//                    ...
//                  ]}, ... ]
//   dry_run    "1" | "0"
//
// Each allocation is validated against remaining_adjustable of its source
// pickup (see list_return_price_options.php) — qty - already returned -
// already adjusted against that specific pickup. A unit already physically
// returned, or already adjusted once, cannot be adjusted again. Unlike
// create_return.php, this is NOT limited by defective_qty/remaining stock —
// it never checks stock at all, only how much of that pickup is still
// eligible to be adjusted.
//
// Effects when saved (all or nothing):
//   - logistic_movements: 1 row per allocation, movement_type='price_adjustment',
//     source_movement_id set, qty = qty adjusted (informational — never
//     changes any stock column), price = new_price, total_price = the
//     DISCOUNT amount i.e. (old_price - new_price) * qty (positive number;
//     sign is applied when touching invoice/customer totals, same
//     convention as create_return.php's grand total).
//   - invoices: reuse the customer's OPEN invoice if one exists (its
//     total_amount is reduced by the discount, may go negative); only when
//     none is open is a new one created, "/adj/" instead of "/inv/" or
//     "/ret/" in its number so it stays obviously a price-adjustment-only
//     invoice, negative total_amount from the start.
//   - customers.total_outflow (+ discount)   — same accumulator returns use,
//     so net receivable (total_inflow - total_outflow) stays correct.
//   - customers.total_price_adjustments (+ discount) — separate accumulator,
//     purely for reporting: how much was given away as price adjustments,
//     as opposed to physical returns.
//   - Stock (remaining_primary_qty, defective_qty, total_taken_qty,
//     defective_taken_qty): UNTOUCHED. The whole point of this endpoint.
//
// Response contract: { ok, message, ... }
//   ok: true  -> adj { customer, adjustment_date, driver_name, police_number,
//                      invoice{id,number,total_before,total_after},
//                      items[]: { logistic_id, product_name, unit_label, qty,
//                        discount_total, allocations[]: { source_movement_id,
//                        source_movement_date, qty, old_price, new_price,
//                        discount_total } },
//                      grand_discount, dry_run, movement_ids? }
//   ok: false -> message, and for a shortage also
//                code: "not_adjustable", items: [ {logistic_id, product_name,
//                source_movement_id, movement_date, available, requested,
//                reason} ]

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// A business-rule failure: rolled back and reported to the browser as-is.
class AosPriceAdjustmentError extends Exception
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
        error_log('create_price_adjustment.php stray output: ' . $noise);
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

// Same [n] scheme as create_order.php / create_return.php's
// invoice_initials_index() — scans ALL THREE invoice kinds ("/inv/",
// "/ret/", "/adj/") since they share the same `invoices` table and must
// never collide for a customer sharing initials with someone else.
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
$customerId      = (int) post_str('customer_id');
$adjustmentDate  = post_str('adjustment_date');
$driver          = mb_strtoupper(post_str('driver_name'));
$police          = mb_strtoupper(post_str('police_number'));
$dryRun          = post_str('dry_run') !== '0'; // anything except an explicit "0" is a preview

if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $adjustmentDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $adjustmentDate) {
    aos_fail('Adjustment date is not valid.');
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
    aos_fail('The price adjustment has no products.');
}
if (count($rawItems) > 50) {
    aos_fail('A price adjustment can have at most 50 product lines.');
}

// Flat list, same reasoning as create_return.php: two allocations for the
// same product routinely carry different old/new prices.
$allocations   = [];
$qtyByLogistic = [];
foreach ($rawItems as $it) {
    $lid    = isset($it['logistic_id']) ? (int) $it['logistic_id'] : 0;
    $allocs = $it['allocations'] ?? null;
    if ($lid <= 0 || !is_array($allocs) || count($allocs) === 0) {
        aos_fail('Every product line needs a product and at least one pickup allocation.');
    }
    foreach ($allocs as $al) {
        $smid     = isset($al['source_movement_id']) ? (int) $al['source_movement_id'] : 0;
        $qty      = isset($al['qty']) ? clean_number((string) $al['qty']) : null;
        $oldPrice = isset($al['old_price']) ? clean_number((string) $al['old_price']) : null;
        $newPrice = isset($al['new_price']) ? clean_number((string) $al['new_price']) : null;
        if ($smid <= 0 || $qty === null || $qty <= 0 || $qty > 99999.99) {
            aos_fail('Every allocated line needs a source pickup and a quantity above zero.');
        }
        if ($oldPrice === null || $oldPrice <= 0 || $oldPrice > 9999999999999.99) {
            aos_fail('Every allocated line needs the original price.');
        }
        if ($newPrice === null || $newPrice < 0 || $newPrice > 9999999999999.99) {
            aos_fail('Every allocated line needs a new price of zero or above.');
        }
        if ($newPrice >= $oldPrice) {
            aos_fail('The new price must be lower than the original price — that is the point of an adjustment.');
        }
        $qty      = round($qty, 2);
        $oldPrice = round($oldPrice, 2);
        $newPrice = round($newPrice, 2);
        $allocations[] = [
            'logistic_id'        => $lid,
            'source_movement_id' => $smid,
            'qty'                => $qty,
            'old_price'          => $oldPrice,
            'new_price'          => $newPrice,
        ];
        $qtyByLogistic[$lid] = round(($qtyByLogistic[$lid] ?? 0) + $qty, 2);
    }
}
if (count($allocations) > 200) {
    aos_fail('A price adjustment can have at most 200 allocated lines.');
}

$userId = (int) $_SESSION['user_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    // ---- Customer (locked — same reasoning as create_return.php) ----
    $st = $lisani_conn->prepare('SELECT id, customer_name FROM customers WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $customerId);
    $st->execute();
    $customer = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$customer) {
        throw new AosPriceAdjustmentError('Customer was not found.');
    }

    // ---- Products (locked, always in id order) ----
    $ids = array_keys($qtyByLogistic);
    sort($ids);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $lisani_conn->prepare(
        'SELECT l.id, l.primary_unit_label, a.activity_name
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
            throw new AosPriceAdjustmentError('A product in this price adjustment was not found.');
        }
    }

    // ---- Lock every source movement this adjustment allocates against ----
    $smIds = array_values(array_unique(array_column($allocations, 'source_movement_id')));
    sort($smIds);
    $smMarks = implode(',', array_fill(0, count($smIds), '?'));
    $st = $lisani_conn->prepare(
        "SELECT id, logistic_id, movement_date, qty_primary_package
         FROM logistic_movements
         WHERE id IN ($smMarks) AND customer_id = ? AND movement_type = 'out'
         FOR UPDATE"
    );
    $types  = str_repeat('i', count($smIds)) . 'i';
    $params = array_merge($smIds, [$customerId]);
    $st->bind_param($types, ...$params);
    $st->execute();
    $sourceMovements = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $sourceMovements[(int) $row['id']] = $row;
    }
    $st->close();

    foreach ($smIds as $smid) {
        if (!isset($sourceMovements[$smid])) {
            throw new AosPriceAdjustmentError('One of the selected pickups could not be found. Please reload and try again.');
        }
    }
    foreach ($allocations as $al) {
        if ((int) $sourceMovements[$al['source_movement_id']]['logistic_id'] !== $al['logistic_id']) {
            throw new AosPriceAdjustmentError('A selected pickup does not match its product. Please reload and try again.');
        }
    }

    // ---- Already returned + already adjusted qty per source movement, so
    //      far — both reduce how much of that pickup is still adjustable
    //      (see list_return_price_options.php's remaining_adjustable). ----
    $st = $lisani_conn->prepare(
        "SELECT source_movement_id, COALESCE(SUM(qty_primary_package), 0) AS consumed
         FROM logistic_movements
         WHERE source_movement_id IN ($smMarks) AND movement_type IN ('in', 'price_adjustment')
         GROUP BY source_movement_id"
    );
    $st->bind_param(str_repeat('i', count($smIds)), ...$smIds);
    $st->execute();
    $alreadyConsumed = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $alreadyConsumed[(int) $row['source_movement_id']] = (float) $row['consumed'];
    }
    $st->close();

    $requestedBySource = [];
    foreach ($allocations as $al) {
        $smid = $al['source_movement_id'];
        $requestedBySource[$smid] = round(($requestedBySource[$smid] ?? 0) + $al['qty'], 2);
    }

    $shortages = [];
    foreach ($requestedBySource as $smid => $requested) {
        $sm        = $sourceMovements[$smid];
        $lid       = (int) $sm['logistic_id'];
        $remaining = round((float) $sm['qty_primary_package'] - ($alreadyConsumed[$smid] ?? 0), 2);
        if ($requested > $remaining + 0.0001) {
            $shortages[] = [
                'logistic_id'        => $lid,
                'product_name'       => $logistics[$lid]['activity_name'],
                'source_movement_id' => $smid,
                'movement_date'      => $sm['movement_date'],
                'available'          => $remaining,
                'requested'          => $requested,
                'reason'             => 'Only ' . fmt_qty($remaining) . ' ' . $logistics[$lid]['primary_unit_label']
                    . ' of ' . $logistics[$lid]['activity_name'] . ' from the pickup on ' . $sm['movement_date']
                    . ' is still eligible for a price adjustment (already returned or adjusted, requested '
                    . fmt_qty($requested) . ').',
            ];
        }
    }

    if ($shortages) {
        throw new AosPriceAdjustmentError(
            'Some products cannot be adjusted as entered.',
            ['code' => 'not_adjustable', 'items' => $shortages]
        );
    }

    // ---- Build product-level lines + grand discount ----
    $byLid = [];
    $grand = 0.0;
    foreach ($allocations as $al) {
        $lid      = $al['logistic_id'];
        $discount = round(($al['old_price'] - $al['new_price']) * $al['qty'], 2);
        $grand    = round($grand + $discount, 2);

        if (!isset($byLid[$lid])) {
            $byLid[$lid] = [
                'logistic_id'    => $lid,
                'product_name'   => $logistics[$lid]['activity_name'],
                'unit_label'     => $logistics[$lid]['primary_unit_label'],
                'qty'            => 0.0,
                'discount_total' => 0.0,
                'allocations'    => [],
            ];
        }
        $byLid[$lid]['qty']            = round($byLid[$lid]['qty'] + $al['qty'], 2);
        $byLid[$lid]['discount_total'] = round($byLid[$lid]['discount_total'] + $discount, 2);
        $byLid[$lid]['allocations'][] = [
            'source_movement_id'   => $al['source_movement_id'],
            'source_movement_date' => $sourceMovements[$al['source_movement_id']]['movement_date'],
            'qty'                  => $al['qty'],
            'old_price'            => $al['old_price'],
            'new_price'            => $al['new_price'],
            'discount_total'       => $discount,
        ];
    }
    $outLines = array_values($byLid);

    // ---- Invoice: reuse the customer's open invoice if one exists, same
    //      rule as create_order.php / create_return.php ----
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
        $invoiceNumber = sprintf('%03d', $invoiceSeq) . '/adj/laj-' . $initials . '-' . $initialsIndex
            . '/' . roman_month($periodMonth) . '/' . $periodYear;

        $chk = $lisani_conn->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $chk->bind_param('s', $invoiceNumber);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        if ($taken) {
            throw new AosPriceAdjustmentError('Could not create a new invoice number.');
        }
        if ($invoiceSeq > 65535) {
            throw new AosPriceAdjustmentError('Could not create a new invoice number.');
        }
    }

    $adj = [
        'dry_run'         => $dryRun,
        'customer'        => ['id' => $customerId, 'name' => $customer['customer_name']],
        'adjustment_date' => $adjustmentDate,
        'driver_name'     => $driver !== '' ? $driver : null,
        'police_number'   => $police !== '' ? $police : null,
        'invoice'         => [
            'id'           => $invoiceId,
            'number'       => $invoiceNumber,
            'is_new'       => $invoiceIsNew,
            'total_before' => $totalBefore,
            'total_after'  => round($totalBefore - $grand, 2),
        ],
        'items'           => $outLines,
        'grand_discount'  => $grand,
    ];

    // ---- Preview only: nothing was written, release the locks ----
    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Price adjustment is ready to be saved.', 'adj' => $adj]);
    }

    // ================= WRITES =================
    $driverDb = $driver !== '' ? $driver : null;
    $policeDb = $police !== '' ? $police : null;

    if ($invoiceIsNew) {
        $negGrand = money(0 - $grand);
        $st = $lisani_conn->prepare(
            "INSERT INTO invoices
               (customer_id, invoice_number, sequence_number, period_month, period_year,
                status, total_amount, paid_amount)
             VALUES (?, ?, ?, ?, ?, 'open', ?, 0)"
        );
        $st->bind_param('isiiis', $customerId, $invoiceNumber, $invoiceSeq, $periodMonth, $periodYear, $negGrand);
        $st->execute();
        $invoiceId = (int) $lisani_conn->insert_id;
        $st->close();
    } else {
        $negGrand = money(0 - $grand);
        $st = $lisani_conn->prepare('UPDATE invoices SET total_amount = total_amount + ? WHERE id = ?');
        $st->bind_param('si', $negGrand, $invoiceId);
        $st->execute();
        $st->close();
    }

    // One row per ALLOCATION, movement_type='price_adjustment'. Stock is
    // NEVER touched here — no logistics UPDATE anywhere in this file.
    $movIns = $lisani_conn->prepare(
        "INSERT INTO logistic_movements
           (logistic_id, customer_id, movement_type, movement_date, customer_name,
            driver_name, police_number, qty_primary_package, price, total_price,
            invoice_id, created_by, source_movement_id)
         VALUES (?, ?, 'price_adjustment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $movementIds = [];
    foreach ($allocations as $al) {
        $lid  = $al['logistic_id'];
        $q    = money($al['qty']);
        $p    = money($al['new_price']);
        $t    = money(round(($al['old_price'] - $al['new_price']) * $al['qty'], 2));
        $smid = $al['source_movement_id'];
        $movIns->bind_param(
            'iisssssssiii',
            $lid, $customerId, $adjustmentDate, $customer['customer_name'],
            $driverDb, $policeDb, $q, $p, $t, $invoiceId, $userId, $smid
        );
        $movIns->execute();
        $movementIds[] = (int) $lisani_conn->insert_id;
    }
    $movIns->close();

    // batch_id groups this adjustment's rows into one card, same convention
    // as create_order.php / create_return.php.
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
    // total_outflow: same accumulator physical returns use, so net
    // receivable (total_inflow - total_outflow) stays correct.
    // total_price_adjustments: separate, purely for reporting.
    $st = $lisani_conn->prepare(
        'UPDATE customers
         SET total_outflow = total_outflow + ?,
             total_price_adjustments = total_price_adjustments + ?
         WHERE id = ?'
    );
    $st->bind_param('ssi', $g, $g, $customerId);
    $st->execute();
    $st->close();

    $lisani_conn->commit();

    $adj['invoice']['id'] = $invoiceId;
    $adj['movement_ids']  = $movementIds;

    aos_json(['ok' => true, 'message' => 'Price adjustment saved.', 'adj' => $adj]);
} catch (AosPriceAdjustmentError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('create_price_adjustment.php: ' . $e->getMessage());
    aos_fail('Failed to save the price adjustment.');
}
