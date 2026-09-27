<?php
// lisani_aos/ajax/manage_defective_stock.php
// Backs the "Defective Stock" tab (see "Kendala #1 & #2" in
// PROJECT_NOTES.md, 22 Sep 2026). Three actions, all against `logistics`
// directly — this is warehouse-level housekeeping, not a customer
// transaction, so it never touches logistic_movements or any customer.
//
// GET  ?action=list
//   Every product that currently has defective stock, or has ever had a
//   reference price set for it (so the row doesn't disappear the moment
//   defective_qty hits 0 — the reference price is still worth keeping).
//   Response: { ok, data: [ { logistic_id, product_name, unit_label,
//     defective_qty, defective_taken_qty, defective_reference_price } ] }
//
// POST action=set_price   logistic_id, price
//   Sets logistics.defective_reference_price. Logged to
//   defective_stock_events (event_type='reference_price_set').
//   This is a FALLBACK default only — create_order.php checks a customer's
//   own customer_item_prices entry first, so a customer-specific price
//   always still wins over this reference price.
//
// POST action=repair   logistic_id, qty
//   Moves qty from defective_qty to remaining_primary_qty (goods were
//   fixed and are sellable as normal again). Cannot exceed defective_qty.
//   Logged to defective_stock_events (event_type='repaired_to_normal').
//   Deliberately the ONLY way defective_qty decreases other than being sold
//   with stock_source='defective' in a New Order.

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('manage_defective_stock.php stray output: ' . $noise);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    aos_fail('Session expired. Please log in again.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

$userId = (int) $_SESSION['user_id'];
$action = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    if ($action === 'list') {
        $st = $lisani_conn->prepare(
            "SELECT l.id AS logistic_id, a.activity_name AS product_name, l.primary_unit_label AS unit_label,
                    l.defective_qty, l.defective_taken_qty, l.defective_reference_price
             FROM logistics l
             JOIN activities a ON a.id = l.activity_id
             WHERE l.defective_qty > 0 OR l.defective_taken_qty > 0 OR l.defective_reference_price IS NOT NULL
             ORDER BY a.activity_name ASC"
        );
        $st->execute();
        $rows = [];
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'logistic_id'               => (int) $row['logistic_id'],
                'product_name'              => $row['product_name'],
                'unit_label'                => $row['unit_label'],
                'defective_qty'             => (float) $row['defective_qty'],
                'defective_taken_qty'       => (float) $row['defective_taken_qty'],
                'defective_reference_price' => $row['defective_reference_price'] === null ? null : (float) $row['defective_reference_price'],
            ];
        }
        $st->close();
        aos_json(['ok' => true, 'data' => $rows]);
    }

    if ($action === 'set_price') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            aos_fail('Invalid request.');
        }
        $lid   = (int) ($_POST['logistic_id'] ?? 0);
        $price = isset($_POST['price']) ? clean_number((string) $_POST['price']) : null;
        if ($lid <= 0) {
            aos_fail('Product is missing.');
        }
        if ($price === null || $price <= 0 || $price > 9999999999999.99) {
            aos_fail('Enter a reference price above zero.');
        }
        $price = round($price, 2);

        $lisani_conn->begin_transaction();

        $st = $lisani_conn->prepare('SELECT defective_reference_price FROM logistics WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $lid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            $lisani_conn->rollback();
            aos_fail('Product was not found.');
        }
        $oldPrice = $row['defective_reference_price'] === null ? null : (float) $row['defective_reference_price'];

        $p = money($price);
        $st = $lisani_conn->prepare('UPDATE logistics SET defective_reference_price = ? WHERE id = ?');
        $st->bind_param('si', $p, $lid);
        $st->execute();
        $st->close();

        $oldP = $oldPrice === null ? null : money($oldPrice);
        $st = $lisani_conn->prepare(
            "INSERT INTO defective_stock_events (logistic_id, event_type, old_price, new_price, created_by)
             VALUES (?, 'reference_price_set', ?, ?, ?)"
        );
        $st->bind_param('issi', $lid, $oldP, $p, $userId);
        $st->execute();
        $st->close();

        $lisani_conn->commit();
        aos_json(['ok' => true, 'message' => 'Reference price saved.']);
    }

    if ($action === 'repair') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            aos_fail('Invalid request.');
        }
        $lid = (int) ($_POST['logistic_id'] ?? 0);
        $qty = isset($_POST['qty']) ? clean_number((string) $_POST['qty']) : null;
        if ($lid <= 0) {
            aos_fail('Product is missing.');
        }
        if ($qty === null || $qty <= 0 || $qty > 99999.99) {
            aos_fail('Enter a quantity above zero.');
        }
        $qty = round($qty, 2);

        $lisani_conn->begin_transaction();

        $st = $lisani_conn->prepare('SELECT defective_qty, primary_unit_label FROM logistics WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $lid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            $lisani_conn->rollback();
            aos_fail('Product was not found.');
        }
        $available = (float) $row['defective_qty'];
        if ($qty > $available + 0.0001) {
            $lisani_conn->rollback();
            aos_fail('Only ' . rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.')
                . ' ' . $row['primary_unit_label'] . ' of defective stock is on hand.');
        }

        $q = money($qty);
        $st = $lisani_conn->prepare(
            'UPDATE logistics
             SET defective_qty = defective_qty - ?,
                 remaining_primary_qty = remaining_primary_qty + ?
             WHERE id = ?'
        );
        $st->bind_param('ssi', $q, $q, $lid);
        $st->execute();
        $st->close();

        $st = $lisani_conn->prepare(
            "INSERT INTO defective_stock_events (logistic_id, event_type, qty, created_by)
             VALUES (?, 'repaired_to_normal', ?, ?)"
        );
        $st->bind_param('isi', $lid, $q, $userId);
        $st->execute();
        $st->close();

        $lisani_conn->commit();
        aos_json(['ok' => true, 'message' => 'Moved back to normal stock.']);
    }

    aos_fail('Unknown action.');
} catch (Throwable $e) {
    @$lisani_conn->rollback();
    error_log('manage_defective_stock.php: ' . $e->getMessage());
    aos_fail('Failed to process the request.');
}
