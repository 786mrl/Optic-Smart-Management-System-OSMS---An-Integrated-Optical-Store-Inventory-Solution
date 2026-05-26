<?php
// sync_background.php
// Dipanggil secara background saat staff login/logout
// Tidak menampilkan output apapun

error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
ob_clean();

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

$tables = [
    'settings',
    'users',
    'frames_main',
    'frame_staging',
    'customer_examinations',
    'customer_orders',
    'custom_frames',
    'prescription_modifications'
];

$exclude_columns = ['users' => ['password_hash']];

function supabase_request($path, $method, $body = null) {
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: resolution=merge-duplicates,return=minimal'
    ];
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout'       => 30
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $context  = stream_context_create($opts);
    $response = @file_get_contents(SUPABASE_URL . $path, false, $context);
    $status   = 0;
    if (function_exists('http_get_last_response_headers')) { $http_response_header = http_get_last_response_headers() ?? []; }
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $status = intval($m[1] ?? 0);
    }
    return ['status' => $status];
}

function check_supabase() {
    $res = supabase_request('/rest/v1/settings?limit=1', 'GET');
    return $res['status'] >= 200 && $res['status'] < 500;
}

if (!check_supabase()) exit();

foreach ($tables as $table) {
    $result = $conn->query("SELECT * FROM `$table`");
    if (!$result) continue;
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        if (isset($exclude_columns[$table])) {
            foreach ($exclude_columns[$table] as $col) unset($row[$col]);
        }
        foreach ($row as $k => $v) if ($v === '') $row[$k] = null;
        $rows[] = $row;
    }
    if (empty($rows)) continue;
    foreach (array_chunk($rows, 50) as $batch) {
        supabase_request('/rest/v1/' . $table, 'POST', $batch);
    }
}

close_db_connection($conn);