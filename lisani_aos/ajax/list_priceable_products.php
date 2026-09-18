<?php
// lisani_aos/ajax/list_priceable_products.php
// Returns every registered product (a `logistics` row joined to its
// `activities` row) for the Itemized Pricing product picker. Unlike
// list_logistic_activities.php, this is NOT scoped to one department —
// Itemized Pricing can price any product that has a logistics record,
// regardless of which department it came from.

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$result = $lisani_conn->query(
    'SELECT l.id AS logistic_id, a.activity_name, l.primary_unit_label
     FROM logistics l
     JOIN activities a ON a.id = l.activity_id
     ORDER BY a.activity_name ASC'
);

if (!$result) {
    echo json_encode(['ok' => false, 'message' => 'Failed to load products.']);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'logistic_id'        => (int) $row['logistic_id'],
        'activity_name'      => $row['activity_name'],
        'primary_unit_label' => $row['primary_unit_label'],
    ];
}

echo json_encode(['ok' => true, 'data' => $data]);
