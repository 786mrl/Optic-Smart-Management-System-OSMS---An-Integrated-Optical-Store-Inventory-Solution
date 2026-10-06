<?php
// lisani_aos/ajax/save_disbursement_category.php
// Adds a new disbursement category. If the name already exists
// (case-insensitive), the existing row is returned instead of an error,
// so the client can simply select it.
// POST: category_name
// Response: { ok, data: {id, category_name, existed} }

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('save_disbursement_category.php stray output: ' . $noise);
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

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    aos_fail('Session expired. Please log in again.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aos_fail('Invalid request.');
}

require_once dirname(__DIR__) . '/db_config.php'; // $lisani_conn (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Free text is always stored uppercase, with repeated spaces collapsed.
$name = isset($_POST['category_name']) ? trim((string)$_POST['category_name']) : '';
$name = mb_strtoupper(preg_replace('/\s+/u', ' ', $name));

if ($name === '') {
    aos_fail('Category name is required.');
}
if (mb_strlen($name) > 100) {
    aos_fail('Category name is too long (max 100 characters).');
}

$userId = (int)$_SESSION['user_id'];

try {
    // Existing? (column collation is case-insensitive, name is already uppercase)
    $stmt = $lisani_conn->prepare('SELECT id, category_name FROM disbursement_categories WHERE category_name = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        aos_json(['ok' => true, 'data' => [
            'id' => (int)$existing['id'], 'category_name' => $existing['category_name'], 'existed' => true,
        ]]);
    }

    $ins = $lisani_conn->prepare('INSERT INTO disbursement_categories (category_name, created_by) VALUES (?, ?)');
    $ins->bind_param('si', $name, $userId);
    $ins->execute();
    $newId = $lisani_conn->insert_id;
    $ins->close();

    aos_json(['ok' => true, 'data' => ['id' => (int)$newId, 'category_name' => $name, 'existed' => false]]);
} catch (Throwable $e) {
    error_log('save_disbursement_category.php: ' . $e->getMessage());
    aos_fail('Failed to save the category.');
}
