<?php
// lisani_aos/ajax/update_order.php
// Merges a follow-up WhatsApp message into an EXISTING Sales Transaction
// order (same customer, same order_date) instead of creating a new one.
// Used when the user answers "Add to Existing Order" or "Revise Existing
// Order" in the fly window shown by transaction_content.php after Read
// Message, and picks which of the customer's orders for that date to merge
// into (see ajax/check_existing_orders.php).
//
// Same two-mode pattern as create_order.php, SAME code path for preview and
// save:
//   dry_run = 1 (default) : computes everything, writes nothing, returns the
//                           order for the confirm window.
//   dry_run = 0           : writes it all in ONE DB transaction.
//
// POST:
//   customer_id, order_date (Y-m-d), driver_name, police_number,
//   items       JSON  [ {"logistic_id": 10, "qty": 30}, ... ]  (the full,
//               already-merged line list the user reviewed on screen — same
//               shape as create_order.php)
//   movement_ids JSON { "10": 55 }  logistic_id -> id of the EXISTING
//               logistic_movements row to UPDATE for that product (from the
//               order the user picked). A logistic_id in `items` that has no
//               entry here is a brand new line and gets INSERTed instead.
//   new_prices  JSON  { "10": 145000 }  same as create_order.php
//   dry_run     "1" | "0"
//
// Response contract: identical shape to create_order.php's `order`, plus
// each item also carries `mode` ("updated" | "added") and, for "updated",
// `qty_before` so the confirm window can show what changed.
//
// Effects when saved (all or nothing):
//   - Existing lines (in movement_ids): logistic_movements row is UPDATED
//     in place (qty_primary_package, price, total_price) — the old values
//     are simply overwritten, there is no separate correction row and no
//     audit trail of the previous qty (confirmed by user 21 Sep 2026: what
//     matters is what was actually ordered/shipped, which the corrected row
//     now reflects).
//   - New lines: INSERTed exactly like create_order.php, attached to the
//     SAME invoice as the order being revised.
//   - logistics.remaining_primary_qty: for an updated line, the OLD qty is
//     given back to stock before the NEW qty is taken out, so the net stock
//     change is just the difference (works whether qty went up or down).
//     For a new line, stock is taken out as usual.
//   - invoices.total_amount / customers.total_inflow: adjusted by the
//     DIFFERENCE for updated lines (new total_price - old total_price) plus
//     the full total_price of new lines — not the full order value again.

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
        error_log('update_order.php stray output: ' . $noise);
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

// ---------- Helpers (same as create_order.php) ----------
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

// Same product on two lines -> one line with the summed quantity (matches
// create_order.php's behaviour).
$qtyByLogistic = [];
foreach ($rawItems as $it) {
    $lid = isset($it['logistic_id']) ? (int) $it['logistic_id'] : 0;
    $qty = isset($it['qty']) ? clean_number((string) $it['qty']) : null;
    if ($lid <= 0 || $qty === null || $qty <= 0 || $qty > 99999.99) {
        aos_fail('Every product line needs a product and a quantity above zero.');
    }
    $qtyByLogistic[$lid] = round(($qtyByLogistic[$lid] ?? 0) + $qty, 2);
}

