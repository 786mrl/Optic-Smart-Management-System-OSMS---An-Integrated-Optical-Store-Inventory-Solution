<?php
// lisani_aos/ajax/list_customer_item_prices.php
// Returns the itemized price history for one customer, newest price_date
// first, joined to activities so the UI has a product name to show
// (customer_item_prices only stores logistic_id + a unit_label snapshot).

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'customer_id tidak valid.']);
    exit;
}

$stmt = $lisani_conn->prepare(
    'SELECT cip.id, cip.customer_id, cip.logistic_id, cip.price, cip.price_date,
            cip.unit_label, cip.created_at, cip.updated_at, a.activity_name
     FROM customer_item_prices cip
     JOIN logistics l ON l.id = cip.logistic_id
     JOIN activities a ON a.id = l.activity_id
     WHERE cip.customer_id = ?
     ORDER BY cip.price_date DESC, cip.created_at DESC'
);
$stmt->bind_param('i', $customerId);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'id'            => (int) $row['id'],
        'customer_id'   => (int) $row['customer_id'],
        'logistic_id'   => (int) $row['logistic_id'],
        'activity_name' => $row['activity_name'],
        'price'         => $row['price'],
        'price_date'    => $row['price_date'],
        'unit_label'    => $row['unit_label'],
        'created_at'    => $row['created_at'],
        'updated_at'    => $row['updated_at'],
    ];
}
$stmt->close();

echo json_encode(['ok' => true, 'data' => $data]);
