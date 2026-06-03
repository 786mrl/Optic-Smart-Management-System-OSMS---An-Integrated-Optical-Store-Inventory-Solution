<?php
// sync_background.php — v3.0
// PERAN: Hanya PULL data dari Supabase ke local MySQL
// TIDAK ADA push otomatis — push dilakukan manual via push_to_cloud.php
//
// Dipanggil saat login (dari login.php) dengan fire-and-forget
// Fungsi:
//   1. Update last_login user
//   2. Jika user tidak aktif 30 hari → wipe & full pull dari Supabase
//   3. Pull activity_log dari Supabase → terapkan ke local DB
//   4. Catat sync_status per user

error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
ob_clean();

session_start();
$current_username = $_SESSION['username'] ?? null;
$current_user_id  = $_SESSION['user_id'] ?? null;
if (!$current_username) exit();

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

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

// ── Auto-create tables ────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS activity_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id  VARCHAR(255) NOT NULL,
    action     ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    changed_by VARCHAR(100) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sync_flag  TINYINT(1) DEFAULT 1
)");
$conn->query("CREATE TABLE IF NOT EXISTS sync_status (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    log_id     INT NOT NULL,
    username   VARCHAR(100) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_log_user (log_id, username)
)");
$conn->query("CREATE TABLE IF NOT EXISTS pending_sync (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    table_name    VARCHAR(100) NOT NULL,
    record_id     VARCHAR(255) NOT NULL,
    action        ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    data_snapshot TEXT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_processing TINYINT(1) DEFAULT 0
)");
$conn->query("CREATE TABLE IF NOT EXISTS deleted_records (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id  VARCHAR(255) NOT NULL,
    deleted_by VARCHAR(100) NOT NULL,
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    synced     TINYINT(1) DEFAULT 0,
    UNIQUE KEY unique_deleted (table_name, record_id)
)");
$conn->query("CREATE TABLE IF NOT EXISTS last_cloud_push (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    pushed_at  DATETIME NOT NULL,
    pushed_by  VARCHAR(100) NOT NULL,
    total_rows INT DEFAULT 0,
    total_dels INT DEFAULT 0
)");

// ── Supabase helper ───────────────────────────────────────────
function supabase_request($path, $method, $body = null) {
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: resolution=merge-duplicates,return=minimal'
    ];
    $opts = [
        'http' => ['method' => $method, 'header' => implode("\r\n", $headers),
                   'ignore_errors' => true, 'timeout' => 30],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $ctx      = stream_context_create($opts);
    $response = @file_get_contents(SUPABASE_URL . $path, false, $ctx);
    $status   = 0;
    $hdrs = $http_response_header ?? [];
    if (!empty($hdrs)) { preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0], $m); $status = intval($m[1] ?? 0); }
    return ['body' => $response, 'status' => $status];
}

function check_supabase() {
    $res = supabase_request('/rest/v1/settings?limit=1', 'GET');
    return $res['status'] >= 200 && $res['status'] < 500;
}

$supabase_online = check_supabase();
$safe_username   = $conn->real_escape_string($current_username);
$now             = date('Y-m-d H:i:s');

// ── Get approved users list ───────────────────────────────────
$ur = $conn->query("SELECT username FROM users WHERE is_approved = 1");
$all_users   = [];
while ($row = $ur->fetch_assoc()) $all_users[] = $row['username'];
$total_users = count($all_users);

// ── Update last_login local ───────────────────────────────────
$conn->query("UPDATE users SET last_login = '$now' WHERE username = '$safe_username'");

// ── Check jika perlu full wipe (30 hari tidak aktif) ─────────
$lr = $conn->query("SELECT last_login FROM users WHERE username = '$safe_username'");
$needs_wipe = false;
if ($lr) {
    $row        = $lr->fetch_assoc();
    $last_login = $row['last_login'] ?? null;
    if ($last_login === null) {
        $needs_wipe = true;
    } else {
        $days = (time() - strtotime($last_login)) / 86400;
        if ($days >= 30) $needs_wipe = true;
    }
}

// ── WIPE & FULL PULL jika 30 hari tidak aktif ────────────────
if ($needs_wipe && $supabase_online) {
    $wipeable = ['frames_main', 'frame_staging', 'customer_examinations',
                 'customer_orders', 'custom_frames', 'prescription_modifications', 'settings'];

    foreach ($wipeable as $table) {
        $conn->query("DELETE FROM `$table`");
        $res = supabase_request('/rest/v1/' . $table . '?select=*', 'GET');
        if ($res['status'] !== 200 || empty($res['body'])) continue;
        $rows   = json_decode($res['body'], true) ?? [];
        if (empty($rows)) continue;
        $pk_col   = $pk_map[$table] ?? 'id';
        $cols     = array_keys($rows[0]);
        $col_list = '`' . implode('`, `', $cols) . '`';
        foreach ($rows as $row) {
            $values = array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'",
                                array_values($row));
            $val_list     = implode(', ', $values);
            $update_parts = array_filter(array_map(fn($c) => $c !== $pk_col ? "`$c` = VALUES(`$c`)" : null, $cols));
            $conn->query("INSERT INTO `$table` ($col_list) VALUES ($val_list)
                ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts));
        }
    }
    // Update last_login di Supabase
    supabase_request('/rest/v1/users?username=eq.' . urlencode($current_username), 'PATCH',
        ['last_login' => $now]);
    close_db_connection($conn);
    exit();
}

