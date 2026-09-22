<?php
// lisani_aos/ajax/list_customer_orders.php
// Data for one customer card in Sales Transaction > Customers tab:
//   - totals (ordered value from customers.total_inflow, paid from customers.total_paid)
//   - taken quantity + value PER PRODUCT
//   - order history grouped PER INVOICE (newest invoice first)
// Read-only. Only movement_type = 'out' (customer took goods) is listed.
//
// GET: customer_id
// Response contract: { ok, message?, data: { customer, products[], invoices[] } }

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Every exit goes through here so the browser always receives clean JSON.
function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('list_customer_orders.php stray output: ' . $noise);
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

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    aos_fail('Session expired. Please log in again.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // ---- Customer totals ----
    $st = $lisani_conn->prepare('SELECT id, customer_name, total_inflow, total_outflow, total_paid FROM customers WHERE id = ?');
    $st->bind_param('i', $customerId);
    $st->execute();
    $customer = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$customer) {
        aos_fail('Customer was not found.');
    }

    // ---- Per product (taken vs returned kept separate, so the card can
    //      show both instead of a single net number) ----
    $st = $lisani_conn->prepare(
        "SELECT m.logistic_id, a.activity_name, l.primary_unit_label AS unit_label,
                SUM(CASE WHEN m.movement_type = 'out' THEN m.qty_primary_package ELSE 0 END) AS total_qty,
                SUM(CASE WHEN m.movement_type = 'out' THEN m.total_price ELSE 0 END) AS total_value,
                SUM(CASE WHEN m.movement_type = 'in' THEN m.qty_primary_package ELSE 0 END) AS returned_qty,
                SUM(CASE WHEN m.movement_type = 'in' THEN m.total_price ELSE 0 END) AS returned_value
         FROM logistic_movements m
         JOIN logistics l ON l.id = m.logistic_id
         JOIN activities a ON a.id = l.activity_id
         WHERE m.customer_id = ?
         GROUP BY m.logistic_id, a.activity_name, l.primary_unit_label
         HAVING total_qty > 0
         ORDER BY total_value DESC, a.activity_name ASC"
    );
    $st->bind_param('i', $customerId);
    $st->execute();
    $products = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $products[] = [
            'logistic_id'    => (int) $row['logistic_id'],
            'activity_name'  => $row['activity_name'],
            'unit_label'     => $row['unit_label'],
            'total_qty'      => $row['total_qty'],
            'total_value'    => $row['total_value'],
            'returned_qty'   => $row['returned_qty'],
            'returned_value' => $row['returned_value'],
        ];
    }
    $st->close();

    // ---- Invoices ----
    $st = $lisani_conn->prepare(
        'SELECT id, invoice_number, status, total_amount, paid_amount, created_at
         FROM invoices
         WHERE customer_id = ?
         ORDER BY id DESC'
    );
    $st->bind_param('i', $customerId);
    $st->execute();
    $invoices = [];
    $byId = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $inv = [
            'id'             => (int) $row['id'],
            'invoice_number' => $row['invoice_number'],
            'status'         => $row['status'],
            'total_amount'   => $row['total_amount'],
            'paid_amount'    => $row['paid_amount'],
            'created_at'     => $row['created_at'],
            'movements'      => [],
        ];
        $invoices[] = $inv;
        $byId[$inv['id']] = count($invoices) - 1;
    }
    $st->close();

    // ---- Movements, dropped into their invoice ----
    // created_at (down to the minute) + driver_name + police_number is how
    // the frontend groups these flat rows back into order cards — same
    // grouping key as check_existing_orders.php — so it has to be selected
    // here even though nothing else in this endpoint uses it.
    $st = $lisani_conn->prepare(
        "SELECT m.id, m.invoice_id, m.movement_type, m.movement_date, m.created_at, m.batch_id, m.driver_name, m.police_number,
                m.qty_primary_package, m.price, m.total_price,
                m.source_movement_id, sm.movement_date AS source_movement_date,
                a.activity_name, l.primary_unit_label AS unit_label
         FROM logistic_movements m
         JOIN logistics l ON l.id = m.logistic_id
         JOIN activities a ON a.id = l.activity_id
         LEFT JOIN logistic_movements sm ON sm.id = m.source_movement_id
         WHERE m.customer_id = ?
         ORDER BY m.movement_date DESC, m.created_at DESC, m.id DESC"
    );
    $st->bind_param('i', $customerId);
    $st->execute();
    $noInvoice = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $mov = [
            'id'            => (int) $row['id'],
            'movement_type' => $row['movement_type'], // 'out' = taken, 'in' = returned
            'movement_date' => $row['movement_date'],
            'created_at'    => $row['created_at'],
            'batch_id'      => $row['batch_id'] === null ? null : (int) $row['batch_id'],
            'activity_name' => $row['activity_name'],
            'unit_label'    => $row['unit_label'],
            'qty'           => $row['qty_primary_package'],
            'price'         => $row['price'],
            'total_price'   => $row['total_price'],
            'driver_name'   => $row['driver_name'],
            'police_number' => $row['police_number'],
            // Only set for movement_type='in' rows created by the lot-based
            // return flow (22 Sep 2026 onward) — null for everything older.
            'source_movement_id'   => $row['source_movement_id'] === null ? null : (int) $row['source_movement_id'],
            'source_movement_date' => $row['source_movement_date'],
        ];
        $iid = $row['invoice_id'] === null ? null : (int) $row['invoice_id'];
        if ($iid !== null && isset($byId[$iid])) {
            $invoices[$byId[$iid]]['movements'][] = $mov;
        } else {
            $noInvoice[] = $mov; // older rows that were never attached to an invoice
        }
    }
    $st->close();

    if ($noInvoice) {
        $invoices[] = [
            'id'             => null,
            'invoice_number' => '(No invoice)',
            'status'         => 'none',
            'total_amount'   => null,
            'paid_amount'    => null,
            'created_at'     => null,
            'movements'      => $noInvoice,
        ];
    }

    aos_json([
        'ok'   => true,
        'data' => [
            'customer' => [
                'id'             => (int) $customer['id'],
                'customer_name'  => $customer['customer_name'],
                'total_ordered'  => $customer['total_inflow'],
                'total_returned' => $customer['total_outflow'],
                'total_paid'     => $customer['total_paid'],
            ],
            'products' => $products,
            'invoices' => $invoices,
        ],
    ]);
} catch (Throwable $e) {
    error_log('list_customer_orders.php: ' . $e->getMessage());
    aos_fail('Failed to load the order history.');
}