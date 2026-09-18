<?php
// lisani_aos/ajax/delete_customer_item_price.php
// Deletes one itemized price entry. No physical files/folders involved
// (unlike delete_logistic.php / delete_customer.php), so this is a plain
// DB delete — but still gated by the same reverify pattern as
// update_customer_item_price.php, since Edit and Delete on this list share
// one reverify prompt in the UI (see openItemPriceReverify()).

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

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'ID tidak valid.']);
    exit;
}

$stmt = $lisani_conn->prepare('DELETE FROM customer_item_prices WHERE id = ?');
$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menghapus entry.']);
    exit;
}
if ($stmt->affected_rows === 0) {
    echo json_encode(['ok' => false, 'message' => 'Entry tidak ditemukan.']);
    exit;
}

echo json_encode(['ok' => true]);
$stmt->close();
