<?php
// lisani_aos/ajax/manage_departments.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid session.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$departmentsFile = __DIR__ . '/../departments.json';

function load_departments(string $file): array
{
    $data = json_decode(@file_get_contents($file), true);
    return $data['departments'] ?? [];
}

function save_departments(string $file, array $departments): bool
{
    $payload = json_encode(['departments' => array_values($departments)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents($file, $payload, LOCK_EX) !== false;
}

function slugify_key(string $label, array $existing): string
{
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '-', $key);
    $key = trim($key, '-');
    if ($key === '') {
        $key = 'dept';
    }
    $existingKeys = array_column($existing, 'key');
    $base = $key;
    $i = 2;
    while (in_array($key, $existingKeys, true)) {
        $key = $base . '-' . $i;
        $i++;
    }
    return $key;
}

$action = $_POST['action'] ?? 'list';
$departments = load_departments($departmentsFile);

switch ($action) {

    case 'list':
        echo json_encode(['ok' => true, 'departments' => $departments]);
        break;

    case 'add':
        $label = strtoupper(trim($_POST['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 50) {
            echo json_encode(['ok' => false, 'message' => 'Department name is invalid.']);
            exit;
        }
        $newKey = slugify_key($label, $departments);
        $departments[] = ['key' => $newKey, 'label' => $label];
        if (!save_departments($departmentsFile, $departments)) {
            echo json_encode(['ok' => false, 'message' => 'Failed to save departments.json.']);
            exit;
        }
        echo json_encode(['ok' => true, 'departments' => $departments]);
        break;

    case 'edit':
        // Only the label can change — the key stays fixed because it is
        // already used as a physical folder name for existing activities.
        $key   = trim($_POST['key'] ?? '');
        $label = strtoupper(trim($_POST['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 50) {
            echo json_encode(['ok' => false, 'message' => 'Department name is invalid.']);
            exit;
        }
        $found = false;
        foreach ($departments as &$dept) {
            if ($dept['key'] === $key) {
                $dept['label'] = $label;
                $found = true;
                break;
            }
        }
        unset($dept);
        if (!$found) {
            echo json_encode(['ok' => false, 'message' => 'Department not found.']);
            exit;
        }
        if (!save_departments($departmentsFile, $departments)) {
            echo json_encode(['ok' => false, 'message' => 'Failed to save departments.json.']);
            exit;
        }
        echo json_encode(['ok' => true, 'departments' => $departments]);
        break;

    case 'delete':
        $key = trim($_POST['key'] ?? '');

        // Refuse deletion if the department is already used by an activity,
        // since its key is part of that activity's relative_path on disk.
        $stmt = $lisani_conn->prepare("SELECT COUNT(*) AS cnt FROM activities WHERE relative_path LIKE CONCAT('input/%/', ?, '/%')");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();

        if ($count > 0) {
            echo json_encode(['ok' => false, 'message' => "Can't delete — already used by {$count} activity code(s)."]);
            exit;
        }

        $departments = array_values(array_filter($departments, function ($d) use ($key) {
            return $d['key'] !== $key;
        }));
        if (!save_departments($departmentsFile, $departments)) {
            echo json_encode(['ok' => false, 'message' => 'Failed to save departments.json.']);
            exit;
        }
        echo json_encode(['ok' => true, 'departments' => $departments]);
        break;

    default:
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
}
