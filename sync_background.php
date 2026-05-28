<?php
// sync_background.php
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

// Tables to sync (deletion_queue managed separately)
$sync_tables = [
    'settings', 'users', 'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames',
    'prescription_modifications'
];

// Tables that can be wiped and re-pulled from Supabase
$wipeable_tables = [
    'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames',
    'prescription_modifications', 'settings'
];

$exclude_columns = ['users' => ['password_hash']];

// ── Auto-create deletion_queue ────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS deletion_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_table VARCHAR(100) NOT NULL,
    target_id_col VARCHAR(100) NOT NULL,
    target_id_val VARCHAR(255) NOT NULL,
    total_users INT NOT NULL DEFAULT 1,
    confirmed_count INT NOT NULL DEFAULT 0,
    confirmed_by TEXT DEFAULT NULL,
    deleted_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL
)");

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

if (!check_supabase()) exit();

// ── Check if user needs fresh data (30 day wipe) ─────────────
$safe_username = $conn->real_escape_string($current_username);
$last_login_res = $conn->query(
    "SELECT last_login FROM users WHERE username = '$safe_username'"
);
$needs_wipe = false;
if ($last_login_res) {
    $row = $last_login_res->fetch_assoc();
    $last_login = $row['last_login'] ?? null;
    if ($last_login === null) {
        // Never logged in before → wipe
        $needs_wipe = true;
    } else {
        $days_since = (time() - strtotime($last_login)) / 86400;
        if ($days_since >= 30) $needs_wipe = true;
    }
}

// ── Wipe & pull fresh data from Supabase ─────────────────────
if ($needs_wipe) {
    foreach ($wipeable_tables as $table) {
        // Wipe local table
        $conn->query("DELETE FROM `$table`");

        // Pull fresh data from Supabase
        $res = supabase_request('/rest/v1/' . $table . '?select=*', 'GET');
        if ($res['status'] === 200 && !empty($res['body'])) {
            $rows = json_decode($res['body'], true) ?? [];
            if (empty($rows)) continue;

            // Get columns from first row
            $cols = array_keys($rows[0]);
            $col_list = '`' . implode('`, `', $cols) . '`';

            foreach ($rows as $row) {
                $values = array_map(function($v) use ($conn) {
                    return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                }, array_values($row));
                $val_list = implode(', ', $values);
                $conn->query("INSERT IGNORE INTO `$table` ($col_list) VALUES ($val_list)");
            }
        }
    }

    // Update last_login after wipe
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE users SET last_login = '$now' WHERE username = '$safe_username'");

    // Also update in Supabase
    supabase_request('/rest/v1/users?username=eq.' . urlencode($current_username), 'PATCH', [
        'last_login' => $now
    ]);

    // Skip normal sync this cycle — data is already fresh from Supabase
    close_db_connection($conn);
    exit();
}

// ── Update last_login (normal login) ─────────────────────────
$now = date('Y-m-d H:i:s');
$conn->query("UPDATE users SET last_login = '$now' WHERE username = '$safe_username'");
supabase_request('/rest/v1/users?username=eq.' . urlencode($current_username), 'PATCH', [
    'last_login' => $now
]);

// ── Pull ALL tables from Supabase every login ────────────────
// Ensures all devices always have latest data from cloud
$pk_map_pull = [
    'users'                      => 'user_id',
    'settings'                   => 'setting_key',
    'frames_main'                => 'ufc',
    'frame_staging'              => 'ufc',
    'customer_examinations'      => 'id',
    'customer_orders'            => 'id',
    'custom_frames'              => 'id',
    'prescription_modifications' => 'modification_id',
];

