<?php
// lisani_aos/ajax/delete_activity_code.php
// Deletes an activity code row. Requires the logged-in user's password to
// be re-entered and verified — this is a destructive, unrecoverable action
// (data loss on the DB side), so it's intentionally NOT reachable via a
// plain click alone. Same pattern as delete_customer.php.
//
// Physical folder handling (storage/input/[year]/[department]/[code]/):
//   - empty (or missing) -> removed
//   - non-empty          -> moved to storage/recycle/input/[year]/[department]/[code]/
//     (the "input" segment is kept so the recycle bin still shows which
//     original section the folder came from) instead of being deleted, so
//     files already inside are never silently lost. Recycle bin
//     retention/cleanup policy is handled separately later.

session_start();

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // provides $lisani_conn (mysqli)

header('Content-Type: application/json');

// Same root as create_activity_code.php / create_customer.php.
if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

/** True if a directory exists and has no entries other than . and .. */
function is_dir_empty(string $dir): bool
{
    $items = @scandir($dir);
    if ($items === false) {
        return false;
    }
    return count($items) <= 2; // just "." and ".."
}

$id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';

if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid activity code id.']);
    exit;
}
if ($password === '') {
    echo json_encode(['ok' => false, 'message' => 'Password is required.']);
    exit;
}

// Verify against the currently logged-in user's own password_hash —
// same table/column used by the existing verify_password.php flow.
$stmt = $lisani_conn->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password_hash'])) {
    echo json_encode(['ok' => false, 'message' => 'Incorrect password.']);
    exit;
}

// Fetch relative_path first — needed to locate the physical folder, and
// DELETE alone wouldn't return it afterwards.
$stmt = $lisani_conn->prepare('SELECT relative_path FROM activities WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['ok' => false, 'message' => 'Activity code not found.']);
    exit;
}

$stmt = $lisani_conn->prepare('DELETE FROM activities WHERE id = ?');
$stmt->bind_param('i', $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        // Folder handling, best-effort — the DB row is already gone either way:
        //   - empty folder (or none)      -> just remove it (rmdir, silent no-op if missing)
        //   - non-empty folder            -> move it under storage/recycle/input/[year]/[dept]/[code]/
        //     instead of deleting real files, so nothing is permanently lost by
        //     accident. Recycle bin retention/cleanup policy: TBD later.
        $folderAction = 'none';
        $activityDir  = rtrim(AOS_STORAGE_BASE, '/') . '/' . $existing['relative_path'];

        if (is_dir($activityDir)) {
            if (is_dir_empty($activityDir)) {
                $folderAction = @rmdir($activityDir) ? 'deleted' : 'failed';
            } else {
                $recycleDir = rtrim(AOS_STORAGE_BASE, '/') . '/recycle/' . rtrim($existing['relative_path'], '/');
                if (is_dir($recycleDir)) {
                    // Destination already taken (e.g. same path deleted
                    // before) — disambiguate instead of overwriting
                    // whatever's already in the recycle bin.
                    $recycleDir .= '-' . date('YmdHis');
                }
                $recycleParentDir = dirname($recycleDir);
                if (!is_dir($recycleParentDir)) {
                    @mkdir($recycleParentDir, 0775, true);
                }
                $folderAction = @rename($activityDir, $recycleDir) ? 'moved_to_recycle' : 'failed';
            }
        }

        echo json_encode(['ok' => true, 'data' => ['id' => $id, 'folder_action' => $folderAction]]);
    } else {
        echo json_encode(['ok' => false, 'message' => 'Activity code not found.']);
    }
} else {
    echo json_encode(['ok' => false, 'message' => 'Failed to delete activity code.']);
}

$stmt->close();