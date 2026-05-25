<?php
// upload_to_supabase.php
// Jalankan SEKALI di PC untuk upload data MySQL → Supabase
// Akses via browser: https://192.168.18.10/optic_pos/upload_to_supabase.php
// HAPUS file ini setelah selesai!

session_start();
include 'db_config.php';

// Supabase config
define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

// Tabel yang akan di-upload (urutan penting)
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

// Kolom password yang tidak boleh di-upload ke cloud
$exclude_columns = [
    'users' => ['password_hash']
];

function supabase_upsert($table, $data) {
    if (empty($data)) return ['success' => true, 'count' => 0];

    $url = SUPABASE_URL . '/rest/v1/' . $table;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: resolution=merge-duplicates,return=minimal'
    ];

    // Kirim dalam batch 50
    $batches = array_chunk($data, 50);
    $total   = 0;

    foreach ($batches as $batch) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($batch),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $total += count($batch);
        } else {
            return ['success' => false, 'error' => $response, 'code' => $httpCode];
        }
    }

    return ['success' => true, 'count' => $total];
}

$results = [];

foreach ($tables as $table) {
    $sql    = "SELECT * FROM `$table`";
    $result = $conn->query($sql);

    if (!$result) {
        $results[$table] = ['success' => false, 'error' => $conn->error];
        continue;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // Hapus kolom sensitif
        if (isset($exclude_columns[$table])) {
            foreach ($exclude_columns[$table] as $col) {
                unset($row[$col]);
            }
        }
        // Konversi null values
        foreach ($row as $k => $v) {
            if ($v === '') $row[$k] = null;
        }
        $rows[] = $row;
    }

    if (empty($rows)) {
        $results[$table] = ['success' => true, 'count' => 0, 'note' => 'empty table'];
        continue;
    }

    $res            = supabase_upsert($table, $rows);
    $results[$table] = array_merge($res, ['mysql_count' => count($rows)]);
}

close_db_connection($conn);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Upload to Supabase</title>
    <style>
        body { font-family: monospace; background:#1a1a2e; color:#e0e0e0; padding:24px; }
        h2   { color:#7eb8f7; }
        .ok  { color:#68d391; }
        .err { color:#ff6b6b; }
        .warn{ color:#f6e05e; }
        table{ border-collapse:collapse; width:100%; margin-top:16px; }
        th,td{ padding:10px 14px; border:1px solid #2d3748; text-align:left; }
        th   { background:#2d3748; color:#7eb8f7; }
        tr:hover{ background:rgba(255,255,255,0.03); }
        .total{ margin-top:20px; padding:14px; background:#2d3748; border-radius:8px; }
    </style>
</head>
<body>
<h2>📤 Upload MySQL → Supabase</h2>
<table>
    <tr><th>Table</th><th>MySQL Records</th><th>Uploaded</th><th>Status</th></tr>
    <?php
    $grand_total = 0;
    foreach ($results as $table => $res):
        $status  = $res['success'] ? '<span class="ok">✓ OK</span>' : '<span class="err">✗ ' . htmlspecialchars($res['error'] ?? 'Error') . '</span>';
        $mysql   = $res['mysql_count'] ?? 0;
        $uploaded = $res['count'] ?? 0;
        $grand_total += $uploaded;
        $note    = isset($res['note']) ? '<span class="warn"> (' . $res['note'] . ')</span>' : '';
    ?>
    <tr>
        <td><?= $table ?></td>
        <td><?= $mysql ?></td>
        <td><?= $uploaded ?><?= $note ?></td>
        <td><?= $status ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<div class="total">
    <strong>Total uploaded: <?= $grand_total ?> records</strong><br>
    <small class="warn">⚠ PENTING: Hapus file ini setelah selesai! Akses ke file ini tidak aman.</small>
</div>
</body>
</html>