// logistic_id -> id of the existing logistic_movements row to UPDATE.
// A logistic_id that is in $qtyByLogistic but not here is a new line.
$movementIdByLogistic = [];
$rawMovIds = json_decode(post_str('movement_ids'), true);
if (is_array($rawMovIds)) {
    foreach ($rawMovIds as $lid => $mid) {
        $lid = (int) $lid;
        $mid = (int) $mid;
        if ($lid > 0 && $mid > 0 && isset($qtyByLogistic[$lid])) {
            $movementIdByLogistic[$lid] = $mid;
        }
    }
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

    // ---- Customer (locked: same reason as create_order.php) ----
    $st = $lisani_conn->prepare('SELECT id, customer_name FROM customers WHERE id = ? FOR UPDATE');
    $st->bind_param('i', $customerId);
    $st->execute();
    $customer = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$customer) {
        throw new AosOrderError('Customer was not found.');
    }

    // ---- Existing movement rows being revised: load + lock, and make sure
    //      every one of them really is an 'out' row that belongs to this
    //      customer and this date (defends against a stale/tampered picker
    //      payload pointing at the wrong row). ----
    $existingRows = []; // movement_id => row
    if ($movementIdByLogistic) {
        $movIds = array_values($movementIdByLogistic);
        sort($movIds);
        $marks = implode(',', array_fill(0, count($movIds), '?'));
        $st = $lisani_conn->prepare(
            "SELECT id, logistic_id, invoice_id, qty_primary_package, price, total_price
             FROM logistic_movements
             WHERE id IN (" . $marks . ") AND customer_id = ? AND movement_type = 'out' AND movement_date = ?
             FOR UPDATE"
        );
        $types  = str_repeat('i', count($movIds)) . 'is';
        $params = array_merge($movIds, [$customerId, $orderDate]);
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $existingRows[(int) $row['id']] = $row;
        }
        $st->close();

        foreach ($movementIdByLogistic as $lid => $mid) {
            if (!isset($existingRows[$mid]) || (int) $existingRows[$mid]['logistic_id'] !== $lid) {
                throw new AosOrderError('The order you are revising has changed. Please pick it again.');
            }
        }
    }

    if (!$existingRows) {
        throw new AosOrderError('No existing order line was selected to revise or add to.');
    }

    // The invoice this order belongs to: taken from any of the existing rows
    // (they were all written together, so they share the same invoice_id).
    $targetInvoiceId = null;
    foreach ($existingRows as $row) {
        if ($row['invoice_id'] !== null) {
            $targetInvoiceId = (int) $row['invoice_id'];
            break;
        }
    }
    if ($targetInvoiceId === null) {
        throw new AosOrderError('The order you are revising has no invoice and cannot be updated here.');
    }

    $st = $lisani_conn->prepare(
        'SELECT id, invoice_number, total_amount FROM invoices WHERE id = ? FOR UPDATE'
    );
    $st->bind_param('i', $targetInvoiceId);
    $st->execute();
    $invoiceRow = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$invoiceRow) {
        throw new AosOrderError('The invoice for this order was not found.');
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

    // ---- Stock: for an updated line the old qty is given back first, so
    //      what actually has to fit in stock is the NET change (new - old).
    //      A new line takes its full qty out, same as create_order.php. ----
    $shortages = [];
    foreach ($ids as $lid) {
        $remaining = (float) ($logistics[$lid]['remaining_primary_qty'] ?? 0);
        $newQty    = $qtyByLogistic[$lid];

        if (isset($movementIdByLogistic[$lid])) {
            $oldQty          = (float) $existingRows[$movementIdByLogistic[$lid]]['qty_primary_package'];
            $remainingIfKept = $remaining + $oldQty; // give the old qty back first
            if ($newQty > $remainingIfKept + 0.0001) {
                $shortages[] = 'Not enough stock for ' . $logistics[$lid]['activity_name']
                    . ': requested ' . fmt_qty($newQty)
                    . ', available ' . fmt_qty($remainingIfKept) . ' ' . $logistics[$lid]['primary_unit_label'] . '.';
            }
        } else {
            if ($newQty > $remaining + 0.0001) {
                $shortages[] = 'Not enough stock for ' . $logistics[$lid]['activity_name']
                    . ': requested ' . fmt_qty($newQty)
                    . ', remaining ' . fmt_qty($remaining) . ' ' . $logistics[$lid]['primary_unit_label'] . '.';
            }
        }
    }
    if ($shortages) {
        throw new AosOrderError(implode("\n", $shortages));
    }

    // ---- Prices: latest price_date that is still <= the order date, same
    //      rule as create_order.php ----
    $priceStmt = $lisani_conn->prepare(
        'SELECT price, price_date
         FROM customer_item_prices
         WHERE customer_id = ? AND logistic_id = ? AND price_date <= ?
         ORDER BY price_date DESC, created_at DESC, id DESC
         LIMIT 1'
    );

    $lines      = [];
    $missing    = [];
    $diffTotal  = 0.0; // net change to invoice/customer totals (can be negative)
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
        $remainingBefore = (float) ($logistics[$lid]['remaining_primary_qty'] ?? 0);

        $isUpdate = isset($movementIdByLogistic[$lid]);
        if ($isUpdate) {
            $existing  = $existingRows[$movementIdByLogistic[$lid]];
            $oldQty    = (float) $existing['qty_primary_package'];
            $oldTotal  = (float) $existing['total_price'];
            $diffTotal = round($diffTotal + ($total - $oldTotal), 2);
            $remainingAfter = round($remainingBefore + $oldQty - $qty, 2);
        } else {
            $diffTotal = round($diffTotal + $total, 2);
            $remainingAfter = round($remainingBefore - $qty, 2);
        }

        $lines[] = [
            'logistic_id'      => $lid,
            'movement_id'      => $isUpdate ? $movementIdByLogistic[$lid] : null,
            'mode'             => $isUpdate ? 'updated' : 'added',
            'product_name'     => $logistics[$lid]['activity_name'],
            'unit_label'       => $logistics[$lid]['primary_unit_label'],
            'qty'              => $qty,
            'qty_before'       => $isUpdate ? $oldQty : null,
            'price'            => $price,
            'price_date'       => $priceDate,
            'price_source'     => $source,
            'total_price'      => $total,
            'remaining_before' => $remainingBefore,
            'remaining_after'  => $remainingAfter,
        ];
    }
    $priceStmt->close();

    if ($missing) {
        throw new AosOrderError(
            'Some products have no price for this customer yet.',
            ['code' => 'missing_prices', 'missing' => $missing]
        );
    }

    $totalBefore = (float) $invoiceRow['total_amount'];

    $order = [
        'dry_run'       => $dryRun,
        'mode'          => 'update',
        'customer'      => ['id' => $customerId, 'name' => $customer['customer_name']],
        'order_date'    => $orderDate,
        'driver_name'   => $driver !== '' ? $driver : null,
        'police_number' => $police !== '' ? $police : null,
        'invoice'       => [
            'id'           => (int) $invoiceRow['id'],
            'number'       => $invoiceRow['invoice_number'],
            'is_new'       => false,
            'total_before' => $totalBefore,
            'total_after'  => round($totalBefore + $diffTotal, 2),
        ],
        'items'         => $lines,
        'grand_total'   => $diffTotal, // the NET change this save makes, not a fresh order's total
    ];

    // ---- Preview only: nothing was written, release the locks ----
    if ($dryRun) {
        $lisani_conn->rollback();
        aos_json(['ok' => true, 'message' => 'Order changes are ready to be saved.', 'order' => $order]);
    }

    // ================= WRITES =================
    $driverDb = $driver !== '' ? $driver : null;
    $policeDb = $police !== '' ? $police : null;

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
    $movUpd = $lisani_conn->prepare(
        'UPDATE logistic_movements
         SET driver_name = ?, police_number = ?, qty_primary_package = ?, price = ?, total_price = ?
         WHERE id = ?'
    );
    // Stock delta: old qty back in (only for updated lines), new qty out.
    $logUpdWithOld = $lisani_conn->prepare(
        'UPDATE logistics
         SET remaining_primary_qty = remaining_primary_qty + ? - ?,
             total_taken_qty = total_taken_qty - ? + ?
         WHERE id = ?'
    );
    $logUpdNew = $lisani_conn->prepare(
        'UPDATE logistics
         SET remaining_primary_qty = remaining_primary_qty - ?,
             total_taken_qty = total_taken_qty + ?
         WHERE id = ?'
    );

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

        if ($ln['mode'] === 'updated') {
            // qty/price/total are DECIMAL columns; money() already formats them
            // as plain decimal strings, bound here as 's' like the rest of the file.
            $movUpd->bind_param('sssssi', $driverDb, $policeDb, $q, $p, $t, $ln['movement_id']);
            $movUpd->execute();

            $oldQ = money((float) $ln['qty_before']);
            $logUpdWithOld->bind_param('ssssi', $oldQ, $q, $oldQ, $q, $lid);
            $logUpdWithOld->execute();
        } else {
            $movIns->bind_param(
                'iisssssssii',
                $lid, $customerId, $orderDate, $customer['customer_name'],
                $driverDb, $policeDb, $q, $p, $t, $targetInvoiceId, $userId
            );
            $movIns->execute();

            $logUpdNew->bind_param('ssi', $q, $q, $lid);
            $logUpdNew->execute();
        }
    }
    $priceIns->close();
    $movIns->close();
    $movUpd->close();
    $logUpdWithOld->close();
    $logUpdNew->close();

    $d = money($diffTotal);

    $st = $lisani_conn->prepare('UPDATE customers SET total_inflow = total_inflow + ? WHERE id = ?');
    $st->bind_param('si', $d, $customerId);
    $st->execute();
    $st->close();

    $st = $lisani_conn->prepare('UPDATE invoices SET total_amount = total_amount + ? WHERE id = ?');
    $st->bind_param('si', $d, $targetInvoiceId);
    $st->execute();
    $st->close();

    $lisani_conn->commit();

    aos_json(['ok' => true, 'message' => 'Order updated.', 'order' => $order]);
} catch (AosOrderError $e) {
    $lisani_conn->rollback();
    aos_json($e->payload);
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('update_order.php: ' . $e->getMessage());
    aos_fail('Failed to update the order.');
}