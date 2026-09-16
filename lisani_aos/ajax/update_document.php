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

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$documentName = trim($_POST['document_name'] ?? '');
$documentDate = $_POST['document_date'] ?? '';

if ($id <= 0 || $documentName === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $documentDate);
if (!$dateObj) {
    echo json_encode(['success' => false, 'message' => 'Invalid document date.']);
    exit;
}

$documentName = preg_replace('/[^A-Za-z0-9 _\-]/', '', $documentName);

$update = $lisani_conn->prepare(
    'UPDATE company_documents SET document_name = ?, document_date = ? WHERE id = ?'
);
$update->bind_param('ssi', $documentName, $documentDate, $id);

if (!$update->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to update document.']);
    exit;
}

echo json_encode(['success' => true]);
