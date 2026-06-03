<?php
// pull_from_cloud.php — v1.0
// Endpoint AJAX untuk PULL manual dari Supabase ke local MySQL
// Dipanggil dari activity_log.php
//
// POST params:
//   action = 'check'       → cek berapa update tersedia dari Supabase
//   action = 'pull_batch'  → tarik 1 batch (10 log entry) dari activity_log Supabase
//   action = 'finalize'    → update last pull, bersihkan sync_status

error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
ob_clean();

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'not_logged_in']);
    exit();
}

$current_username = $_SESSION['username'] ?? 'unknown';
$action           = $_POST['action'] ?? '';

define('SUPABASE_URL_PULL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY_PULL', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');
define('PULL_BATCH_SIZE', 10);

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

// ── Supabase helper ───────────────────────────────────────────
function sb_pull($path, $method, $body = null) {
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY_PULL,
        'Authorization: Bearer ' . SUPABASE_KEY_PULL,
        'Prefer: resolution=merge-duplicates,return=minimal'
    ];
    $opts = [
        'http' => ['method' => $method, 'header' => implode("\r\n", $headers),
                   'ignore_errors' => true, 'timeout' => 30],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $ctx      = stream_context_create($opts);
    $response = @file_get_contents(SUPABASE_URL_PULL . $path, false, $ctx);
    $status   = 0;
    $hdrs     = $http_response_header ?? [];
    if (!empty($hdrs)) { preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0], $m); $status = intval($m[1] ?? 0); }
    return ['body' => $response, 'status' => $status];
}

$safe_username = $conn->real_escape_string($current_username);

