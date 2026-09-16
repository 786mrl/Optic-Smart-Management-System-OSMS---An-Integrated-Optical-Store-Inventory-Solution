<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/_require_reverify.php';
aos_require_recent_reverify();

$ids = $_POST['ids'] ?? [];

if (!is_array($ids) || count($ids) === 0) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$jsonPath = dirname(__DIR__) . '/json_file/bank_accounts.json';
$allAccounts = [];
if (is_file($jsonPath)) {
    $decoded = json_decode(file_get_contents($jsonPath), true);
    if (is_array($decoded)) {
        $allAccounts = $decoded;
    }
}

$idSet = array_flip($ids);
$selected = array_values(array_filter($allAccounts, function ($acc) use ($idSet) {
    return isset($idSet[$acc['id'] ?? '']);
}));

echo json_encode(['success' => true, 'accounts' => $selected]);
