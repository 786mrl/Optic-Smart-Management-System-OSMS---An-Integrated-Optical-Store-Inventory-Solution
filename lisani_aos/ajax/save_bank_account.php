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

$accountNumber = mb_strtoupper(trim($_POST['account_number'] ?? ''));
$accountName = mb_strtoupper(trim($_POST['account_name'] ?? ''));
$bankName = mb_strtoupper(trim($_POST['bank_name'] ?? ''));
$currency = trim($_POST['currency'] ?? '');
$swiftCode = mb_strtoupper(trim($_POST['swift_code'] ?? ''));
$address = mb_strtoupper(trim($_POST['address'] ?? ''));

if ($bankName === '' || $accountNumber === '' || $accountName === '' || $currency === '') {
    echo json_encode(['success' => false, 'message' => 'Bank name, account number, account name, and currency are required.']);
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

// Prevent saving an account number that already exists FOR THE SAME BANK
// (two different banks can legitimately share the same account number).
// Account number is compared with spaces/formatting stripped, so
// "1234 5678" and "12345678" are treated as the same number. Bank name is
// already uppercased above. Skip the record being edited when updating.
$normalizedNumber = preg_replace('/[^A-Z0-9]/', '', $accountNumber);
foreach ($accounts as $existing) {
    if ($isUpdate && (string) ($existing['id'] ?? '') === $id) {
        continue;
    }
    $existingBankName = mb_strtoupper(trim($existing['bank_name'] ?? ''));
    $existingNormalized = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($existing['account_number'] ?? ''));
    if (
        $normalizedNumber !== ''
        && $existingNormalized === $normalizedNumber
        && $existingBankName === $bankName
    ) {
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode(['success' => false, 'message' => 'This account number already exists for this bank.']);
        exit;
    }
}

if ($isUpdate) {
    $found = false;
    foreach ($accounts as &$acc) {
        if ((string) ($acc['id'] ?? '') === $id) {
            $acc['bank_name'] = $bankName;
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
        'bank_name' => $bankName,
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