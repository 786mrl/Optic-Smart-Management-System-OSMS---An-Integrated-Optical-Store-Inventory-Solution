<?php
// sync_to_supabase.php
session_start();
include 'db_config.php';

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

// Semua tabel di-sync dari mana pun (PC atau HP)
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

// Kolom sensitif tidak di-upload
$exclude_columns = [
    'users' => ['password_hash']
];

function supabase_upsert($table, $data) {
    if (empty($data)) return ['success' => true, 'count' => 0, 'note' => 'empty'];

    $url = SUPABASE_URL . '/rest/v1/' . $table;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: resolution=merge-duplicates,return=minimal'
    ];

    $batches = array_chunk($data, 50);
    $total   = 0;

    foreach ($batches as $batch) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($batch),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) return ['success' => false, 'error' => 'cURL: ' . $curlError];
        if ($httpCode >= 200 && $httpCode < 300) {
            $total += count($batch);
        } else {
            return ['success' => false, 'error' => $response, 'code' => $httpCode];
        }
    }
    return ['success' => true, 'count' => $total];
}

function check_supabase() {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/settings?limit=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10
    ]);
    curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) return ['ok' => false, 'error' => $curlError];
    return ['ok' => ($httpCode >= 200 && $httpCode < 500)];
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
        ✗ Tidak bisa konek ke Supabase. Pastikan terhubung ke internet.<br>
        <?= htmlspecialchars($supabase_ok['error'] ?? '') ?>
    </div>
    <?php else: ?>
    <div class="status-box online">✓ Supabase terjangkau — sync berhasil</div>

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
