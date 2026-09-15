<?php
// lisani_aos/ajax/delete_customer.php
// Deletes a customer row. Requires the logged-in user's password to be
// re-entered and verified — this is a destructive, unrecoverable action
// (data loss on the DB side), so it's intentionally NOT reachable via a
// plain click alone.
//
// Physical folder handling (storage/selling/[year]/[customer_name]/):
//   - empty (or missing) -> removed
//   - non-empty          -> moved to storage/recycle/selling/[year]/[customer_name]/
//     (the "selling" segment is kept so the recycle bin still shows which
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

/**
 * Turn a customer name into a filesystem-safe, lowercase folder name.
 * Strips path separators, ".." traversal, and anything outside
 * letters/digits/space/dash/underscore, then collapses whitespace.
 * Must match sanitize_folder_name() in create_customer.php / update_customer.php.
 */
function sanitize_folder_name(string $name): string
{
    $clean = str_replace(['/', '\\'], ' ', $name);
    $clean = preg_replace('/\.\.+/', '', $clean);
    $clean = preg_replace('/[^A-Za-z0-9 _-]/', '', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    return strtolower($clean);
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
    echo json_encode(['ok' => false, 'message' => 'Invalid customer id.']);
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

// Fetch year/customer_name first — needed to locate the physical folder,
// and DELETE alone wouldn't return them afterwards.
$stmt = $lisani_conn->prepare('SELECT year, customer_name FROM customers WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['ok' => false, 'message' => 'Customer not found.']);
    exit;
}

$stmt = $lisani_conn->prepare('DELETE FROM customers WHERE id = ?');
$stmt->bind_param('i', $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        // Folder handling, best-effort — the DB row is already gone either way:
        //   - empty folder (or none)      -> just remove it (rmdir, silent no-op if missing)
        //   - non-empty folder            -> move it under storage/recycle/[year]/[name]/
        //     instead of deleting real files, so nothing is permanently lost by
        //     accident. Recycle bin retention/cleanup policy: TBD later.
        $folderAction = 'none';
        $folderName   = sanitize_folder_name($existing['customer_name']);
        $customerDir  = rtrim(AOS_STORAGE_BASE, '/') . '/selling/' . $existing['year'] . '/' . $folderName;

        if ($folderName !== '' && is_dir($customerDir)) {
            if (is_dir_empty($customerDir)) {
                $folderAction = @rmdir($customerDir) ? 'deleted' : 'failed';
            } else {
                $recycleDir = rtrim(AOS_STORAGE_BASE, '/') . '/recycle/selling/' . $existing['year'] . '/' . $folderName;
                if (is_dir($recycleDir)) {
                    // Destination already taken (e.g. same customer name+year
                    // deleted before) — disambiguate instead of overwriting
                    // whatever's already in the recycle bin.
                    $recycleDir .= '-' . date('YmdHis');
                }
                $recycleYearDir = dirname($recycleDir);
                if (!is_dir($recycleYearDir)) {
                    @mkdir($recycleYearDir, 0775, true);
                }
                $folderAction = @rename($customerDir, $recycleDir) ? 'moved_to_recycle' : 'failed';
            }
        }

        echo json_encode(['ok' => true, 'data' => ['id' => $id, 'folder_action' => $folderAction]]);
    } else {
        echo json_encode(['ok' => false, 'message' => 'Customer not found.']);
    }
} else {
    echo json_encode(['ok' => false, 'message' => 'Failed to delete customer.']);
}

$stmt->close();