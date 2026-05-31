<?php
// activity_helper.php
// Include di setiap halaman PHP yang melakukan INSERT/UPDATE/DELETE
// Usage:
//   log_activity($conn, 'frames_main', 'FRAME-001', 'INSERT', $_SESSION['username']);
//   log_activity($conn, 'customer_orders', '123', 'UPDATE', $_SESSION['username']);
//   log_activity($conn, 'customer_orders', '123', 'DELETE', $_SESSION['username']);

define('SUPABASE_URL_AH', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY_AH', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

function log_activity($conn, $table, $record_id, $action, $username) {
    $safe_table    = $conn->real_escape_string($table);
    $safe_record   = $conn->real_escape_string($record_id);
    $safe_action   = $conn->real_escape_string($action);
    $safe_username = $conn->real_escape_string($username);
    $now           = date('Y-m-d H:i:s');

    // Auto-create tables if not exist
    $conn->query("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(100) NOT NULL,
        record_id VARCHAR(255) NOT NULL,
        action ENUM('INSERT','UPDATE','DELETE') NOT NULL,
        changed_by VARCHAR(100) NOT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sync_flag TINYINT(1) DEFAULT 1
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

    // 1. Log to local activity_log
    $conn->query("INSERT INTO activity_log
        (table_name, record_id, action, changed_by, changed_at, sync_flag)
        VALUES ('$safe_table', '$safe_record', '$safe_action', '$safe_username', '$now', 1)");
    $log_id = $conn->insert_id;

    // 2. If DELETE, add to deleted_records blacklist
    if ($action === 'DELETE') {
        $conn->query("INSERT IGNORE INTO deleted_records
            (table_name, record_id, deleted_by, deleted_at)
            VALUES ('$safe_table', '$safe_record', '$safe_username', '$now')");
    }

    // 3. Get data snapshot for INSERT/UPDATE (for offline queue)
    $data_snapshot = null;
    if ($action !== 'DELETE') {
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
        $pk_col = $pk_map[$table] ?? 'id';
        $result = $conn->query("SELECT * FROM `$safe_table` WHERE `$pk_col` = '$safe_record' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $data_snapshot = json_encode($result->fetch_assoc());
        }
    }

    // 4. Try to push to Supabase directly
    $supabase_ok = push_to_supabase($table, $record_id, $action, $username, $now, $data_snapshot, $log_id);

    // 5. If Supabase unreachable, add to pending_sync queue
    if (!$supabase_ok) {
        $safe_snapshot = $data_snapshot ? $conn->real_escape_string($data_snapshot) : 'NULL';
        $snapshot_val  = $data_snapshot ? "'$safe_snapshot'" : 'NULL';
        $conn->query("INSERT INTO pending_sync
            (table_name, record_id, action, data_snapshot)
            VALUES ('$safe_table', '$safe_record', '$safe_action', $snapshot_val)");
    }

    return $log_id;
}

function push_to_supabase($table, $record_id, $action, $username, $now, $data_snapshot, $log_id) {
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY_AH,
        'Authorization: Bearer ' . SUPABASE_KEY_AH,
        'Prefer: resolution=merge-duplicates,return=minimal'
    ];

    // Check Supabase reachable
    $check = supabase_req_ah('/rest/v1/settings?limit=1', 'GET', null, $headers);
    if ($check['status'] < 200 || $check['status'] >= 500) return false;

    if ($action === 'DELETE') {
        // Add to deleted_records in Supabase
        supabase_req_ah('/rest/v1/deleted_records', 'POST', [[
            'table_name' => $table,
            'record_id'  => $record_id,
            'deleted_by' => $username,
            'deleted_at' => $now
        ]], $headers);

        // Delete from Supabase target table
        $pk_map = [
            'users' => 'user_id', 'settings' => 'setting_key',
            'frames_main' => 'ufc', 'frame_staging' => 'ufc',
            'customer_examinations' => 'id', 'customer_orders' => 'id',
            'custom_frames' => 'id', 'prescription_modifications' => 'modification_id',
        ];
        $pk_col = $pk_map[$table] ?? 'id';
        supabase_req_ah('/rest/v1/' . $table . '?' . $pk_col . '=eq.' . urlencode($record_id), 'DELETE', null, $headers);

    } elseif ($data_snapshot) {
        // Push data to Supabase
        $data = json_decode($data_snapshot, true);
        if ($data) {
            supabase_req_ah('/rest/v1/' . $table, 'POST', [$data], $headers);
        }
    }

    // Log to Supabase activity_log
    supabase_req_ah('/rest/v1/activity_log', 'POST', [[
        'table_name' => $table,
        'record_id'  => $record_id,
        'action'     => $action,
        'changed_by' => $username,
        'changed_at' => $now,
        'sync_flag'  => 1
    ]], $headers);

    return true;
}

function supabase_req_ah($path, $method, $body, $headers) {
    $header_str = implode("\r\n", $headers);
    $opts = [
        'http' => ['method' => $method, 'header' => $header_str, 'ignore_errors' => true, 'timeout' => 10],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $context  = stream_context_create($opts);
    $response = @file_get_contents(SUPABASE_URL_AH . $path, false, $context);
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
