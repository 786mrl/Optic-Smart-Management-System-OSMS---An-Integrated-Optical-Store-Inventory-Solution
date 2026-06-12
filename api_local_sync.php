<?php
// api_local_sync.php
// Endpoint PHP untuk HP mengambil (pull) data dari MySQL PC
// Letakkan di: C:\xampp\htdocs\optic_pos\api_local_sync.php
//
// HP memanggil: GET http://[IP-PC]/optic_pos/api_local_sync.php?table=frames_main
// PC merespons: JSON berisi semua data tabel tersebut

session_start();
include 'db_config.php';

// Keamanan: harus login
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Tabel yang boleh di-sync (whitelist keamanan)
$allowed_tables = [
    'customer_examinations',
    'customer_orders',
    'custom_frames',
    'frames_main',
    'frame_staging',
    'prescription_modifications',
    'settings',
    'users'
];

$action = $_GET['action'] ?? 'pull';

// ── PULL: HP minta data dari PC ───────────────────────────────────────────
if ($action === 'pull') {
    $table = $_GET['table'] ?? '';

    if (!in_array($table, $allowed_tables)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tabel tidak valid: ' . $table]);
        exit();
    }

    // Opsional: filter by updated_at jika HP kirim timestamp terakhir sync
    $since = $_GET['since'] ?? null;

    $sql = "SELECT * FROM `$table`";
    if ($since && $table !== 'settings' && $table !== 'users') {
        // Hanya tabel dengan kolom updated_at atau created_at
        $since_escaped = $conn->real_escape_string($since);
        if (in_array($table, ['frames_main', 'frame_staging', 'customer_orders'])) {
            $sql .= " WHERE updated_at >= '$since_escaped'";
        } elseif (in_array($table, ['customer_examinations'])) {
            $sql .= " WHERE created_at >= '$since_escaped'";
        }
    }

    $result = $conn->query($sql);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Query gagal: ' . $conn->error]);
        exit();
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    echo json_encode([
        'success'    => true,
        'table'      => $table,
        'count'      => count($rows),
        'data'       => $rows,
        'server_time'=> date('Y-m-d H:i:s')
    ]);
    exit();
}

// ── PUSH: HP kirim data baru ke PC ───────────────────────────────────────
if ($action === 'push') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['table']) || !isset($input['records'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Data tidak lengkap']);
        exit();
    }

    $table   = $input['table'];
    $records = $input['records'];

    if (!in_array($table, $allowed_tables)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tabel tidak valid']);
        exit();
    }

    // Tabel yang tidak boleh di-push dari HP (hanya bisa dari PC)
    $readonly_tables = ['users', 'settings'];
    if (in_array($table, $readonly_tables)) {
        http_response_code(403);
        echo json_encode(['error' => 'Tabel ini tidak bisa diubah dari HP']);
        exit();
    }

    $success_count = 0;
    $error_count   = 0;
    $errors        = [];

    foreach ($records as $record) {
        // Hapus field internal IndexedDB sebelum insert ke MySQL
        unset($record['_updated_at']);

        if (empty($record)) continue;

        // Build INSERT ... ON DUPLICATE KEY UPDATE
        $columns      = array_keys($record);
        $col_list     = implode('`, `', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // UPDATE SET (semua kolom kecuali primary key)
        $pk_map = [
            'customer_examinations'      => 'id',
            'customer_orders'            => 'id',
            'custom_frames'              => 'id',
            'frames_main'                => 'ufc',
            'frame_staging'              => 'ufc',
            'prescription_modifications' => 'modification_id',
        ];
        $pk = $pk_map[$table] ?? 'id';

        $update_parts = [];
        foreach ($columns as $col) {
            if ($col !== $pk) {
                $update_parts[] = "`$col` = VALUES(`$col`)";
            }
        }
        $update_set = implode(', ', $update_parts);

        $sql  = "INSERT INTO `$table` (`$col_list`) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $update_set";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $error_count++;
            $errors[] = $conn->error;
            continue;
        }

        // Bind parameters dinamis
        $types  = str_repeat('s', count($columns)); // semua sebagai string, MySQL akan konversi
        $values = array_values($record);
        $stmt->bind_param($types, ...$values);

        if ($stmt->execute()) {
            $success_count++;
        } else {
            $error_count++;
            $errors[] = $stmt->error;
        }
        $stmt->close();
    }

    echo json_encode([
        'success'       => true,
        'table'         => $table,
        'saved'         => $success_count,
        'errors'        => $error_count,
        'error_details' => $errors,
        'server_time'   => date('Y-m-d H:i:s')
    ]);
    exit();
}

// ── STATUS: cek koneksi ───────────────────────────────────────────────────
if ($action === 'status') {
    $tables_count = [];
    $allowed_tables_check = ['customer_examinations', 'customer_orders', 'frames_main', 'frame_staging'];
    foreach ($allowed_tables_check as $t) {
        $r = $conn->query("SELECT COUNT(*) as c FROM `$t`");
        $tables_count[$t] = $r ? $r->fetch_assoc()['c'] : 0;
    }

    echo json_encode([
        'success'     => true,
        'status'      => 'online',
        'server_time' => date('Y-m-d H:i:s'),
        'tables'      => $tables_count
    ]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Action tidak dikenal: ' . $action]);
close_db_connection($conn);
?>
