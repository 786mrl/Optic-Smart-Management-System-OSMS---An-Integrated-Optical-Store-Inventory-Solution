<?php
// sync_api.php — v1.0
// Endpoint lokal untuk sync antar device (PC ↔ HP via WiFi)
// Dipanggil oleh device lain, BUKAN oleh user langsung
//
// POST actions:
//   ping              → cek apakah device ini online
//   get_pending       → kirim semua pending_sync + deleted_records ke device lain
//   apply_changes     → terima data dari device lain, terapkan ke database lokal
//   confirm_synced    → tandai pending_sync & deleted_records sebagai sudah disync

date_default_timezone_set('Asia/Jakarta');
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
ob_clean();

header('Content-Type: application/json');

// ── Keamanan: hanya dari jaringan lokal ──────────────────────
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local  = (
    strpos($client_ip, '192.168.') === 0 ||
    strpos($client_ip, '10.')      === 0 ||
    strpos($client_ip, '172.')     === 0 ||
    $client_ip === '127.0.0.1'
);
if (!$is_local) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses hanya dari jaringan lokal.']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$pk_map = [
    'users'                      => 'user_id',
    'settings'                   => 'setting_key',
    'frames_main'                => 'ufc',
    'frame_staging'              => 'ufc',
    'customer_examinations'      => 'id',
    'customer_orders'            => 'id',
    'custom_frames'              => 'id',
    'prescription_modifications' => 'modification_id',
];

