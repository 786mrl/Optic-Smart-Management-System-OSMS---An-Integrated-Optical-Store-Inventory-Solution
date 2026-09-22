<?php
// lisani_aos/ajax/create_return.php
// Records ONE Sales Transaction RETURN (one customer, one date, N products)
// after the WhatsApp message was parsed and the user reviewed it. Mirrors
// create_order.php's dry-run/save pattern, but for goods coming BACK from a
// customer, not going out. See "Sales Transaction — Tab Returns" in
// PROJECT_NOTES.md for the agreed design.
//
// Differences from create_order.php, all deliberate (user decisions,
// 21-22 Sep 2026):
//   - NOT tied to any specific order/invoice: only requires a customer.
//   - Price is entered MANUALLY per line by the user (picked from that
//     product's price history, or typed) — never pulled automatically.
//   - Reuses the customer's OPEN invoice if one exists (its total_amount is
//     simply reduced by the return, and may go negative). Only when the
//     customer has NO open invoice does this create a brand-new one, with
//     a negative total_amount from the start ("/ret/" instead of "/inv/"
//     in its number, so it's still obviously a return-only invoice).
//   - Validated against how much the customer has EVER taken minus what
//     was already returned (net available). A product never taken at all,
//     or a qty above what's still available, is rejected outright — no
//     partial acceptance.
//
// Two modes, SAME code path so the preview can never differ from what is saved:
//   dry_run = 1 (default) : computes everything, writes nothing, returns the
//                           return for the confirm window.
//   dry_run = 0           : writes it all in ONE DB transaction.
//
// POST:
//   customer_id, return_date (Y-m-d), driver_name, police_number,
//   items JSON  [ {"logistic_id": 10, "allocations": [
//                    {"source_movement_id": 55, "qty": 2, "price": 170000},
//                    {"source_movement_id": 61, "qty": 3, "price": 165000}
//                  ]}, ... ]
//   dry_run    "1" | "0"
//
// Revised 22 Sep 2026 (lot-based returns — see PROJECT_NOTES.md, "Redesign
// Returns: alokasi per-lot"): a returned qty is no longer validated/priced
// against a customer/product-wide aggregate. Every unit returned must be
// allocated against a SPECIFIC pickup (a movement_type='out' row — its
// "source movement"), and each allocation gets its own price (still
// defaulted client-side to that pickup's price, but sent here already
// resolved). One product line commonly carries several allocations when the
// return is split across pickups (e.g. 2 units from one day, 3 from
// another) — each allocation becomes its OWN logistic_movements ('in') row,
// stamped with source_movement_id, so the split stays traceable forever.
//
// Response contract: { ok, message, ... }
//   ok: true  -> ret { customer, return_date, driver_name, police_number,
//                      invoice{id,number,total_before,total_after},
//                      items[]: { logistic_id, product_name, unit_label, qty,
//                        total_price, remaining_before, remaining_after,
//                        allocations[]: { source_movement_id,
//                        source_movement_date, qty, price, total_price } },
//                      grand_total, dry_run, movement_ids? }
//   ok: false -> message, and for a shortage also
//                code: "not_returnable", items: [ {logistic_id, product_name,
//                source_movement_id, movement_date, available, requested,
//                reason} ]

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// A business-rule failure: rolled back and reported to the browser as-is.
class AosReturnError extends Exception
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
        error_log('create_return.php stray output: ' . $noise);
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

// Same [n] scheme as create_order.php's invoice_initials_index(), but scans
// BOTH "/inv/laj-...-n/" (orders) and "/ret/laj-...-n/" (returns) numbers,
// since both live in the same `invoices` table and must never collide for
// a customer sharing initials with someone else.
function invoice_initials_index(mysqli $conn, int $customerId, string $initials): int
{
    $re = '#/(?:inv|ret)/laj-([^/]+)-(\d+)/#u';

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
$customerId = (int) post_str('customer_id');
$returnDate = post_str('return_date');
$driver     = mb_strtoupper(post_str('driver_name'));
$police     = mb_strtoupper(post_str('police_number'));
$dryRun     = post_str('dry_run') !== '0'; // anything except an explicit "0" is a preview

if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $returnDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $returnDate) {
    aos_fail('Return date is not valid.');
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
    aos_fail('The return has no products.');
}
if (count($rawItems) > 50) {
    aos_fail('A return can have at most 50 product lines.');
}

