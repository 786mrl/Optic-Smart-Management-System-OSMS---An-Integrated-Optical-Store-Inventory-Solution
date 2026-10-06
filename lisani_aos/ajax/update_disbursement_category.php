<?php
// lisani_aos/ajax/update_disbursement_category.php
// Renames a disbursement category (no password, per user decision).
// POST: id, category_name
// Response: { ok: true|false, message: "..." }

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('update_disbursement_category.php stray output: ' . $noise);
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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    aos_fail('Category is missing.');
}

// Free text is always stored uppercase, with repeated spaces collapsed.
$name = isset($_POST['category_name']) ? trim((string)$_POST['category_name']) : '';
$name = mb_strtoupper(preg_replace('/\s+/u', ' ', $name));
if ($name === '') {
    aos_fail('Category name is required.');
}
if (mb_strlen($name) > 100) {
    aos_fail('Category name is too long (max 100 characters).');
}

try {
    $lisani_conn->begin_transaction();

    $stmt = $lisani_conn->prepare('SELECT id FROM disbursement_categories WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$found) {
        $lisani_conn->rollback();
        aos_fail('Category was not found.');
    }

    // Another category with the same name? (collation is case-insensitive)
    $stmt = $lisani_conn->prepare('SELECT id FROM disbursement_categories WHERE category_name = ? AND id <> ?');
    $stmt->bind_param('si', $name, $id);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($dup) {
        $lisani_conn->rollback();
        aos_fail('Another category already has this name.');
    }

    $upd = $lisani_conn->prepare('UPDATE disbursement_categories SET category_name = ? WHERE id = ?');
    $upd->bind_param('si', $name, $id);
    $upd->execute();
    $upd->close();

    $lisani_conn->commit();
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('update_disbursement_category.php: ' . $e->getMessage());
    aos_fail('Failed to rename the category.');
}

aos_json(['ok' => true, 'message' => 'Category renamed.']);
