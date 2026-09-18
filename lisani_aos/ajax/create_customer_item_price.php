<?php
// lisani_aos/ajax/create_customer_item_price.php
// Inserts one itemized price entry for a customer + registered product.
// No password reverify here — same as Create New Logistic, only Edit/
// Delete of an existing entry are reverify-gated (see
// update_customer_item_price.php / delete_customer_item_price.php).

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$customerId = (int) ($_POST['customer_id'] ?? 0);
$logisticId = (int) ($_POST['logistic_id'] ?? 0);
$price      = $_POST['price'] ?? '';
$priceDate  = trim($_POST['price_date'] ?? '');

if ($customerId <= 0 || $logisticId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Customer dan produk wajib dipilih.']);
    exit;
}
if ($price === '' || !is_numeric($price) || (float) $price < 0) {
    echo json_encode(['ok' => false, 'message' => 'Price tidak valid.']);
    exit;
}
if ($priceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $priceDate)) {
    echo json_encode(['ok' => false, 'message' => 'Date wajib diisi.']);
    exit;
}

// Snapshot the product's current primary unit label at the moment this
// price is saved — see customer_item_prices design notes (unit can change
// later via Manage Units, this row must keep showing what was true then).
$stmt = $lisani_conn->prepare('SELECT primary_unit_label FROM logistics WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $logisticId);
$stmt->execute();
$logisticRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$logisticRow) {
    echo json_encode(['ok' => false, 'message' => 'Produk tidak ditemukan.']);
    exit;
}
$unitLabel = $logisticRow['primary_unit_label'] ?? '';

$stmt = $lisani_conn->prepare(
    'INSERT INTO customer_item_prices (customer_id, logistic_id, price, price_date, unit_label)
     VALUES (?, ?, ?, ?, ?)'
);
$stmt->bind_param('iidss', $customerId, $logisticId, $price, $priceDate, $unitLabel);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan price.']);
    exit;
}

echo json_encode(['ok' => true, 'id' => $stmt->insert_id]);
$stmt->close();
