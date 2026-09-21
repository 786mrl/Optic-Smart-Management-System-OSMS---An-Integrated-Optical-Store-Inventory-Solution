<?php
// lisani_aos/ajax/save_order_alias.php
// Saves one new product wording (alias) into
// json_file/order_patterns/{activity_id}.json after the user answered
// "what does this line mean?" in Sales Transaction.
//
// POST: activity_id, alias_text   (alias_text = product wording WITHOUT the quantity)
// Response contract: { ok, message, alias }
//
// No password re-verification: this only ADDS a wording, it never edits or
// deletes anything.

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
        error_log('save_order_alias.php stray output: ' . $noise);
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
$activityId = isset($_POST['activity_id']) ? (int) $_POST['activity_id'] : 0;
$aliasText  = isset($_POST['alias_text']) ? trim((string) $_POST['alias_text']) : '';

if ($activityId <= 0) {
    aos_fail('Product is missing.');
}
if ($aliasText === '') {
    aos_fail('Product wording is empty.');
}

// ---------- Save ----------
try {
    $products = aos_load_products($lisani_conn);
    $result   = aos_save_alias($activityId, $aliasText, $products);
} catch (Throwable $e) {
    error_log('save_order_alias.php: ' . $e->getMessage());
    aos_fail('Failed to save the wording.');
}

aos_json($result);
