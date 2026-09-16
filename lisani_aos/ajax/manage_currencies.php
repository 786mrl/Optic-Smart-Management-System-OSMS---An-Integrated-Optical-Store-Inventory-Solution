<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$jsonPath = dirname(__DIR__) . '/json_file/currencies.json';
$action = $_REQUEST['action'] ?? 'list';

if ($action === 'list') {
    $currencies = [];
    if (is_file($jsonPath)) {
        $decoded = json_decode(file_get_contents($jsonPath), true);
        if (is_array($decoded)) {
            $currencies = $decoded;
        }
    }
    echo json_encode(['success' => true, 'currencies' => $currencies]);
    exit;
}

if ($action === 'add') {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $code = preg_replace('/[^A-Z]/', '', $code);

    if ($code === '' || strlen($code) > 6) {
        echo json_encode(['success' => false, 'message' => 'Invalid currency code.']);
        exit;
    }

    $fp = fopen($jsonPath, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        echo json_encode(['success' => false, 'message' => 'Could not access data file.']);
        exit;
    }

    $raw = stream_get_contents($fp);
    $currencies = json_decode($raw, true);
    if (!is_array($currencies)) {
        $currencies = [];
    }

    if (!in_array($code, $currencies, true)) {
        $currencies[] = $code;
        sort($currencies);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($currencies, JSON_PRETTY_PRINT));
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode(['success' => true, 'currencies' => $currencies]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
