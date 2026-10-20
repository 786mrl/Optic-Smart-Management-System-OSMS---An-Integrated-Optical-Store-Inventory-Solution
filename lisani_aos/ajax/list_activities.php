<?php
// lisani_aos/ajax/list_activities.php
// Returns the known "judul kegiatan" list from json_file/document_activities.json
// so upload forms across Document Tools can offer "pilih yang cocok atau
// buat baru" (an <input list="..."> datalist on the client). The JSON file
// itself is written by ajax/upload_signature.php (and, later, by whatever
// else in Document Tools saves files under storage/company_activity/...).
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$jsonPath = dirname(__DIR__) . '/json_file/document_activities.json';

$activities = [];
if (is_file($jsonPath)) {
    $raw = file_get_contents($jsonPath);
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['activities']) && is_array($decoded['activities'])) {
        $activities = $decoded['activities'];
    }
}

echo json_encode(['success' => true, 'activities' => $activities]);