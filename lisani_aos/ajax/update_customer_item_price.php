<?php
// lisani_aos/ajax/update_customer_item_price.php
// Edits an existing itemized price entry — price and price_date only.
// The product (logistic_id) and its unit_label snapshot are NOT editable
// here: this row represents a specific priced entry for a specific
// product, changing the product would mean a different entry entirely.
//
// Gated by aos_require_recent_reverify() (verify_password.php +
// _require_reverify.php), same pattern as update_logistic.php — NOT the
// password_verify()-in-endpoint pattern used by delete_customer.php.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn
require_once __DIR__ . '/_require_reverify.php';
aos_require_recent_reverify(); // echoes {success:false,...} + exit if expired

$id        = (int) ($_POST['id'] ?? 0);
$price     = $_POST['price'] ?? '';
$priceDate = trim($_POST['price_date'] ?? '');

if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'ID tidak valid.']);
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

$stmt = $lisani_conn->prepare(
    'UPDATE customer_item_prices SET price = ?, price_date = ? WHERE id = ?'
);
$stmt->bind_param('dsi', $price, $priceDate, $id);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan perubahan.']);
    exit;
}
if ($stmt->affected_rows === 0) {
    echo json_encode(['ok' => false, 'message' => 'Entry tidak ditemukan.']);
    exit;
}

echo json_encode(['ok' => true]);
$stmt->close();
