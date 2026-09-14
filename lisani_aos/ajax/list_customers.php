<?php
// lisani_aos/ajax/list_customers.php
// Returns all customers, newest first, for the Customer List preview tab.

session_start();

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // provides $lisani_conn (mysqli)

header('Content-Type: application/json');

$result = $lisani_conn->query(
    'SELECT id, year, customer_name, phone_number, total_inflow, total_outflow, profit, created_at
     FROM customers
     ORDER BY created_at DESC'
);

if (!$result) {
    echo json_encode(['ok' => false, 'message' => 'Failed to load customers.']);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'id'            => (int) $row['id'],
        'year'          => $row['year'],
        'customer_name' => $row['customer_name'],
        'phone_number'  => $row['phone_number'],
        'total_inflow'  => $row['total_inflow'],
        'total_outflow' => $row['total_outflow'],
        'profit'        => $row['profit'],
        'created_at'    => $row['created_at']
    ];
}

echo json_encode(['ok' => true, 'data' => $data]);