// ════════════════════════════════════════════════════════════════
// ACTION: ping — cek device online
// ════════════════════════════════════════════════════════════════
if ($action === 'ping') {
    // Hitung pending di device ini
    $r1 = $conn->query("SELECT COUNT(*) AS t FROM pending_sync");
    $r2 = $conn->query("SELECT COUNT(*) AS t FROM deleted_records WHERE synced = 0");
    $pending = $r1 ? (int)$r1->fetch_assoc()['t'] : 0;
    $deleted = $r2 ? (int)$r2->fetch_assoc()['t'] : 0;

    echo json_encode([
        'online'        => true,
        'device'        => gethostname(),
        'server_time'   => date('Y-m-d H:i:s'),
        'pending_count' => $pending,
        'deleted_count' => $deleted,
        'total_pending' => $pending + $deleted,
    ]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// ACTION: get_pending — kirim data pending ke device yang minta
// ════════════════════════════════════════════════════════════════
if ($action === 'get_pending') {
    // Ambil semua pending_sync
    $rows = [];
    $res = $conn->query("SELECT * FROM pending_sync ORDER BY created_at ASC");
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;

    // Ambil semua deleted_records yang belum synced
    $deletes = [];
    $res2 = $conn->query("SELECT * FROM deleted_records WHERE synced = 0 ORDER BY deleted_at ASC");
    if ($res2) while ($row = $res2->fetch_assoc()) $deletes[] = $row;

    echo json_encode([
        'pending_rows' => $rows,
        'deleted_rows' => $deletes,
        'total'        => count($rows) + count($deletes),
        'exported_at'  => date('Y-m-d H:i:s'),
    ]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// ACTION: apply_changes — terima & terapkan data dari device lain
// ════════════════════════════════════════════════════════════════
if ($action === 'apply_changes') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        echo json_encode(['error' => 'Payload tidak valid.']);
        exit();
    }

    $pending_rows = $payload['pending_rows'] ?? [];
    $deleted_rows = $payload['deleted_rows'] ?? [];
    $applied      = 0;
    $skipped      = 0;
    $errors       = 0;

    // ── Terapkan INSERT/UPDATE ────────────────────────────────
    foreach ($pending_rows as $item) {
        $table  = $item['table_name'] ?? '';
        $action_type = $item['action'] ?? '';
        $data   = $item['data_snapshot'] ? json_decode($item['data_snapshot'], true) : null;

        if (!$data || !$table || !in_array($action_type, ['INSERT', 'UPDATE'])) {
            $skipped++;
            continue;
        }

        // Cek apakah sudah ada di deleted_records lokal (jangan apply jika sudah dihapus lokal)
        $rec_id   = $item['record_id'] ?? '';
        $safe_tbl = $conn->real_escape_string($table);
        $safe_rec = $conn->real_escape_string($rec_id);
        $bl = $conn->query("SELECT id FROM deleted_records
            WHERE table_name = '$safe_tbl' AND record_id = '$safe_rec'");
        if ($bl && $bl->num_rows > 0) { $skipped++; continue; }

        // Build INSERT ... ON DUPLICATE KEY UPDATE
        $pk_col      = $pk_map[$table] ?? 'id';
        $cols        = array_keys($data);
        $col_list    = '`' . implode('`, `', $cols) . '`';
        $values      = array_map(
            fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'",
            array_values($data)
        );
        $val_list    = implode(', ', $values);
        $update_parts = array_filter(array_map(
            fn($c) => $c !== $pk_col ? "`$c` = VALUES(`$c`)" : null, $cols
        ));

        $sql = "INSERT INTO `$table` ($col_list) VALUES ($val_list)
                ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts);

        if ($conn->query($sql)) {
            $applied++;
        } else {
            $errors++;
        }
    }

    // ── Terapkan DELETE ───────────────────────────────────────
    foreach ($deleted_rows as $item) {
        $table  = $item['table_name'] ?? '';
        $rec_id = $item['record_id']  ?? '';
        if (!$table || !$rec_id) { $skipped++; continue; }

        $pk_col   = $pk_map[$table] ?? 'id';
        $safe_tbl = $conn->real_escape_string($table);
        $safe_rec = $conn->real_escape_string($rec_id);
        $safe_by  = $conn->real_escape_string($item['deleted_by'] ?? 'sync');
        $safe_at  = $conn->real_escape_string($item['deleted_at'] ?? date('Y-m-d H:i:s'));

        // Hapus dari tabel target
        $conn->query("DELETE FROM `$table` WHERE `$pk_col` = '$safe_rec'");

        // Tambah ke deleted_records lokal supaya tidak di-apply ulang
        $conn->query("INSERT IGNORE INTO deleted_records
            (table_name, record_id, deleted_by, deleted_at, synced)
            VALUES ('$safe_tbl', '$safe_rec', '$safe_by', '$safe_at', 1)");

        $applied++;
    }

    echo json_encode([
        'success' => true,
        'applied' => $applied,
        'skipped' => $skipped,
        'errors'  => $errors,
    ]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// ACTION: confirm_synced — tandai pending sebagai sudah disync
// (dipanggil setelah device tujuan konfirmasi apply berhasil)
// ════════════════════════════════════════════════════════════════
if ($action === 'confirm_synced') {
    $conn->query("DELETE FROM pending_sync");
    $conn->query("UPDATE deleted_records SET synced = 1 WHERE synced = 0");

    echo json_encode(['success' => true, 'message' => 'pending_sync dibersihkan.']);
    exit();
}


// ════════════════════════════════════════════════════════════════
// ACTION: save_last_sync — catat riwayat sync ke last_sync
// ════════════════════════════════════════════════════════════════
if ($action === 'save_last_sync') {
    // Auto-create last_sync jika belum ada
    $conn->query("CREATE TABLE IF NOT EXISTS last_sync (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        direction  ENUM('push','pull') NOT NULL,
        target_ip  VARCHAR(100) NOT NULL,
        synced_at  DATETIME NOT NULL,
        total_rows INT DEFAULT 0,
        total_dels INT DEFAULT 0,
        done_by    VARCHAR(100) NOT NULL
    )");

    $dir        = in_array($_POST['direction'] ?? '', ['push','pull']) ? $_POST['direction'] : 'push';
    $target_ip  = $conn->real_escape_string($_POST['target_ip']  ?? '');
    $total_rows = (int)($_POST['total_rows'] ?? 0);
    $total_dels = (int)($_POST['total_dels'] ?? 0);
    $done_by    = $conn->real_escape_string($_SESSION['username'] ?? 'system');
    $now        = date('Y-m-d H:i:s');

    $conn->query("INSERT INTO last_sync (direction, target_ip, synced_at, total_rows, total_dels, done_by)
        VALUES ('$dir', '$target_ip', '$now', $total_rows, $total_dels, '$done_by')");

    // Hanya simpan 10 riwayat terakhir
    $conn->query("DELETE FROM last_sync WHERE id NOT IN (
        SELECT id FROM (SELECT id FROM last_sync ORDER BY synced_at DESC LIMIT 10) t
    )");

    close_db_connection($conn);
    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['error' => 'Action tidak dikenal: ' . htmlspecialchars($action)]);

// (file already ends above — this block handled by appending)