// Allocations are kept as a FLAT list (never merged), because two
// allocations for the same product routinely carry two different prices —
// that is the whole point of lot-based returns. Requested qty is summed PER
// SOURCE MOVEMENT (for the per-pickup remaining check) and PER PRODUCT (for
// the product-level lines built after the checks pass).
$allocations   = [];
$qtyByLogistic = [];
foreach ($rawItems as $it) {
    $lid    = isset($it['logistic_id']) ? (int) $it['logistic_id'] : 0;
    $allocs = $it['allocations'] ?? null;
    if ($lid <= 0 || !is_array($allocs) || count($allocs) === 0) {
        aos_fail('Every product line needs a product and at least one pickup allocation.');
    }
    foreach ($allocs as $al) {
        $smid  = isset($al['source_movement_id']) ? (int) $al['source_movement_id'] : 0;
        $qty   = isset($al['qty']) ? clean_number((string) $al['qty']) : null;
        $price = isset($al['price']) ? clean_number((string) $al['price']) : null;
        if ($smid <= 0 || $qty === null || $qty <= 0 || $qty > 99999.99) {
            aos_fail('Every allocated line needs a source pickup and a quantity above zero.');
        }
        if ($price === null || $price <= 0 || $price > 9999999999999.99) {
            aos_fail('Every allocated line needs a price above zero.');
        }
        $qty   = round($qty, 2);
        $price = round($price, 2);
        $allocations[] = [
            'logistic_id'        => $lid,
            'source_movement_id' => $smid,
            'qty'                => $qty,
            'price'              => $price,
        ];
        $qtyByLogistic[$lid] = round(($qtyByLogistic[$lid] ?? 0) + $qty, 2);
    }
}
if (count($allocations) > 200) {
    aos_fail('A return can have at most 200 allocated lines.');
}

$userId = (int) $_SESSION['user_id'];

