<?php
// sync_background.php — v2.0
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

$sync_tables = [
    'settings', 'users', 'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames',
    'prescription_modifications'
];

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

$exclude_columns = ['users' => ['password_hash']];

// ── Auto-create tables ────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id VARCHAR(255) NOT NULL,
    action ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    changed_by VARCHAR(100) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sync_flag TINYINT(1) DEFAULT 1
)");

$conn->query("CREATE TABLE IF NOT EXISTS sync_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_log_user (log_id, username)
)");

$conn->query("CREATE TABLE IF NOT EXISTS pending_sync (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id VARCHAR(255) NOT NULL,
    action ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    data_snapshot TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS deleted_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id VARCHAR(255) NOT NULL,
    deleted_by VARCHAR(100) NOT NULL,
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_deleted (table_name, record_id)
)");

// ── Supabase request helper ───────────────────────────────────
function supabase_request($path, $method, $body = null) {
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: resolution=merge-duplicates,return=minimal'
    ];
    $opts = [
        'http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 30],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $context  = stream_context_create($opts);
    $response = @file_get_contents(SUPABASE_URL . $path, false, $context);
    $status   = 0;
    $hdrs = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if (!empty($hdrs)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0], $m);
        $status = intval($m[1] ?? 0);
    }
    return ['body' => $response, 'status' => $status];
}

function check_supabase() {
    $res = supabase_request('/rest/v1/settings?limit=1', 'GET');
    return $res['status'] >= 200 && $res['status'] < 500;
}

$supabase_online = check_supabase();

// ── Get approved users ────────────────────────────────────────
$ur = $conn->query("SELECT username FROM users WHERE is_approved = 1");
$all_users   = [];
while ($row = $ur->fetch_assoc()) $all_users[] = $row['username'];
$total_users = count($all_users);

// ── Update last_login ─────────────────────────────────────────
$safe_username = $conn->real_escape_string($current_username);
$now = date('Y-m-d H:i:s');
$conn->query("UPDATE users SET last_login = '$now' WHERE username = '$safe_username'");

// ── Check if needs full wipe (30 day inactive) ────────────────
$lr = $conn->query("SELECT last_login FROM users WHERE username = '$safe_username'");
$needs_wipe = false;
if ($lr) {
    $row = $lr->fetch_assoc();
    $last_login = $row['last_login'] ?? null;
    if ($last_login === null) {
        $needs_wipe = true;
    } else {
        $days = (time() - strtotime($last_login)) / 86400;
        if ($days >= 30) $needs_wipe = true;
    }
}

// ── WIPE & FULL PULL if inactive 30 days ─────────────────────
if ($needs_wipe && $supabase_online) {
    $wipeable = ['frames_main', 'frame_staging', 'customer_examinations',
                 'customer_orders', 'custom_frames', 'prescription_modifications', 'settings'];

    foreach ($wipeable as $table) {
        $conn->query("DELETE FROM `$table`");
        $res = supabase_request('/rest/v1/' . $table . '?select=*', 'GET');
        if ($res['status'] !== 200 || empty($res['body'])) continue;
        $rows = json_decode($res['body'], true) ?? [];
        if (empty($rows)) continue;
        $pk_col   = $pk_map[$table] ?? 'id';
        $cols     = array_keys($rows[0]);
        $col_list = '`' . implode('`, `', $cols) . '`';
        foreach ($rows as $row) {
            $values = array_map(function($v) use ($conn) {
                return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
            }, array_values($row));
            $val_list    = implode(', ', $values);
            $update_parts = [];
            foreach ($cols as $col) {
                if ($col !== $pk_col)
                    $update_parts[] = "`$col` = VALUES(`$col`)";
            }
            $conn->query("INSERT INTO `$table` ($col_list) VALUES ($val_list)
                ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts));
        }
    }

    // Update last_login in Supabase
    supabase_request('/rest/v1/users?username=eq.' . urlencode($current_username), 'PATCH', [
        'last_login' => $now
    ]);

    close_db_connection($conn);
    exit();
}

// ── Process pending_sync queue (offline recovery) ────────────
if ($supabase_online) {
    $pq = $conn->query("SELECT * FROM pending_sync ORDER BY created_at ASC LIMIT 50");
    if ($pq && $pq->num_rows > 0) {
        while ($item = $pq->fetch_assoc()) {
            $ps_id     = (int)$item['id'];
            $ps_table  = $item['table_name'];
            $ps_action = $item['action'];
            $ps_record = $item['record_id'];
            $ps_data   = $item['data_snapshot'] ? json_decode($item['data_snapshot'], true) : null;

            if ($ps_action === 'DELETE') {
                supabase_request('/rest/v1/' . $ps_table . '?id=eq.' . urlencode($ps_record), 'DELETE');
                // Add to deleted_records in Supabase
                supabase_request('/rest/v1/deleted_records', 'POST', [[
                    'table_name' => $ps_table,
                    'record_id'  => $ps_record,
                    'deleted_by' => $current_username,
                    'deleted_at' => $now
                ]]);
            } elseif ($ps_data) {
                supabase_request('/rest/v1/' . $ps_table, 'POST', [$ps_data]);
                // Log to activity_log in Supabase
                supabase_request('/rest/v1/activity_log', 'POST', [[
                    'table_name' => $ps_table,
                    'record_id'  => $ps_record,
                    'action'     => $ps_action,
                    'changed_by' => $current_username,
                    'changed_at' => $now,
                    'sync_flag'  => 1
                ]]);
            }
            // Remove from pending_sync
            $conn->query("DELETE FROM pending_sync WHERE id = $ps_id");
        }
    }
}

