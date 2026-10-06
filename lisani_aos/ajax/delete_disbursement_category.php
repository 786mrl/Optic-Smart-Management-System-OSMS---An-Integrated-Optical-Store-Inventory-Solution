<?php
// lisani_aos/ajax/delete_disbursement_category.php
// Deletes a disbursement category (no password, per user decision).
// Refused when any transaction already uses it (same idea as manage_departments).
// POST: id
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
        error_log('delete_disbursement_category.php stray output: ' . $noise);
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

    $stmt = $lisani_conn->prepare('SELECT COUNT(*) AS c FROM transaction_disbursements WHERE category_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $used = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    if ($used > 0) {
        $lisani_conn->rollback();
        aos_fail('This category is already used by ' . $used . ' transaction(s) and cannot be deleted. You can rename it instead.');
    }

    $del = $lisani_conn->prepare('DELETE FROM disbursement_categories WHERE id = ?');
    $del->bind_param('i', $id);
    $del->execute();
    $del->close();

    $lisani_conn->commit();
} catch (Throwable $e) {
    $lisani_conn->rollback();
    error_log('delete_disbursement_category.php: ' . $e->getMessage());
    aos_fail('Failed to delete the category.');
}

aos_json(['ok' => true, 'message' => 'Category deleted.']);
