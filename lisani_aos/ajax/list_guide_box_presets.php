<?php
// lisani_aos/ajax/list_guide_box_presets.php
// Document Tools > Document Editor - ukuran "Guide Box" (kotak bantuan jarak
// ttd, rangkuman_tool_ttd_dokumen.md bagian 6). Disimpan dalam INCI di
// json_file/guide_box_presets.json; editor mengonversi inci -> px working
// canvas pakai DOC_DPI di JS, yang HARUS sama dengan $ASSUMED_DPI di
// save_edited_document.php (keduanya 150) - kalau salah satu diubah,
// ubah juga yang satunya.
//
// File json ini BOLEH diedit langsung di server (tambah/ubah/hapus baris
// "presets") tanpa perlu ubah kode - dropdown di Document Editor otomatis
// mengikuti isinya. Pola sama dengan json_file/document_activities.json
// (self-seed kalau file belum ada, lihat save_edited_document.php).
//
// Standar yang diminta user (18 Okt 2026): 1.46" x 2.18" dan 1.46" x 3.05".
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$jsonPath = dirname(__DIR__) . '/json_file/guide_box_presets.json';

$defaults = [
    'presets' => [
        ['id' => 'standard-1', 'label' => '1.46" x 2.18"', 'width_in' => 1.46, 'height_in' => 2.18],
        ['id' => 'standard-2', 'label' => '1.46" x 3.05"', 'width_in' => 1.46, 'height_in' => 3.05],
    ],
];

if (!is_file($jsonPath)) {
    $dir = dirname($jsonPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($jsonPath, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$decoded = is_file($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : null;
$presets = (is_array($decoded) && isset($decoded['presets']) && is_array($decoded['presets']) && count($decoded['presets']) > 0)
    ? $decoded['presets']
    : $defaults['presets'];

echo json_encode(['success' => true, 'presets' => $presets]);