// ── PULL: terapkan activity_log dari Supabase ke local ────────
// Ini yang memberitahu device ini bahwa ada data baru dari device lain
if ($supabase_online) {
    $res = supabase_request('/rest/v1/activity_log?sync_flag=eq.1&select=*&order=changed_at.asc', 'GET');

    if ($res['status'] === 200 && !empty($res['body'])) {
        $logs = json_decode($res['body'], true) ?? [];

        foreach ($logs as $log) {
            $log_id     = (int)$log['id'];
            $log_table  = $log['table_name'];
            $log_recid  = $log['record_id'];
            $log_action = $log['action'];

            // Skip jika user ini sudah apply log ini
            $check = $conn->query("SELECT id FROM sync_status
                WHERE log_id = $log_id AND username = '$safe_username'");
            if ($check && $check->num_rows > 0) continue;

            if ($log_action === 'DELETE') {
                $pk_col     = $pk_map[$log_table] ?? 'id';
                $safe_recid = $conn->real_escape_string($log_recid);
                $safe_tbl   = $conn->real_escape_string($log_table);
                $safe_by    = $conn->real_escape_string($log['changed_by'] ?? '');

                $conn->query("DELETE FROM `$log_table` WHERE `$pk_col` = '$safe_recid'");
                $conn->query("INSERT IGNORE INTO deleted_records
                    (table_name, record_id, deleted_by, deleted_at, synced)
                    VALUES ('$safe_tbl', '$safe_recid', '$safe_by', '$now', 1)");

            } elseif (in_array($log_action, ['INSERT', 'UPDATE'])) {
                $pk_col = $pk_map[$log_table] ?? 'id';
                $pull   = supabase_request(
                    '/rest/v1/' . $log_table . '?' . $pk_col . '=eq.' . urlencode($log_recid), 'GET');

                if ($pull['status'] === 200 && !empty($pull['body'])) {
                    $records = json_decode($pull['body'], true) ?? [];
                    if (!empty($records)) {
                        $record       = $records[0];
                        $cols         = array_keys($record);
                        $col_list     = '`' . implode('`, `', $cols) . '`';
                        $values       = array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'",
                                                  array_values($record));
                        $val_list     = implode(', ', $values);
                        $update_parts = array_filter(array_map(fn($c) => $c !== $pk_col ? "`$c` = VALUES(`$c`)" : null, $cols));
                        $conn->query("INSERT INTO `$log_table` ($col_list) VALUES ($val_list)
                            ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts));
                    }
                }
            }

            // Catat bahwa user ini sudah apply
            $conn->query("INSERT IGNORE INTO sync_status (log_id, username, applied_at)
                VALUES ($log_id, '$safe_username', '$now')");

            supabase_request('/rest/v1/sync_status', 'POST', [[
                'log_id'     => $log_id,
                'username'   => $current_username,
                'applied_at' => $now
            ]]);

            // Cek apakah SEMUA user sudah apply → bersihkan log entry ini
            $applied_res = supabase_request(
                '/rest/v1/sync_status?log_id=eq.' . $log_id . '&select=username', 'GET');

            if ($applied_res['status'] === 200 && !empty($applied_res['body'])) {
                $applied_users = json_decode($applied_res['body'], true) ?? [];
                $applied_names = array_column($applied_users, 'username');
                $all_done = count(array_intersect($applied_names, $all_users)) >= $total_users;

                if ($all_done) {
                    supabase_request('/rest/v1/activity_log?id=eq.' . $log_id, 'PATCH', ['sync_flag' => 0]);
                    supabase_request('/rest/v1/sync_status?log_id=eq.' . $log_id, 'DELETE');
                    supabase_request('/rest/v1/activity_log?id=eq.' . $log_id, 'DELETE');
                    $conn->query("UPDATE activity_log SET sync_flag = 0 WHERE id = $log_id");
                    $conn->query("DELETE FROM sync_status WHERE log_id = $log_id");
                    $conn->query("DELETE FROM activity_log WHERE id = $log_id");
                }
            }
        }
    }

    // Update last_login di Supabase juga
    supabase_request('/rest/v1/users?username=eq.' . urlencode($current_username), 'PATCH',
        ['last_login' => $now]);
}

close_db_connection($conn);