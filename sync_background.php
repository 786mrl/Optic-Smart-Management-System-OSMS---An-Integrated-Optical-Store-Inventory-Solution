<?php
// sync_background.php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
ob_clean();

session_start();
$current_username = $_SESSION['username'] ?? null;
if (!$current_username) exit();

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

// Tabel yang di-sync (deletion_queue TIDAK di-sync otomatis)
$sync_tables = [
    'settings', 'users', 'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames',
    'prescription_modifications'
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
    expires_at TIMESTAMP DEFAULT NULL
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
    $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    if (!empty($hdrs)) { preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0], $m); $status = intval($m[1] ?? 0); }
    return ['body' => $response, 'status' => $status];
}

function check_supabase() {
    $res = supabase_request('/rest/v1/settings?limit=1', 'GET');
    return $res['status'] >= 200 && $res['status'] < 500;
}

if (!check_supabase()) exit();

// ── Get all approved usernames from local MySQL ───────────────
$ur = $conn->query("SELECT username FROM users WHERE is_approved = 1");
$all_users = [];
while ($row = $ur->fetch_assoc()) $all_users[] = $row['username'];
$total_users = count($all_users);

// ── Process expired queue items (30 days) ────────────────────
// Cleanup expired locally
$conn->query("DELETE FROM deletion_queue WHERE expires_at IS NOT NULL AND expires_at < NOW()");

// Cleanup expired in Supabase
supabase_request('/rest/v1/deletion_queue?expires_at=lt.' . urlencode(date('Y-m-d\TH:i:s')), 'DELETE');

// ── Process active deletion queue from Supabase ──────────────
$res = supabase_request('/rest/v1/deletion_queue?order=created_at.asc&expires_at=gt.' . urlencode(date('Y-m-d\TH:i:s')), 'GET');

if ($res['status'] === 200 && !empty($res['body'])) {
    $queue = json_decode($res['body'], true) ?? [];

    foreach ($queue as $item) {
        $q_id      = $item['id'];
        $q_table   = $item['target_table'];
        $q_id_col  = $item['target_id_col'];
        $q_id_val  = $item['target_id_val'];
        $confirmed = (int)($item['confirmed_count'] ?? 0);
        $confirmed_by_raw = $item['confirmed_by'] ?? '';

        // Parse confirmed_by as JSON array for reliable check
        $confirmed_list = json_decode($confirmed_by_raw, true);
        if (!is_array($confirmed_list)) $confirmed_list = [];

        // Check if current user already confirmed
        $already_confirmed = in_array($current_username, $confirmed_list);

        if (!$already_confirmed) {
            // Delete from local MySQL
            $safe_val = $conn->real_escape_string($q_id_val);
            $conn->query("DELETE FROM `{$q_table}` WHERE `{$q_id_col}` = '{$safe_val}'");

            // Add current user to confirmed list
            $confirmed_list[] = $current_username;
            $new_count = count($confirmed_list);
            $new_confirmed_by = json_encode($confirmed_list);

            // Atomic update — only update if confirmed_count matches what we read
            // This prevents race condition
            supabase_request('/rest/v1/deletion_queue?id=eq.' . $q_id . '&confirmed_count=eq.' . $confirmed, 'PATCH', [
                'confirmed_count' => $new_count,
                'confirmed_by'    => $new_confirmed_by
            ]);

            // Check if all approved users have confirmed
            $all_confirmed = count(array_intersect($confirmed_list, $all_users)) >= $total_users;

            if ($all_confirmed) {
                // Delete from Supabase target table
                supabase_request('/rest/v1/' . $q_table . '?' . $q_id_col . '=eq.' . urlencode($q_id_val), 'DELETE');
                // Remove from Supabase deletion_queue
                supabase_request('/rest/v1/deletion_queue?id=eq.' . $q_id, 'DELETE');
                // Remove from local deletion_queue
                $conn->query("DELETE FROM deletion_queue WHERE id = " . (int)$q_id);
            }
        }
    }
}

// ── Sync all tables to Supabase ───────────────────────────────
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