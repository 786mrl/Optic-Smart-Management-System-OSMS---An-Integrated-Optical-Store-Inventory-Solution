<?php
// lisani_aos/ajax/delete_logistic.php
// Deletes a logistics row and ALL its import documents. Deleting a logistic
// does NOT delete the activity code itself — only the logistics row and
// logistic_documents rows tied to it, plus their physical files.
//
// Physical import_documents/ folder for this activity code is moved
// (not deleted) to:
//   AOS_STORAGE_BASE/recycle/input/[year]/[dept]/[code]/import_documents/
// — same "keep the input/ segment" convention as delete_activity_code.php's
// recycle handling, so the recycle structure still shows which section a
// folder came from. A notes.txt is written alongside the moved folder
// summarizing the logistics row that was deleted (qty, units, activity
// code, who deleted it and when) — there is no DB audit trail for this,
// the file IS the record.
//
// Gated by the shared re-verify guard, same as update_logistic.php.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn
require_once __DIR__ . '/_require_reverify.php';
aos_require_recent_reverify(); // exits with ['success' => false, ...] on failure — NOT ['ok' => false]

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

$logisticId = (int) ($_POST['id'] ?? 0);
if ($logisticId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Logistic tidak valid.']);
    exit;
}

// Pull the logistics row together with its activity's relative_path (needed
// to locate the import_documents/ folder) and activity_name/code (needed
// for the notes.txt summary).
$stmt = $lisani_conn->prepare(
    'SELECT l.*, a.activity_name, a.relative_path
     FROM logistics l
     JOIN activities a ON a.id = l.activity_id
     WHERE l.id = ? LIMIT 1'
);
$stmt->bind_param('i', $logisticId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Logistic tidak ditemukan.']);
    exit;
}

$activityId   = (int) $row['activity_id'];
$relativePath = $row['relative_path'];

// Snapshot the documents list BEFORE deleting rows — needed for the
// notes.txt summary regardless of whether the physical move succeeds.
$docStmt = $lisani_conn->prepare(
    'SELECT document_type, document_name, document_date, file_path FROM logistic_documents WHERE activity_id = ? ORDER BY document_type, id'
);
$docStmt->bind_param('i', $activityId);
$docStmt->execute();
$docResult = $docStmt->get_result();
$documents = [];
while ($d = $docResult->fetch_assoc()) {
    $documents[] = $d;
}
$docStmt->close();

// --- DB deletes first: logistic_documents rows, then the logistics row.
// If either fails, bail before touching the filesystem.
$lisani_conn->begin_transaction();
try {
    $delDocsStmt = $lisani_conn->prepare('DELETE FROM logistic_documents WHERE activity_id = ?');
    $delDocsStmt->bind_param('i', $activityId);
    $delDocsStmt->execute();
    $delDocsStmt->close();

    $delLogisticStmt = $lisani_conn->prepare('DELETE FROM logistics WHERE id = ?');
    $delLogisticStmt->bind_param('i', $logisticId);
    $delLogisticStmt->execute();
    $delLogisticStmt->close();

    $lisani_conn->commit();
} catch (\Throwable $e) {
    $lisani_conn->rollback();
    echo json_encode(['ok' => false, 'message' => 'Gagal menghapus data logistic dari database. Coba lagi.']);
    exit;
}

// --- Physical folder: import_documents/ under this activity code.
// Only acted on if it actually exists — a logistic with zero uploaded
// documents never created the folder in the first place.
$folderRelative = rtrim($relativePath, '/') . '/import_documents';
$folderFull     = rtrim(AOS_STORAGE_BASE, '/') . '/' . $folderRelative;

$folderAction = 'none';

if (is_dir($folderFull)) {
    $recycleFull = rtrim(AOS_STORAGE_BASE, '/') . '/recycle/' . $folderRelative;
    $recycleParent = dirname($recycleFull);

    if (!is_dir($recycleParent)) {
        @mkdir($recycleParent, 0755, true);
    }

    $destFull = $recycleFull;
    if (is_dir($destFull)) {
        // Same activity code had a logistic deleted before — avoid clobbering
        // the previous recycle folder.
        $destFull = $recycleFull . '-' . date('YmdHis');
    }

    if (@rename($folderFull, $destFull)) {
        $folderAction = 'moved_to_recycle';

        // notes.txt: plain-text summary of the deleted logistics row,
        // written INSIDE the moved folder so it travels with the documents.
        // This is the only record of the deletion — no DB audit trail.
        $notesLines = [];
        $notesLines[] = 'Logistic deleted';
        $notesLines[] = '==================';
        $notesLines[] = 'Deleted at   : ' . date('Y-m-d H:i:s');
        $notesLines[] = 'Deleted by   : user_id ' . $_SESSION['user_id'];
        $notesLines[] = '';
        $notesLines[] = 'Activity code : ' . $relativePath;
        $notesLines[] = 'Activity name : ' . $row['activity_name'];
        $notesLines[] = 'Logistic ID   : ' . $logisticId;
        $notesLines[] = 'Incoming date : ' . ($row['incoming_date'] ?? '-');
        $notesLines[] = '';
        $notesLines[] = 'Primary qty          : ' . ($row['primary_qty'] ?? '-') . ' ' . ($row['primary_unit_label'] ?? '');
        $notesLines[] = 'Primary unit weight   : ' . ($row['primary_unit_weight_kg'] ?? '-') . ' KG';
        $notesLines[] = 'Remaining primary qty : ' . ($row['remaining_primary_qty'] ?? '-');
        $notesLines[] = 'Secondary unit        : ' . ($row['secondary_unit_label'] ?? '-');
        $notesLines[] = 'Secondary unit weight : ' . ($row['secondary_unit_weight_kg'] ?? '-') . ' KG';
        $notesLines[] = 'Secondary ratio       : ' . ($row['secondary_ratio_per_primary'] ?? '-') . ' per primary unit';
        $notesLines[] = '';
        $notesLines[] = 'Documents (' . count($documents) . '):';
        if (count($documents) === 0) {
            $notesLines[] = '  (none)';
        } else {
            foreach ($documents as $d) {
                $notesLines[] = '  - [' . $d['document_type'] . '] ' . $d['document_name']
                    . ' (' . ($d['document_date'] ?? 'no date') . ') -> ' . $d['file_path'];
            }
        }
        $notesLines[] = '';

        @file_put_contents($destFull . '/notes.txt', implode("\n", $notesLines) . "\n");
    } else {
        $folderAction = 'failed';
    }
} elseif (count($documents) === 0) {
    // No documents were ever uploaded for this logistic — nothing physical
    // to move, but still worth confirming that's genuinely why.
    $folderAction = 'none';
}

echo json_encode([
    'ok' => true,
    'data' => [
        'folder_action' => $folderAction,
    ],
]);
