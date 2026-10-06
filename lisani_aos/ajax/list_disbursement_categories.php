<?php
// lisani_aos/ajax/list_disbursement_categories.php
// Returns all disbursement categories (alphabetical).
// Response: { ok, data: [ {id, category_name}, ... ] }

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('list_disbursement_categories.php stray output: ' . $noise);
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

require_once dirname(__DIR__) . '/db_config.php'; // $lisani_conn (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $rows = [];
    $res = $lisani_conn->query('SELECT id, category_name FROM disbursement_categories ORDER BY category_name ASC');
    while ($row = $res->fetch_assoc()) {
        $rows[] = ['id' => (int)$row['id'], 'category_name' => $row['category_name']];
    }
    aos_json(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
    error_log('list_disbursement_categories.php: ' . $e->getMessage());
    aos_fail('Failed to load categories. Has migration_disbursement_categories.sql been run?');
}
