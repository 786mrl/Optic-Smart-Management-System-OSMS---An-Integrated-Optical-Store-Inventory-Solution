<?php
// push_to_cloud.php — v1.0
// Endpoint AJAX untuk push manual pending_sync ke Supabase
// Dipanggil dari activity_log.php
//
// POST params:
//   action = 'check'        → cek status (pending count, last push, time allowed)
//   action = 'push_batch'   → push 1 batch (10 item) dari pending_sync
//   action = 'push_deletes' → push deleted_records yang belum di-sync
//   action = 'finalize'     → bersihkan pending_sync, catat last_cloud_push

date_default_timezone_set('Asia/Jakarta');
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

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');
define('BATCH_SIZE', 10);

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

// ── Supabase helper ───────────────────────────────────────────
function supabase_req($path, $method, $body = null) {
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
    $hdrs     = $http_response_header ?? [];
    if (!empty($hdrs)) { preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0], $m); $status = intval($m[1] ?? 0); }
    return ['body' => $response, 'status' => $status];
}

function is_push_time_allowed() {
    // Hanya boleh push antara jam 20:30 – 23:59
    $h = (int)date('H');
    $m = (int)date('i');
    $total_minutes = $h * 60 + $m;
    return $total_minutes >= (20 * 60 + 30); // >= 20:30
}

