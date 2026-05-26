<?php
// sync_background.php
// Dipanggil secara background saat staff/admin login atau logout
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
ob_clean();

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

$tables = [
    'settings', 'users', 'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames',
    'prescription_modifications', 'deletion_queue'
];

$exclude_columns = ['users' => ['password_hash']];

// ── Auto-create deletion_queue if not exists ──────────────────
$conn->query("CREATE TABLE IF NOT EXISTS deletion_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_table VARCHAR(100) NOT NULL,
    target_id_col VARCHAR(100) NOT NULL,
    target_id_val VARCHAR(255) NOT NULL,
    total_users INT NOT NULL DEFAULT 1,
    confirmed_count INT NOT NULL DEFAULT 0,
    confirmed_by TEXT DEFAULT NULL,
    deleted_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

// ── Process deletion queue ────────────────────────────────────
// Get current device identifier (use server host as device ID)
$device_id = $_SERVER['HTTP_HOST'] ?? 'unknown';

// Get pending deletions from Supabase
$res = supabase_request('/rest/v1/deletion_queue?order=created_at.asc', 'GET');
if ($res['status'] === 200 && !empty($res['body'])) {
    $queue = json_decode($res['body'], true) ?? [];

    // Get total approved users from local MySQL
    $user_result = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_approved = 1");
    $total_users = $user_result ? (int)$user_result->fetch_assoc()['total'] : 1;

    foreach ($queue as $item) {
        $q_id      = $item['id'];
        $q_table   = $item['target_table'];
        $q_id_col  = $item['target_id_col'];
        $q_id_val  = $item['target_id_val'];
        $confirmed = $item['confirmed_count'] ?? 0;
        $confirmed_by = $item['confirmed_by'] ?? '';

        // Check if this device already confirmed
        $already_confirmed = !empty($confirmed_by) && strpos($confirmed_by, $device_id) !== false;

        if (!$already_confirmed) {
            // Delete from local MySQL
            $conn->query("DELETE FROM `{$q_table}` WHERE `{$q_id_col}` = '" . $conn->real_escape_string($q_id_val) . "'");

            // Update confirmed count in Supabase
            $new_count = $confirmed + 1;
            $new_confirmed_by = trim($confirmed_by . ',' . $device_id, ',');

            supabase_request('/rest/v1/deletion_queue?id=eq.' . $q_id, 'PATCH', [
                'confirmed_count' => $new_count,
                'confirmed_by'    => $new_confirmed_by
            ]);

            // If all users confirmed → delete from Supabase target table + remove from queue
            if ($new_count >= $total_users) {
                supabase_request('/rest/v1/' . $q_table . '?' . $q_id_col . '=eq.' . urlencode($q_id_val), 'DELETE');
                supabase_request('/rest/v1/deletion_queue?id=eq.' . $q_id, 'DELETE');
            }
        }
    }
}

// ── Sync all tables to Supabase ───────────────────────────────
foreach ($tables as $table) {
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