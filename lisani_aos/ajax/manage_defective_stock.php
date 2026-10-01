<?php
// lisani_aos/ajax/manage_defective_stock.php
// Backs the "Defective Stock — Return History" fly window inside the
// Logistic menu's Logistic List tab (logistic_content.php). Warehouse-level,
// scoped to ONE activity code (logistic_id) at a time — opened by clicking
// that product's "Defective Stock" row.
//
// Revised 27 Sep 2026 ("Redesain besar: hapus tab Defective Stock..."): the
// old warehouse-wide tab (`action=list`) and its static reference price
// (`action=set_price`, logistics.defective_reference_price) are GONE — price
// for a defective sale is now always typed fresh in New Order (see
// create_order.php), with a "last given" suggestion computed on the fly from
// logistic_movements instead of a stored column. `action=repair` is
// unchanged and is now the only action left in this file besides the new
// `action=history`.
//
// GET  ?action=history   logistic_id
//   Everything the fly window needs for one product: current defective_qty/
//   defective_taken_qty (so "Repair" can be validated client-side too), and
//   the full pickup history — every logistic_movements row for this
//   logistic_id with movement_type='out' AND stock_source='defective'
//   (i.e. actually sold from defective stock, NOT goods returned in
//   defective condition — that is a separate, incoming direction and is not
//   what this list is for). One entry per movement: customer_name,
//   movement_date, qty, price, total_price.
//   Response: { ok, data: { logistic_id, product_name, unit_label,
//     defective_qty, defective_taken_qty, history: [ {customer_name,
//     movement_date, qty, price, total_price} ] } }
//
// GET  ?action=return_history   logistic_id
//   Added 27 Sep 2026 alongside the fly window's second button ("Returned
//   In"). Mirrors action=history but the OPPOSITE direction: every
//   logistic_movements row for this logistic_id with movement_type='in' AND
//   stock_source='defective' (create_return.php writes stock_source =
//   restock_bucket on the 'in' row it inserts — see that file's header —
//   so restock_bucket='defective' at return time is what lands here). These
//   are customer returns restocked INTO the defective bucket, NOT repairs
//   (repairs are logged in defective_stock_events, a different table, and
//   are not movement rows at all). Same response shape as action=history —
//   just a different `history` array — so the client can swap the list
//   under the same header when the user switches tabs.
//   Response: { ok, data: { logistic_id, product_name, unit_label,
//     defective_qty, defective_taken_qty, history: [ {customer_name,
//     movement_date, qty, price, total_price} ] } }
//
// POST action=repair   logistic_id, qty
//   Moves qty from defective_qty to remaining_primary_qty (goods were fixed
//   and are sellable as normal again). Cannot exceed defective_qty. Logged
//   to defective_stock_events (event_type='repaired_to_normal'). Still the
//   ONLY way defective_qty decreases other than being sold with
//   stock_source='defective' in a New Order.

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
    // Shared by action=history and action=return_history: only the
    // movement_type differs ('out' = taken by a customer, 'in' = returned
    // by a customer back into the defective bucket) — everything else
    // about the product header + row shape is identical.
    if ($action === 'history' || $action === 'return_history') {
        $lid = (int) ($_GET['logistic_id'] ?? 0);
        if ($lid <= 0) {
            aos_fail('Product is missing.');
        }

        $st = $lisani_conn->prepare(
            'SELECT l.defective_qty, l.defective_taken_qty, l.primary_unit_label, l.product_name AS activity_name
             FROM logistics l
             JOIN activities a ON a.id = l.activity_id
             WHERE l.id = ?'
        );
        $st->bind_param('i', $lid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            aos_fail('Product was not found.');
        }

        $movementType = $action === 'history' ? 'out' : 'in';
        $st = $lisani_conn->prepare(
            "SELECT customer_name, movement_date, qty_primary_package AS qty, price, total_price
             FROM logistic_movements
             WHERE logistic_id = ? AND movement_type = ? AND stock_source = 'defective'
             ORDER BY movement_date DESC, created_at DESC, id DESC"
        );
        $st->bind_param('is', $lid, $movementType);
        $st->execute();
        $history = [];
        $res = $st->get_result();
        while ($h = $res->fetch_assoc()) {
            $history[] = [
                'customer_name' => $h['customer_name'],
                'movement_date' => $h['movement_date'],
                'qty'           => (float) $h['qty'],
                'price'         => $h['price'] === null ? null : (float) $h['price'],
                'total_price'   => $h['total_price'] === null ? null : (float) $h['total_price'],
            ];
        }
        $st->close();

        aos_json(['ok' => true, 'data' => [
            'logistic_id'         => $lid,
            'product_name'        => $row['activity_name'],
            'unit_label'          => $row['primary_unit_label'],
            'defective_qty'       => (float) $row['defective_qty'],
            'defective_taken_qty' => (float) $row['defective_taken_qty'],
            'history'             => $history,
        ]]);
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