// ════════════════════════════════════════════════════════════════
// ACTION: check — status awal sebelum push
// ════════════════════════════════════════════════════════════════
if ($action === 'check') {
    $pending_count  = 0;
    $deleted_count  = 0;
    $oldest_pending = null;
    $days_oldest    = 0;
    $last_push      = null;

    $r = $conn->query("SELECT COUNT(*) AS total FROM pending_sync WHERE is_processing = 0");
    if ($r) $pending_count = (int)$r->fetch_assoc()['total'];

    $r = $conn->query("SELECT COUNT(*) AS total FROM deleted_records WHERE synced = 0");
    if ($r) $deleted_count = (int)$r->fetch_assoc()['total'];

    $r = $conn->query("SELECT MIN(created_at) AS oldest FROM pending_sync WHERE is_processing = 0");
    if ($r) {
        $row = $r->fetch_assoc();
        $oldest_pending = $row['oldest'];
        if ($oldest_pending) $days_oldest = (time() - strtotime($oldest_pending)) / 86400;
    }

    $r = $conn->query("SELECT pushed_at, pushed_by, total_rows, total_dels
        FROM last_cloud_push ORDER BY pushed_at DESC LIMIT 1");
    if ($r && $r->num_rows > 0) $last_push = $r->fetch_assoc();

    // Cek Supabase
    $sb_check = supabase_req('/rest/v1/settings?limit=1', 'GET');
    $sb_online = $sb_check['status'] >= 200 && $sb_check['status'] < 500;

    close_db_connection($conn);
    echo json_encode([
        'pending_count'  => $pending_count,
        'deleted_count'  => $deleted_count,
        'total_items'    => $pending_count + $deleted_count,
        'days_oldest'    => round($days_oldest, 1),
        'oldest_pending' => $oldest_pending,
        'last_push'      => $last_push,
        'sb_online'      => $sb_online,
        'time_allowed'   => is_push_time_allowed(),
        'server_time'    => date('H:i'),
    ]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// Validasi waktu untuk semua action push
// ════════════════════════════════════════════════════════════════
if (in_array($action, ['push_batch', 'push_deletes', 'finalize'])) {
    if (!is_push_time_allowed()) {
        close_db_connection($conn);
        echo json_encode([
            'error'       => 'time_not_allowed',
            'message'     => 'Upload hanya boleh dilakukan mulai pukul 20:30. Sekarang: ' . date('H:i'),
            'server_time' => date('H:i'),
        ]);
        exit();
    }

    // Cek Supabase
    $sb_check = supabase_req('/rest/v1/settings?limit=1', 'GET');
    if ($sb_check['status'] < 200 || $sb_check['status'] >= 500) {
        close_db_connection($conn);
        echo json_encode(['error' => 'supabase_offline', 'message' => 'Supabase tidak dapat dijangkau.']);
        exit();
    }
}

// ════════════════════════════════════════════════════════════════
// ACTION: push_batch — push 1 batch INSERT/UPDATE dari pending_sync
// ════════════════════════════════════════════════════════════════
if ($action === 'push_batch') {
    $offset = (int)($_POST['offset'] ?? 0);

    // Ambil total keseluruhan dulu untuk progress
    $r = $conn->query("SELECT COUNT(*) AS total FROM pending_sync");
    $total_pending = $r ? (int)$r->fetch_assoc()['total'] : 0;

    // Ambil 1 batch dari pending_sync (INSERT/UPDATE)
    $pq = $conn->query("SELECT * FROM pending_sync
        WHERE action IN ('INSERT','UPDATE') AND is_processing = 0
        ORDER BY created_at ASC LIMIT " . BATCH_SIZE);

    if (!$pq || $pq->num_rows === 0) {
        // Tidak ada lagi INSERT/UPDATE
        close_db_connection($conn);
        echo json_encode(['done' => true, 'processed' => 0]);
        exit();
    }

    $ids_processed = [];
    $pushed        = 0;
    $errors        = 0;

    // Lock rows ini agar tidak diproses 2x
    $ids_to_lock = [];
    while ($item = $pq->fetch_assoc()) $ids_to_lock[] = (int)$item['id'];
    $pq->data_seek(0); // reset pointer

    $id_list = implode(',', $ids_to_lock);
    $conn->query("UPDATE pending_sync SET is_processing = 1 WHERE id IN ($id_list)");

    // Group by table untuk batch push
    $by_table = [];
    $pq->data_seek(0);
    while ($item = $pq->fetch_assoc()) {
        $by_table[$item['table_name']][] = $item;
    }

    foreach ($by_table as $table => $items) {
        $rows_to_push = [];
        foreach ($items as $item) {
            $data = $item['data_snapshot'] ? json_decode($item['data_snapshot'], true) : null;
            if (!$data) { $ids_processed[] = (int)$item['id']; continue; }

            // Bersihkan kolom sensitif
            if (isset($exclude_columns[$table])) {
                foreach ($exclude_columns[$table] as $col) unset($data[$col]);
            }
            // NULL-kan string kosong
            foreach ($data as $k => $v) if ($v === '') $data[$k] = null;

            $rows_to_push[] = $data;
            $ids_processed[] = (int)$item['id'];
        }

        if (empty($rows_to_push)) continue;

        $res = supabase_req('/rest/v1/' . $table, 'POST', $rows_to_push);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $pushed += count($rows_to_push);
            // Catat ke activity_log di Supabase
            foreach ($items as $item) {
                supabase_req('/rest/v1/activity_log', 'POST', [[
                    'table_name' => $item['table_name'],
                    'record_id'  => $item['record_id'],
                    'action'     => $item['action'],
                    'changed_by' => $current_username,
                    'changed_at' => $item['created_at'],
                    'sync_flag'  => 1
                ]]);
            }
        } else {
            $errors++;
            // Unlock jika gagal
            foreach ($items as $item) {
                $id = (int)$item['id'];
                $conn->query("UPDATE pending_sync SET is_processing = 0 WHERE id = $id");
            }
        }
    }

    // Hapus yang berhasil dari pending_sync
    if (!empty($ids_processed)) {
        $done_list = implode(',', $ids_processed);
        $conn->query("DELETE FROM pending_sync WHERE id IN ($done_list) AND is_processing = 1");
    }

    // Hitung sisa
    $r = $conn->query("SELECT COUNT(*) AS total FROM pending_sync WHERE action IN ('INSERT','UPDATE')");
    $remaining = $r ? (int)$r->fetch_assoc()['total'] : 0;

    close_db_connection($conn);
    echo json_encode([
        'done'      => ($remaining === 0),
        'processed' => $pushed,
        'errors'    => $errors,
        'remaining' => $remaining,
    ]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// ACTION: push_deletes — push deleted_records ke Supabase
// ════════════════════════════════════════════════════════════════
if ($action === 'push_deletes') {
    $dq = $conn->query("SELECT * FROM deleted_records WHERE synced = 0 ORDER BY deleted_at ASC");
    $pushed  = 0;
    $errors  = 0;

    if ($dq && $dq->num_rows > 0) {
        while ($item = $dq->fetch_assoc()) {
            $table     = $item['table_name'];
            $record_id = $item['record_id'];
            $pk_col    = $pk_map[$table] ?? 'id';

            // Hapus dari Supabase target table
            $res = supabase_req('/rest/v1/' . $table . '?' . $pk_col . '=eq.' . urlencode($record_id), 'DELETE');

            // Catat ke Supabase activity_log
            supabase_req('/rest/v1/activity_log', 'POST', [[
                'table_name' => $table,
                'record_id'  => $record_id,
                'action'     => 'DELETE',
                'changed_by' => $item['deleted_by'],
                'changed_at' => $item['deleted_at'],
                'sync_flag'  => 1
            ]]);

            // Tandai sudah synced di local
            $id = (int)$item['id'];
            $conn->query("UPDATE deleted_records SET synced = 1 WHERE id = $id");
            $pushed++;
        }
    }

    close_db_connection($conn);
    echo json_encode(['done' => true, 'pushed_deletes' => $pushed, 'errors' => $errors]);
    exit();
}

// ════════════════════════════════════════════════════════════════
// ACTION: finalize — setelah semua batch selesai
// ════════════════════════════════════════════════════════════════
if ($action === 'finalize') {
    // Hitung sisa (seharusnya 0)
    $r1 = $conn->query("SELECT COUNT(*) AS total FROM pending_sync");
    $r2 = $conn->query("SELECT COUNT(*) AS total FROM deleted_records WHERE synced = 0");
    $remaining_pending = $r1 ? (int)$r1->fetch_assoc()['total'] : 0;
    $remaining_deleted = $r2 ? (int)$r2->fetch_assoc()['total'] : 0;

    // Hitung berapa yang sudah di-push di sesi ini
    $r3 = $conn->query("SELECT COUNT(*) AS total FROM deleted_records WHERE synced = 1");
    $total_dels = $r3 ? (int)$r3->fetch_assoc()['total'] : 0;

    // Catat ke last_cloud_push
    $now       = date('Y-m-d H:i:s');
    $safe_user = $conn->real_escape_string($current_username);
    $conn->query("INSERT INTO last_cloud_push (pushed_at, pushed_by, total_rows, total_dels)
        VALUES ('$now', '$safe_user', 0, $total_dels)");

    // Bersihkan pending_sync yang sudah diproses
    $conn->query("DELETE FROM pending_sync WHERE is_processing = 1");

    close_db_connection($conn);
    echo json_encode([
        'done'              => true,
        'remaining_pending' => $remaining_pending,
        'remaining_deleted' => $remaining_deleted,
        'pushed_at'         => $now,
    ]);
    exit();
}

close_db_connection($conn);
echo json_encode(['error' => 'unknown_action']);