// ── Pull: apply activity_log changes from Supabase ───────────
if ($supabase_online) {
    // Get active activity_log entries not yet applied by this user
    $res = supabase_request(
        '/rest/v1/activity_log?sync_flag=eq.1&select=*&order=changed_at.asc',
        'GET'
    );

    if ($res['status'] === 200 && !empty($res['body'])) {
        $logs = json_decode($res['body'], true) ?? [];

        foreach ($logs as $log) {
            $log_id    = (int)$log['id'];
            $log_table = $log['table_name'];
            $log_recid = $log['record_id'];
            $log_action= $log['action'];

            // Check if this user already applied this log entry
            $check = $conn->query("SELECT id FROM sync_status
                WHERE log_id = $log_id AND username = '$safe_username'");
            if ($check && $check->num_rows > 0) continue;

            if ($log_action === 'DELETE') {
                // Delete from local MySQL
                $pk_col = $pk_map[$log_table] ?? 'id';
                $safe_recid = $conn->real_escape_string($log_recid);
                $conn->query("DELETE FROM `$log_table` WHERE `$pk_col` = '$safe_recid'");

                // Add to local deleted_records
                $safe_table = $conn->real_escape_string($log_table);
                $safe_by    = $conn->real_escape_string($log['changed_by']);
                $conn->query("INSERT IGNORE INTO deleted_records
                    (table_name, record_id, deleted_by, deleted_at)
                    VALUES ('$safe_table', '$safe_recid', '$safe_by', '$now')");

            } elseif (in_array($log_action, ['INSERT', 'UPDATE'])) {
                // Pull fresh record from Supabase
                $pk_col  = $pk_map[$log_table] ?? 'id';
                $pull    = supabase_request(
                    '/rest/v1/' . $log_table . '?' . $pk_col . '=eq.' . urlencode($log_recid),
                    'GET'
                );
                if ($pull['status'] === 200 && !empty($pull['body'])) {
                    $records = json_decode($pull['body'], true) ?? [];
                    if (!empty($records)) {
                        $record   = $records[0];
                        $cols     = array_keys($record);
                        $col_list = '`' . implode('`, `', $cols) . '`';
                        $values   = array_map(function($v) use ($conn) {
                            return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                        }, array_values($record));
                        $val_list    = implode(', ', $values);
                        $update_parts = [];
                        foreach ($cols as $col) {
                            if ($col !== $pk_col)
                                $update_parts[] = "`$col` = VALUES(`$col`)";
                        }
                        $conn->query("INSERT INTO `$log_table` ($col_list) VALUES ($val_list)
                            ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts));
                    }
                }
            }

            // Mark as applied by this user in local sync_status
            $conn->query("INSERT IGNORE INTO sync_status (log_id, username, applied_at)
                VALUES ($log_id, '$safe_username', '$now')");

            // Also record in Supabase sync_status
            supabase_request('/rest/v1/sync_status', 'POST', [[
                'log_id'     => $log_id,
                'username'   => $current_username,
                'applied_at' => $now
            ]]);

            // Check if ALL approved users have applied this log entry
            $applied_res = supabase_request(
                '/rest/v1/sync_status?log_id=eq.' . $log_id . '&select=username',
                'GET'
            );
            if ($applied_res['status'] === 200 && !empty($applied_res['body'])) {
                $applied_users = json_decode($applied_res['body'], true) ?? [];
                $applied_names = array_column($applied_users, 'username');
                $all_done = count(array_intersect($applied_names, $all_users)) >= $total_users;

                if ($all_done) {
                    // Turn off sync_flag
                    supabase_request('/rest/v1/activity_log?id=eq.' . $log_id, 'PATCH', [
                        'sync_flag' => 0
                    ]);
                    $conn->query("UPDATE activity_log SET sync_flag = 0 WHERE id = $log_id");

                    // Clean up sync_status entries for this log
                    supabase_request('/rest/v1/sync_status?log_id=eq.' . $log_id, 'DELETE');
                    $conn->query("DELETE FROM sync_status WHERE log_id = $log_id");

                    // Clean up activity_log entry
                    supabase_request('/rest/v1/activity_log?id=eq.' . $log_id, 'DELETE');
                    $conn->query("DELETE FROM activity_log WHERE id = $log_id");
                }
            }
        }
    }
}

// ── Push: sync local data to Supabase ────────────────────────
if ($supabase_online) {
    foreach ($sync_tables as $table) {
        $result = $conn->query("SELECT * FROM `$table`");
        if (!$result) continue;
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (isset($exclude_columns[$table]))
                foreach ($exclude_columns[$table] as $col) unset($row[$col]);
            foreach ($row as $k => $v) if ($v === '') $row[$k] = null;

            // Skip if in deleted_records blacklist
            $pk_col  = $pk_map[$table] ?? 'id';
            $rec_id  = $conn->real_escape_string($row[$pk_col] ?? '');
            $safe_t  = $conn->real_escape_string($table);
            $bl      = $conn->query("SELECT id FROM deleted_records
                WHERE table_name='$safe_t' AND record_id='$rec_id'");
            if ($bl && $bl->num_rows > 0) continue;

            $rows[] = $row;
        }
        if (empty($rows)) continue;
        foreach (array_chunk($rows, 50) as $batch) {
            supabase_request('/rest/v1/' . $table, 'POST', $batch);
        }
    }

    // Update last_login in Supabase
    supabase_request('/rest/v1/users?username=eq.' . urlencode($current_username), 'PATCH', [
        'last_login' => $now
    ]);
}

close_db_connection($conn);