// ════════════════════════════════════════════════════════════════
// ACTION: check — berapa log entry di Supabase yang belum diapply
// ════════════════════════════════════════════════════════════════
if ($action === 'check') {
    // Cek Supabase online
    $sb_check  = sb_pull('/rest/v1/settings?limit=1', 'GET');
    $sb_online = $sb_check['status'] >= 200 && $sb_check['status'] < 500;

    $available = 0;
    if ($sb_online) {
        // Ambil semua activity_log dari Supabase yang sync_flag=1
        $res = sb_pull('/rest/v1/activity_log?sync_flag=eq.1&select=id,table_name,action,changed_by,changed_at&order=changed_at.asc', 'GET');
        if ($res['status'] === 200 && !empty($res['body'])) {
            $all_logs = json_decode($res['body'], true) ?? [];
            // Filter: yang belum diapply oleh user ini
            foreach ($all_logs as $log) {
                $log_id = (int)$log['id'];
                $check  = $conn->query("SELECT id FROM sync_status
                    WHERE log_id = $log_id AND username = '$safe_username'");
                if (!$check || $check->num_rows === 0) $available++;
            }
        }
    }

    // Last pull info
    $last_pull = null;
    $r = $conn->query("SELECT * FROM last_cloud_pull ORDER BY pulled_at DESC LIMIT 1");
    if ($r && $r->num_rows > 0) $last_pull = $r->fetch_assoc();

    close_db_connection($conn);
    echo json_encode([
        'sb_online'  => $sb_online,
        'available'  => $available,
        'last_pull'  => $last_pull,
        'server_time'=> date('H:i'),
    ]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// Validasi Supabase online untuk semua pull action
// ════════════════════════════════════════════════════════════════
if (in_array($action, ['pull_batch', 'finalize'])) {
    $sb_check = sb_pull('/rest/v1/settings?limit=1', 'GET');
    if ($sb_check['status'] < 200 || $sb_check['status'] >= 500) {
        close_db_connection($conn);
        echo json_encode(['error' => 'supabase_offline', 'message' => 'Supabase tidak dapat dijangkau.']);
        exit();
    }
}

// ════════════════════════════════════════════════════════════════
// ACTION: pull_batch — tarik 1 batch dari activity_log Supabase
// ════════════════════════════════════════════════════════════════
if ($action === 'pull_batch') {
    $now = date('Y-m-d H:i:s');

    // Get approved users (untuk cek all_done)
    $ur = $conn->query("SELECT username FROM users WHERE is_approved = 1");
    $all_users = [];
    while ($row = $ur->fetch_assoc()) $all_users[] = $row['username'];
    $total_users = count($all_users);

    // Ambil activity_log dari Supabase
    $res = sb_pull('/rest/v1/activity_log?sync_flag=eq.1&select=*&order=changed_at.asc&limit=' . PULL_BATCH_SIZE, 'GET');

    if ($res['status'] !== 200 || empty($res['body'])) {
        close_db_connection($conn);
        echo json_encode(['done' => true, 'processed' => 0, 'remaining' => 0]);
        exit();
    }

    $logs = json_decode($res['body'], true) ?? [];
    if (empty($logs)) {
        close_db_connection($conn);
        echo json_encode(['done' => true, 'processed' => 0, 'remaining' => 0]);
        exit();
    }

    $processed = 0;
    $log_details = [];

    foreach ($logs as $log) {
        $log_id     = (int)$log['id'];
        $log_table  = $log['table_name'];
        $log_recid  = $log['record_id'];
        $log_action = $log['action'];

        // Skip jika user ini sudah apply
        $check = $conn->query("SELECT id FROM sync_status
            WHERE log_id = $log_id AND username = '$safe_username'");
        if ($check && $check->num_rows > 0) {
            $log_details[] = "⏭ Skip (sudah applied): {$log_action} {$log_table} #{$log_recid}";
            continue;
        }

        if ($log_action === 'DELETE') {
            $pk_col     = $pk_map[$log_table] ?? 'id';
            $safe_recid = $conn->real_escape_string($log_recid);
            $safe_tbl   = $conn->real_escape_string($log_table);
            $safe_by    = $conn->real_escape_string($log['changed_by'] ?? '');

            $conn->query("DELETE FROM `$log_table` WHERE `$pk_col` = '$safe_recid'");
            $conn->query("INSERT IGNORE INTO deleted_records
                (table_name, record_id, deleted_by, deleted_at, synced)
                VALUES ('$safe_tbl', '$safe_recid', '$safe_by', '$now', 1)");
            $log_details[] = "🗑 DELETE {$log_table} #{$log_recid}";

        } elseif (in_array($log_action, ['INSERT', 'UPDATE'])) {
            $pk_col = $pk_map[$log_table] ?? 'id';
            $pull   = sb_pull('/rest/v1/' . $log_table . '?' . $pk_col . '=eq.' . urlencode($log_recid), 'GET');

            if ($pull['status'] === 200 && !empty($pull['body'])) {
                $records = json_decode($pull['body'], true) ?? [];
                if (!empty($records)) {
                    $record       = $records[0];
                    $cols         = array_keys($record);
                    $col_list     = '`' . implode('`, `', $cols) . '`';
                    $values       = array_map(
                        fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'",
                        array_values($record)
                    );
                    $val_list     = implode(', ', $values);
                    $update_parts = array_filter(array_map(
                        fn($c) => $c !== $pk_col ? "`$c` = VALUES(`$c`)" : null, $cols
                    ));
                    $conn->query("INSERT INTO `$log_table` ($col_list) VALUES ($val_list)
                        ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts));
                    $log_details[] = "✓ {$log_action} {$log_table} #{$log_recid}";
                }
            } else {
                $log_details[] = "⚠ Gagal pull {$log_table} #{$log_recid}";
            }
        }

        // Catat applied di local
        $conn->query("INSERT IGNORE INTO sync_status (log_id, username, applied_at)
            VALUES ($log_id, '$safe_username', '$now')");

        // Catat di Supabase sync_status
        sb_pull('/rest/v1/sync_status', 'POST', [[
            'log_id'     => $log_id,
            'username'   => $current_username,
            'applied_at' => $now
        ]]);

        // Cek apakah semua user sudah apply → bersihkan
        $applied_res = sb_pull('/rest/v1/sync_status?log_id=eq.' . $log_id . '&select=username', 'GET');
        if ($applied_res['status'] === 200 && !empty($applied_res['body'])) {
            $applied_users = json_decode($applied_res['body'], true) ?? [];
            $applied_names = array_column($applied_users, 'username');
            $all_done = count(array_intersect($applied_names, $all_users)) >= $total_users;

            if ($all_done) {
                sb_pull('/rest/v1/activity_log?id=eq.' . $log_id, 'PATCH', ['sync_flag' => 0]);
                sb_pull('/rest/v1/sync_status?log_id=eq.' . $log_id, 'DELETE');
                sb_pull('/rest/v1/activity_log?id=eq.' . $log_id, 'DELETE');
                $conn->query("UPDATE activity_log SET sync_flag = 0 WHERE id = $log_id");
                $conn->query("DELETE FROM sync_status WHERE log_id = $log_id");
                $conn->query("DELETE FROM activity_log WHERE id = $log_id");
            }
        }

        $processed++;
    }

    // Cek sisa yang belum diapply
    $remaining = 0;
    $res2 = sb_pull('/rest/v1/activity_log?sync_flag=eq.1&select=id', 'GET');
    if ($res2['status'] === 200 && !empty($res2['body'])) {
        $all_remaining = json_decode($res2['body'], true) ?? [];
        foreach ($all_remaining as $r) {
            $lid = (int)$r['id'];
            $ck  = $conn->query("SELECT id FROM sync_status WHERE log_id = $lid AND username = '$safe_username'");
            if (!$ck || $ck->num_rows === 0) $remaining++;
        }
    }

    close_db_connection($conn);
    echo json_encode([
        'done'       => ($remaining === 0),
        'processed'  => $processed,
        'remaining'  => $remaining,
        'log_details'=> $log_details,
    ]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// ACTION: finalize — catat last_cloud_pull
// ════════════════════════════════════════════════════════════════
if ($action === 'finalize') {
    // Auto-create last_cloud_pull jika belum ada
    $conn->query("CREATE TABLE IF NOT EXISTS last_cloud_pull (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        pulled_at  DATETIME NOT NULL,
        pulled_by  VARCHAR(100) NOT NULL,
        total_rows INT DEFAULT 0
    )");

    $now       = date('Y-m-d H:i:s');
    $safe_user = $conn->real_escape_string($current_username);
    $conn->query("INSERT INTO last_cloud_pull (pulled_at, pulled_by, total_rows)
        VALUES ('$now', '$safe_user', 0)");

    close_db_connection($conn);
    echo json_encode(['done' => true, 'pulled_at' => $now]);
    exit();
}

close_db_connection($conn);
echo json_encode(['error' => 'unknown_action']);
