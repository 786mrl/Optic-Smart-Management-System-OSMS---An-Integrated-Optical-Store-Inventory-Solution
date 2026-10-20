<?php
// lisani_aos/ajax/share_signatures.php
// Share via WhatsApp: tidak mengirim file lewat server, hanya memverifikasi
// password lalu mengembalikan metadata + download_url tiap signature yang
// dipilih. Client sendiri yang men-download tiap file dan membuka WA dengan
// teks daftar nama (lihat docShareBtn di settings_content.php untuk pola
// yang sama persis pada Company Documents).
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

$ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
$ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No signatures selected.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$stmt = $lisani_conn->prepare(
    "SELECT id, signature_name, signature_date FROM signature_library WHERE id IN ($placeholders)"
);
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$result = $stmt->get_result();

$documents = [];
while ($row = $result->fetch_assoc()) {
    $documents[] = [
        'id' => (int) $row['id'],
        'document_name' => $row['signature_name'],
        'document_date' => $row['signature_date'],
        'download_url' => 'ajax/download_signature.php?id=' . (int) $row['id'],
    ];
}

echo json_encode(['success' => true, 'documents' => $documents]);
