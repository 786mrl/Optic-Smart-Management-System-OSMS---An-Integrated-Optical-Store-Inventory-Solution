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

$jsonPath = dirname(__DIR__) . '/json_file/bank_accounts.json';

$id = trim($_POST['id'] ?? '');

if ($id === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$fp = fopen($jsonPath, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    echo json_encode(['success' => false, 'message' => 'Could not access data file.']);
    exit;
}

$raw = stream_get_contents($fp);
$accounts = json_decode($raw, true);
if (!is_array($accounts)) {
    $accounts = [];
}

$before = count($accounts);
$accounts = array_values(array_filter($accounts, function ($acc) use ($id) {
    return (string) ($acc['id'] ?? '') !== $id;
}));

if (count($accounts) === $before) {
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['success' => true]);