// Make mysqli throw on failure (default only from PHP 8.1) so the catch below always fires.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    // ---- Customer (locked: serializes returns/orders of the same customer,
    //      keeping invoice numbering and total_outflow consistent) ----
    $st = $lisani_conn->prepare('SELECT id, customer_name FROM customers WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $customerId);
    $st->execute();
    $customer = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$customer) {
        throw new AosReturnError('Customer was not found.');
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
            throw new AosReturnError('A product in this return was not found.');
        }
    }

    // ---- Lock every source movement this return allocates against, and
    //      re-verify it belongs to (this customer, the product the client
    //      claims) and is itself a pickup ('out'). Locked against concurrent
    //      returns of the same customer via the customer row lock above —
    //      same pattern create_order.php relies on for invoice numbering. ----
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
            // Not a pickup of this customer at all — stale UI or tampering,
            // not a normal "not enough stock" situation.
            throw new AosReturnError('One of the selected pickups could not be found. Please reload and try again.');
        }
    }
    foreach ($allocations as $al) {
        if ((int) $sourceMovements[$al['source_movement_id']]['logistic_id'] !== $al['logistic_id']) {
            throw new AosReturnError('A selected pickup does not match its product. Please reload and try again.');
        }
    }

    // ---- Already-returned qty per source movement, so far. ----
    $st = $lisani_conn->prepare(
        "SELECT source_movement_id, COALESCE(SUM(qty_primary_package), 0) AS returned
         FROM logistic_movements
         WHERE source_movement_id IN ($smMarks) AND movement_type = 'in'
         GROUP BY source_movement_id"
    );
    $st->bind_param(str_repeat('i', count($smIds)), ...$smIds);
    $st->execute();
    $alreadyReturned = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $alreadyReturned[(int) $row['source_movement_id']] = (float) $row['returned'];
    }
    $st->close();

    // ---- Requested qty per source movement (an allocation could in theory
    //      repeat a source id if the client sent two lines for it) ----
    $requestedBySource = [];
    foreach ($allocations as $al) {
        $smid = $al['source_movement_id'];
        $requestedBySource[$smid] = round(($requestedBySource[$smid] ?? 0) + $al['qty'], 2);
    }

    $shortages = [];
    foreach ($requestedBySource as $smid => $requested) {
        $sm        = $sourceMovements[$smid];
        $lid       = (int) $sm['logistic_id'];
        $remaining = round((float) $sm['qty_primary_package'] - ($alreadyReturned[$smid] ?? 0), 2);
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
                    . ' is still available to return (requested ' . fmt_qty($requested) . ').',
            ];
        }
    }

    if ($shortages) {
        throw new AosReturnError(
            'Some products cannot be returned as entered.',
            ['code' => 'not_returnable', 'items' => $shortages]
        );
    }

    // ---- Build product-level lines (grouping allocations back by product,
    //      for the invoice total and the confirm-window display), + grand
    //      total (price comes straight from the client; already validated
    //      > 0 above, per allocation) ----
    $byLid = [];
    $grand = 0.0;
    foreach ($allocations as $al) {
        $lid   = $al['logistic_id'];
        $total = round($al['qty'] * $al['price'], 2);
        $grand = round($grand + $total, 2);

        if (!isset($byLid[$lid])) {
            $byLid[$lid] = [
                'logistic_id'  => $lid,
                'product_name' => $logistics[$lid]['activity_name'],
                'unit_label'   => $logistics[$lid]['primary_unit_label'],
                'qty'          => 0.0,
                'total_price'  => 0.0,
                'allocations'  => [],
            ];
        }
        $byLid[$lid]['qty']         = round($byLid[$lid]['qty'] + $al['qty'], 2);
        $byLid[$lid]['total_price'] = round($byLid[$lid]['total_price'] + $total, 2);
        $byLid[$lid]['allocations'][] = [
            'source_movement_id'   => $al['source_movement_id'],
            'source_movement_date' => $sourceMovements[$al['source_movement_id']]['movement_date'],
            'qty'                  => $al['qty'],
            'price'                => $al['price'],
            'total_price'          => $total,
        ];
    }

    $outLines = [];
    foreach ($byLid as $lid => $row) {
        $remainingBefore    = (float) ($logistics[$lid]['remaining_primary_qty'] ?? 0);
        $row['remaining_before'] = $remainingBefore;
        $row['remaining_after']  = round($remainingBefore + $row['qty'], 2);
        $outLines[] = $row;
    }

    // ---- Invoice: reuse the customer's open invoice if one exists (same
    //      rule as create_order.php); only create a new one, with a
    //      NEGATIVE total, when the customer has none open. Never creates a
    //      second open invoice alongside an existing one. ----
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
        $invoiceNumber = sprintf('%03d', $invoiceSeq) . '/ret/laj-' . $initials . '-' . $initialsIndex
            . '/' . roman_month($periodMonth) . '/' . $periodYear;

        $chk = $lisani_conn->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $chk->bind_param('s', $invoiceNumber);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        if ($taken) {
            throw new AosReturnError('Could not create a new invoice number.');
        }
        if ($invoiceSeq > 65535) {
            throw new AosReturnError('Could not create a new invoice number.');
        }
    }

    $ret = [
        'dry_run'       => $dryRun,
        'customer'      => ['id' => $customerId, 'name' => $customer['customer_name']],
        'return_date'   => $returnDate,
        'driver_name'   => $driver !== '' ? $driver : null,
        'police_number' => $police !== '' ? $police : null,
        'invoice'       => [
            'id'           => $invoiceId,
            'number'       => $invoiceNumber,
            'is_new'       => $invoiceIsNew,
            'total_before' => $totalBefore,
            'total_after'  => round($totalBefore - $grand, 2),
        ],
        'items'         => $outLines,
        'grand_total'   => $grand,
    ];

    // ---- Preview only: nothing was written, release the locks ----
    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Return is ready to be saved.', 'ret' => $ret]);
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
        $negGrand = money(0 - $grand); // subtract: total_amount = total_amount + (-grand)
        $st = $lisani_conn->prepare('UPDATE invoices SET total_amount = total_amount + ? WHERE id = ?');
        $st->bind_param('si', $negGrand, $invoiceId);
        $st->execute();
        $st->close();
    }

    // One row per ALLOCATION now (not per product) — that's what makes each
    // split traceable back to the exact pickup it came from.
    $movIns = $lisani_conn->prepare(
        "INSERT INTO logistic_movements
           (logistic_id, customer_id, movement_type, movement_date, customer_name,
            driver_name, police_number, qty_primary_package, price, total_price,
            invoice_id, created_by, source_movement_id)
         VALUES (?, ?, 'in', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $logUpd = $lisani_conn->prepare(
        'UPDATE logistics
         SET remaining_primary_qty = remaining_primary_qty + ?,
             total_taken_qty = GREATEST(total_taken_qty - ?, 0)
         WHERE id = ?'
    );

    $movementIds = [];
    foreach ($allocations as $al) {
        $lid  = $al['logistic_id'];
        $q    = money($al['qty']);
        $p    = money($al['price']);
        $t    = money(round($al['qty'] * $al['price'], 2));
        $smid = $al['source_movement_id'];
        $movIns->bind_param(
            'iisssssssiii',
            $lid, $customerId, $returnDate, $customer['customer_name'],
            $driverDb, $policeDb, $q, $p, $t, $invoiceId, $userId, $smid
        );
        $movIns->execute();
        $movementIds[] = (int) $lisani_conn->insert_id;

        $logUpd->bind_param('ssi', $q, $q, $lid);
        $logUpd->execute();
    }
    $movIns->close();
    $logUpd->close();

    // batch_id groups this return's rows into one card, same convention as
    // create_order.php (lowest movement id of the batch).
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
    $st = $lisani_conn->prepare('UPDATE customers SET total_outflow = total_outflow + ? WHERE id = ?');
    $st->bind_param('si', $g, $customerId);
    $st->execute();
    $st->close();

    $lisani_conn->commit();

    $ret['invoice']['id'] = $invoiceId;
    $ret['movement_ids']  = $movementIds;

    aos_json(['ok' => true, 'message' => 'Return saved.', 'ret' => $ret]);
} catch (AosReturnError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('create_return.php: ' . $e->getMessage());
    aos_fail('Failed to save the return.');
}