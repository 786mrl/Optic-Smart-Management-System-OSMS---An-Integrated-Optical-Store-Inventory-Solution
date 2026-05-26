<?php
// sync_to_supabase.php
include 'db_config.php';

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

$exclude_columns = [
    'users' => ['password_hash']
];

function supabase_request($path, $method, $body = null) {
    $url = SUPABASE_URL . $path;
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
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false
        ]
    ];

    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }

    $context  = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    $status   = 0;

    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $status = intval($m[1] ?? 0);
    }

    return ['body' => $response, 'status' => $status];
}

function supabase_upsert($table, $data) {
    if (empty($data)) return ['success' => true, 'count' => 0, 'note' => 'empty'];

    $batches = array_chunk($data, 50);
    $total   = 0;

    foreach ($batches as $batch) {
        $res = supabase_request('/rest/v1/' . $table, 'POST', $batch);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $total += count($batch);
        } else {
            return ['success' => false, 'error' => $res['body'], 'code' => $res['status']];
        }
    }
    return ['success' => true, 'count' => $total];
}

function check_supabase() {
    $res = supabase_request('/rest/v1/settings?limit=1', 'GET');
    return ['ok' => ($res['status'] >= 200 && $res['status'] < 500)];
}

$results     = [];
$supabase_ok = check_supabase();

if ($supabase_ok['ok']) {
    foreach ($tables as $table) {
        $result = $conn->query("SELECT * FROM `$table`");
        if (!$result) {
            $results[$table] = ['success' => false, 'error' => $conn->error];
            continue;
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (isset($exclude_columns[$table])) {
                foreach ($exclude_columns[$table] as $col) unset($row[$col]);
            }
            foreach ($row as $k => $v) if ($v === '') $row[$k] = null;
            $rows[] = $row;
        }
        $res             = supabase_upsert($table, $rows);
        $results[$table] = array_merge($res, ['mysql_count' => count($rows)]);
    }
}

close_db_connection($conn);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync ke Supabase</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { padding: 20px; }
        .sync-card { max-width: 600px; margin: 0 auto; }
        .sync-title { font-size: 20px; font-weight: bold; margin-bottom: 4px; }
        .sync-sub { font-size: 13px; color: #888; margin-bottom: 20px; }
        .status-box { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
        .online  { background: rgba(126,255,160,0.1); color: #7effa0; border: 1px solid rgba(126,255,160,0.2); }
        .offline { background: rgba(255,107,107,0.1); color: #ff6b6b; border: 1px solid rgba(255,107,107,0.2); }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.07); text-align: left; }
        th { color: #7eb8f7; font-weight: 600; }
        .ok   { color: #7effa0; }
        .err  { color: #ff6b6b; }
        .warn { color: #f6e05e; }
        .total-box { padding: 14px 16px; background: rgba(255,255,255,0.05); border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
        .total-box strong { color: #7effa0; font-size: 15px; }
        .back-btn {
            display: inline-block;
            padding: 12px 24px;
            background: rgba(255,255,255,0.07);
            border-radius: 12px;
            color: #ccc;
            text-decoration: none;
            font-size: 14px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .back-btn:hover { background: rgba(255,255,255,0.12); }
    </style>
</head>
<body>
<div class="sync-card">
    <div class="sync-title">☁️ Sync ke Supabase</div>
    <div class="sync-sub">Sinkronisasi semua data ke cloud — <?= date('d/m/Y H:i:s') ?></div>

    <?php if (!$supabase_ok['ok']): ?>
    <div class="status-box offline">
        ✗ Tidak bisa konek ke Supabase. Pastikan terhubung ke internet.
    </div>
    <?php else: ?>
    <div class="status-box online">✓ Supabase terjangkau — sync berjalan</div>

    <table>
        <tr><th>Tabel</th><th>Records</th><th>Uploaded</th><th>Status</th></tr>
        <?php
        $grand_total = 0;
        foreach ($results as $table => $res):
            $mysql    = $res['mysql_count'] ?? 0;
            $uploaded = $res['count'] ?? 0;
            $grand_total += $uploaded;
            $note   = isset($res['note']) ? ' <span class="warn">(' . $res['note'] . ')</span>' : '';
            $status = $res['success']
                ? '<span class="ok">✓ OK</span>'
                : '<span class="err">✗ ' . htmlspecialchars($res['error'] ?? 'Error') . '</span>';
        ?>
        <tr>
            <td><?= $table ?></td>
            <td><?= $mysql ?></td>
            <td><?= $uploaded ?><?= $note ?></td>
            <td><?= $status ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="total-box">
        <strong>Total: <?= $grand_total ?> records diupload</strong>
    </div>
    <?php endif; ?>

    <a href="index.php" class="back-btn">← Kembali</a>
</div>
</body>
</html>