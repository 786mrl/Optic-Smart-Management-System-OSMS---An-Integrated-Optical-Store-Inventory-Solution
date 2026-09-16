<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$jsonPath = dirname(__DIR__) . '/json_file/bank_accounts.json';
$accounts = [];

if (is_file($jsonPath)) {
    $raw = file_get_contents($jsonPath);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $accounts = $decoded;
    }
}

// Group by currency for display.
$grouped = [];
foreach ($accounts as $acc) {
    $currency = $acc['currency'] ?? 'IDR';
    if (!isset($grouped[$currency])) {
        $grouped[$currency] = [];
    }
    $grouped[$currency][] = $acc;
}
ksort($grouped);

echo json_encode(['success' => true, 'accounts' => $accounts, 'grouped' => $grouped]);
