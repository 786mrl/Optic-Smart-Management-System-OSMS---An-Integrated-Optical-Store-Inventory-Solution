<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';

$rows = [];
$result = $lisani_conn->query(
    "SELECT id, document_name, document_date, original_filename, file_ext, file_size, created_at
     FROM company_documents
     ORDER BY document_date DESC, id DESC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

echo json_encode(['success' => true, 'documents' => $rows]);
