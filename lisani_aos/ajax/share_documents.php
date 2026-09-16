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

$ids = array_map('intval', $ids);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$query = "SELECT id, document_name, document_date FROM company_documents WHERE id IN ($placeholders) ORDER BY document_date DESC";
$docStmt = $lisani_conn->prepare($query);
$docStmt->bind_param($types, ...$ids);
$docStmt->execute();
$result = $docStmt->get_result();

$documents = [];
while ($row = $result->fetch_assoc()) {
    $documents[] = [
        'id' => (int) $row['id'],
        'document_name' => $row['document_name'],
        'document_date' => $row['document_date'],
        'download_url' => 'ajax/download_document.php?id=' . $row['id'],
    ];
}

echo json_encode(['success' => true, 'documents' => $documents]);
