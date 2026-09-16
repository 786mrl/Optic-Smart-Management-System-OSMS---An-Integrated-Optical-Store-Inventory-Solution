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

$jsonPath = dirname(__DIR__) . '/json_file/bank_accounts.json';

$id = trim($_POST['id'] ?? '');
$isUpdate = $id !== '';

$accountNumber = trim($_POST['account_number'] ?? '');
$accountName = trim($_POST['account_name'] ?? '');
$currency = trim($_POST['currency'] ?? '');
$swiftCode = trim($_POST['swift_code'] ?? '');
$address = trim($_POST['address'] ?? '');

if ($accountNumber === '' || $accountName === '' || $currency === '') {
    echo json_encode(['success' => false, 'message' => 'Account number, name, and currency are required.']);
    exit;
}

if ($currency !== 'IDR' && ($swiftCode === '' || $address === '')) {
    echo json_encode(['success' => false, 'message' => 'SWIFT code and address are required for non-IDR currency.']);
    exit;
}

// Editing an existing account requires the user to have just re-verified
// their password via ajax/verify_password.php (create/new account does not).
if ($isUpdate) {
    aos_require_recent_reverify();
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

if ($isUpdate) {
    $found = false;
    foreach ($accounts as &$acc) {
        if ((string) ($acc['id'] ?? '') === $id) {
            $acc['account_number'] = $accountNumber;
            $acc['account_name'] = $accountName;
            $acc['currency'] = $currency;
            $acc['swift_code'] = $currency !== 'IDR' ? $swiftCode : '';
            $acc['address'] = $currency !== 'IDR' ? $address : '';
            $acc['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    unset($acc);

    if (!$found) {
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }
} else {
    $newId = uniqid('bank_', true);
    $accounts[] = [
        'id' => $newId,
        'account_number' => $accountNumber,
        'account_name' => $accountName,
        'currency' => $currency,
        'swift_code' => $currency !== 'IDR' ? $swiftCode : '',
        'address' => $currency !== 'IDR' ? $address : '',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['success' => true]);
