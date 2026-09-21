<?php
// lisani_aos/ajax/check_existing_orders.php
// Used right after "Read Message" in Sales Transaction (Tab 1) to detect
// whether the selected customer already has other order(s) on the same
// order_date. If so, the UI asks "New Order / Add to Existing / Revise
// Existing" and, for the latter two, shows the list this endpoint returns
// so the user can pick which existing order to merge into.
//
// There is no separate "orders" table: one order is a group of
// logistic_movements rows (movement_type = 'out') that were written by the
// same create_order.php / update_order.php call for this customer on this
// date. Rows are grouped by created_at rounded to the minute (all rows of
// one order share the same request, so their created_at only differs by
// sub-second write time) together with driver_name + police_number, which
// is enough to tell separate orders apart without adding a new column.
//
// GET: customer_id, order_date (Y-m-d)
// Response contract: { ok, message?, data: { has_existing, orders[] } }
//   orders[] (newest first): { group_key, created_at, driver_name,
//     police_number, items[]: { logistic_id, movement_id, activity_name,
//     unit_label, qty, price, total_price } }

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
        error_log('check_existing_orders.php stray output: ' . $noise);
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
$orderDate  = trim((string) ($_GET['order_date'] ?? ''));

if ($customerId <= 0) {
    aos_fail('Customer is missing.');
}

$dateObj = DateTime::createFromFormat('Y-m-d', $orderDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $orderDate) {
    aos_fail('Order date is not valid.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $st = $lisani_conn->prepare(
        "SELECT m.id AS movement_id, m.logistic_id, m.movement_date, m.created_at,
                m.driver_name, m.police_number, m.qty_primary_package, m.price, m.total_price,
                a.activity_name, l.primary_unit_label AS unit_label
         FROM logistic_movements m
         JOIN logistics l ON l.id = m.logistic_id
         JOIN activities a ON a.id = l.activity_id
         WHERE m.customer_id = ? AND m.movement_type = 'out' AND m.movement_date = ?
         ORDER BY m.created_at DESC, m.id DESC"
    );
    $st->bind_param('is', $customerId, $orderDate);
    $st->execute();
    $res = $st->get_result();

    // Group rows into orders: same rounded-to-the-minute created_at + same
    // driver/police_number => one order (one Confirm & Save call).
    $groups = []; // group_key => order
    $order  = [];  // keeps first-seen order for group_key, in DESC created_at order
    while ($row = $res->fetch_assoc()) {
        $minute = substr((string) $row['created_at'], 0, 16); // "Y-m-d H:i"
        $groupKey = $minute . '|' . (string) $row['driver_name'] . '|' . (string) $row['police_number'];

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'group_key'     => $groupKey,
                'created_at'    => $row['created_at'],
                'driver_name'   => $row['driver_name'],
                'police_number' => $row['police_number'],
                'items'         => [],
            ];
            $order[] = $groupKey;
        }
        $groups[$groupKey]['items'][] = [
            'logistic_id'   => (int) $row['logistic_id'],
            'movement_id'   => (int) $row['movement_id'],
            'activity_name' => $row['activity_name'],
            'unit_label'    => $row['unit_label'],
            'qty'           => $row['qty_primary_package'],
            'price'         => $row['price'],
            'total_price'   => $row['total_price'],
        ];
    }
    $st->close();

    $orders = [];
    foreach ($order as $groupKey) {
        $orders[] = $groups[$groupKey];
    }

    aos_json([
        'ok'   => true,
        'data' => [
            'has_existing' => count($orders) > 0,
            'orders'       => $orders,
        ],
    ]);
} catch (Throwable $e) {
    error_log('check_existing_orders.php: ' . $e->getMessage());
    aos_fail('Failed to check existing orders.');
}
