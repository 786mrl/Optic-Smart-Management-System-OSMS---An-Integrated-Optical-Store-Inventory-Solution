<?php
// lisani_aos/ajax/list_logistic_documents.php
// Returns import documents already uploaded for one activity code, so the
// Create New Logistic form can show what's saved so far under the upload
// form (and so upload_logistic_document.php can check name-uniqueness
// against the real, current list).
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$activityId = (int) ($_GET['activity_id'] ?? 0);
if ($activityId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Activity code tidak valid.']);
    exit;
}

$stmt = $lisani_conn->prepare(
    'SELECT id, document_type, document_name, document_date, file_path, uploaded_at
     FROM logistic_documents
     WHERE activity_id = ?
     ORDER BY uploaded_at DESC, id DESC'
);
$stmt->bind_param('i', $activityId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = [
        'id'            => (int) $r['id'],
        'document_type' => $r['document_type'],
        'document_name' => $r['document_name'],
        'document_date' => $r['document_date'],
        'file_path'     => $r['file_path'],
    ];
}
$stmt->close();

echo json_encode(['ok' => true, 'data' => $rows]);
