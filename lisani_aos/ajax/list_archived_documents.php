<?php
// lisani_aos/ajax/list_archived_documents.php
// Document Tools > Document Archive. Same list pattern as
// list_letterheads.php - newest first, no pagination (v1, see
// PROJECT_NOTES.md §8 open items for Letterhead's own "belum ada
// filter/pagination" note, which applies here too).
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';

$result = $lisani_conn->query(
    "SELECT id, document_name, document_date, activity_title, original_filename, stored_filename, file_path, file_size, created_at
     FROM archived_documents
     ORDER BY id DESC"
);

$documents = [];
while ($row = $result->fetch_assoc()) {
    $documents[] = [
        'id' => (int) $row['id'],
        'document_name' => $row['document_name'],
        'document_date' => $row['document_date'],
        'activity_title' => $row['activity_title'],
        'original_filename' => $row['original_filename'],
        'stored_filename' => $row['stored_filename'],
        'file_path' => $row['file_path'],
        'file_size' => (int) $row['file_size'],
        'created_at' => $row['created_at'],
    ];
}

echo json_encode(['success' => true, 'documents' => $documents]);
