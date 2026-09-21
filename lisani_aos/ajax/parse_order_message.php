<?php
// lisani_aos/ajax/parse_order_message.php
// Parses a WhatsApp order message pasted in Sales Transaction (Tab 1):
// driver name, police number, and product lines with carton quantities.
// Read-only: nothing is saved here. Unknown product wordings are returned as
// status "unknown_product"; the UI asks the user and then calls
// ajax/save_order_alias.php.
//
// POST: message
// Response contract: { ok, message, ... }
//   ok: true  -> driver_name, police_number, items[], ignored[], products[]
//   items[]: { line, qty, product_text, status, activity_id,
//              logistic_id, product_name, unit_label }
//     status: matched | unknown_product | missing_qty | missing_product
//   ignored[]: { line, reason }
//     reason: unrecognized_text | no_quantity | duplicate_driver |
//             duplicate_police_number | empty
//   products[]: every orderable product (for the "which product?" picker):
//     { logistic_id, activity_id, activity_name, unit_label, remaining_qty }

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
        error_log('parse_order_message.php stray output: ' . $noise);
    }
    header('Content-Type: application/json; charset=utf-8');
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

// ---------- Guard ----------
if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    aos_fail('Session expired. Please log in again.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aos_fail('Invalid request.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)
require_once __DIR__ . '/_order_patterns.php';

// ---------- Input ----------
$message = isset($_POST['message']) ? trim((string) $_POST['message']) : '';
if ($message === '') {
    aos_fail('The order message is empty.');
}
if (mb_strlen($message) > 5000) {
    aos_fail('The order message is too long (max 5000 characters).');
}

// ---------- Parse ----------
try {
    $products = aos_load_products($lisani_conn);
    $aliasMap = aos_load_alias_map(array_keys($products));
    $parsed   = aos_parse_order_message($message, $aliasMap);
} catch (Throwable $e) {
    error_log('parse_order_message.php: ' . $e->getMessage());
    aos_fail('Failed to read the order message.');
}

// Attach product info to matched lines.
foreach ($parsed['items'] as &$item) {
    $product = $item['activity_id'] !== null ? ($products[$item['activity_id']] ?? null) : null;
    $item['logistic_id']  = $product ? $product['logistic_id'] : null;
    $item['product_name'] = $product ? $product['activity_name'] : null;
    $item['unit_label']   = $product ? $product['unit_label'] : null;
}
unset($item);

$productList = array_values($products);
usort($productList, function ($a, $b) {
    return strcmp((string) $a['activity_name'], (string) $b['activity_name']);
});

aos_json([
    'ok'            => true,
    'message'       => 'Order message parsed.',
    'driver_name'   => $parsed['driver_name'],
    'police_number' => $parsed['police_number'],
    'items'         => $parsed['items'],
    'ignored'       => $parsed['ignored'],
    'products'      => $productList,
]);