foreach ($sync_tables as $table) {
    $res = supabase_request('/rest/v1/' . $table . '?select=*', 'GET');
    if ($res['status'] !== 200 || empty($res['body'])) continue;

    $rows = json_decode($res['body'], true) ?? [];
    if (empty($rows)) continue;

    $cols     = array_keys($rows[0]);
    $col_list = '`' . implode('`, `', $cols) . '`';
    $pk_col   = $pk_map_pull[$table] ?? 'id';

    foreach ($rows as $row) {
        $values = array_map(function($v) use ($conn) {
            return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
        }, array_values($row));
        $val_list = implode(', ', $values);

        // ON DUPLICATE KEY UPDATE — update all columns except PK
        $update_parts = [];
        foreach ($cols as $col) {
            if ($col !== $pk_col) {
                $update_parts[] = "`$col` = VALUES(`$col`)";
            }
        }
        $update_set = implode(', ', $update_parts);

        $conn->query("INSERT INTO `$table` ($col_list) VALUES ($val_list)
            ON DUPLICATE KEY UPDATE $update_set");
    }
}

// ── Get current approved users ────────────────────────────────
$ur = $conn->query("SELECT username FROM users WHERE is_approved = 1");
$all_users   = [];
while ($row = $ur->fetch_assoc()) $all_users[] = $row['username'];
$total_users = count($all_users);

// ── Cleanup expired queue items ───────────────────────────────
$conn->query("DELETE FROM deletion_queue WHERE expires_at IS NOT NULL AND expires_at < NOW()");
supabase_request(
    '/rest/v1/deletion_queue?expires_at=lt.' . urlencode(date('Y-m-d\TH:i:s')),
    'DELETE'
);

// ── Process active deletion queue from Supabase ───────────────
$now_enc = urlencode(date('Y-m-d\TH:i:s'));
$res = supabase_request(
    '/rest/v1/deletion_queue?order=created_at.asc&expires_at=gt.' . $now_enc,
    'GET'
);

if ($res['status'] === 200 && !empty($res['body'])) {
    $queue = json_decode($res['body'], true) ?? [];

    foreach ($queue as $item) {
        $q_id      = (int)$item['id'];
        $q_table   = $item['target_table'];
        $q_id_col  = $item['target_id_col'];
        $q_id_val  = $item['target_id_val'];
        $confirmed = (int)($item['confirmed_count'] ?? 0);

        $confirmed_list = json_decode($item['confirmed_by'] ?? '[]', true);
        if (!is_array($confirmed_list)) $confirmed_list = [];

        if (in_array($current_username, $confirmed_list)) continue;

        // Delete from local MySQL
        $safe_val = $conn->real_escape_string($q_id_val);
        $safe_col = $conn->real_escape_string($q_id_col);
        $safe_tbl = $conn->real_escape_string($q_table);
        $conn->query("DELETE FROM `{$safe_tbl}` WHERE `{$safe_col}` = '{$safe_val}'");
        $conn->query("DELETE FROM deletion_queue WHERE target_table='{$safe_tbl}' AND target_id_col='{$safe_col}' AND target_id_val='{$safe_val}'");

        // Atomic confirm via RPC
        supabase_request('/rest/v1/rpc/confirm_deletion', 'POST', [
            'p_queue_id'      => $q_id,
            'p_username'      => $current_username,
            'p_current_count' => $confirmed
        ]);

        // Re-fetch to check if all confirmed
        $updated = supabase_request('/rest/v1/deletion_queue?id=eq.' . $q_id, 'GET');
        if ($updated['status'] === 200 && !empty($updated['body'])) {
            $updated_item = json_decode($updated['body'], true);
            $updated_item = $updated_item[0] ?? null;

            if ($updated_item) {
                $new_confirmed = json_decode($updated_item['confirmed_by'] ?? '[]', true);
                if (!is_array($new_confirmed)) $new_confirmed = [];

                $all_confirmed = count(array_intersect($new_confirmed, $all_users)) >= $total_users;

                if ($all_confirmed) {
                    supabase_request(
                        '/rest/v1/' . $q_table . '?' . $q_id_col . '=eq.' . urlencode($q_id_val),
                        'DELETE'
                    );
                    supabase_request('/rest/v1/deletion_queue?id=eq.' . $q_id, 'DELETE');
                }
            }
        }
    }
}

// ── Sync all tables to Supabase (queue processed first) ───────
foreach ($sync_tables as $table) {
    $result = $conn->query("SELECT * FROM `$table`");
    if (!$result) continue;
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        if (isset($exclude_columns[$table]))
            foreach ($exclude_columns[$table] as $col) unset($row[$col]);
        foreach ($row as $k => $v) if ($v === '') $row[$k] = null;
        $rows[] = $row;
    }
    if (empty($rows)) continue;
    foreach (array_chunk($rows, 50) as $batch) {
        supabase_request('/rest/v1/' . $table, 'POST', $batch);
    }
}

close_db_connection($conn);