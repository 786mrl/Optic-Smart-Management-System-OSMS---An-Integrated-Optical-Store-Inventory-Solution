<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
ob_clean();

session_start();
$current_role = $_SESSION['role'] ?? 'staff';
$is_admin     = ($current_role === 'admin');

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

$tables = [
    'settings', 'users', 'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames', 'prescription_modifications'
];

$exclude_columns = ['users' => ['password_hash']];

$pk_map = [
    'customer_examinations'      => 'id',
    'customer_orders'            => 'id',
    'custom_frames'              => 'id',
    'frames_main'                => 'ufc',
    'frame_staging'              => 'ufc',
    'prescription_modifications' => 'modification_id',
    'users'                      => 'user_id',
    'settings'                   => 'setting_key',
];

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
    if (function_exists('http_get_last_response_headers')) { $http_response_header = http_get_last_response_headers() ?? []; }
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $status = intval($m[1] ?? 0);
    }
    return ['body' => $response, 'status' => $status];
}

function supabase_upsert($table, $data) {
    if (empty($data)) return ['success' => true, 'count' => 0, 'note' => 'empty'];
    $total = 0;
    foreach (array_chunk($data, 50) as $batch) {
        $res = supabase_request('/rest/v1/' . $table, 'POST', $batch);
        if ($res['status'] >= 200 && $res['status'] < 300) $total += count($batch);
        else return ['success' => false, 'error' => $res['body']];
    }
    return ['success' => true, 'count' => $total];
}

function check_supabase() {
    $res = supabase_request('/rest/v1/settings?limit=1', 'GET');
    return $res['status'] >= 200 && $res['status'] < 500;
}

// Handle hapus (admin only)
if ($is_admin && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $t = $_POST['table'] ?? '';
    $c = $_POST['id_col'] ?? '';
    $v = $_POST['id_val'] ?? '';
    $res = supabase_request("/rest/v1/{$t}?{$c}=eq." . urlencode($v), 'DELETE');
    echo json_encode(['success' => ($res['status'] >= 200 && $res['status'] < 300)]);
    exit();
}

$results    = [];
$table_rows = [];
$supabase_ok = check_supabase();

if ($supabase_ok) {
    foreach ($tables as $table) {
        $result = $conn->query("SELECT * FROM `$table`");
        if (!$result) { $results[$table] = ['success' => false, 'error' => $conn->error]; continue; }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (isset($exclude_columns[$table]))
                foreach ($exclude_columns[$table] as $col) unset($row[$col]);
            foreach ($row as $k => $v) if ($v === '') $row[$k] = null;
            $rows[] = $row;
        }
        $table_rows[$table] = $rows;
        $res = supabase_upsert($table, $rows);
        $results[$table] = array_merge($res, ['mysql_count' => count($rows)]);
    }
}
close_db_connection($conn);

// Staff: redirect langsung
if (!$is_admin) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync ke Supabase</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { padding: 16px; }
        .sync-wrap { max-width: 700px; margin: 0 auto; }
        .sync-title { font-size: 20px; font-weight: bold; margin-bottom: 4px; }
        .sync-sub { font-size: 13px; color: #888; margin-bottom: 20px; }
        .status-box { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; }
        .online  { background: rgba(126,255,160,0.1); color: #7effa0; border: 1px solid rgba(126,255,160,0.2); }
        .offline { background: rgba(255,107,107,0.1); color: #ff6b6b; border: 1px solid rgba(255,107,107,0.2); }

        /* Summary table */
        .summary-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 24px; }
        .summary-table th { color: #7eb8f7; font-weight: 600; padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .summary-table td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .ok   { color: #7effa0; }
        .err  { color: #ff6b6b; }
        .warn { color: #f6e05e; }
        .toggle-btn {
            background: none; border: 1px solid rgba(126,184,247,0.3);
            color: #7eb8f7; font-size: 11px; padding: 3px 10px;
            border-radius: 6px; cursor: pointer;
        }
        .toggle-btn:hover { background: rgba(126,184,247,0.1); }

        /* Cards section */
        .cards-section { display: none; margin-bottom: 24px; }
        .cards-section.open { display: block; }
        .section-label { font-size: 12px; color: #888; margin-bottom: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
        .record-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 14px;
            position: relative;
        }
        .record-card:hover { border-color: rgba(126,184,247,0.2); }
        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .card-id { font-size: 11px; color: #555; font-family: monospace; }
        .del-btn {
            background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.2);
            color: #ff6b6b; border-radius: 6px; padding: 4px 10px;
            font-size: 11px; cursor: pointer; flex-shrink: 0;
        }
        .del-btn:hover { background: rgba(255,107,107,0.25); }
        .card-fields { display: flex; flex-direction: column; gap: 6px; }
        .card-field { display: flex; gap: 8px; font-size: 12px; }
        .field-key { color: #7eb8f7; min-width: 110px; flex-shrink: 0; font-weight: 500; }
        .field-val { color: #ccc; word-break: break-all; }
        .field-val.null-val { color: #444; font-style: italic; }
        .empty-note { color: #555; font-size: 13px; font-style: italic; padding: 12px 0; }

        /* Total & back */
        .total-box { padding: 14px 16px; background: rgba(255,255,255,0.04); border-radius: 10px; margin-bottom: 20px; }
        .total-box strong { color: #7effa0; font-size: 15px; }
        .back-btn {
            display: inline-block; padding: 12px 24px;
            background: rgba(255,255,255,0.07); border-radius: 12px;
            color: #ccc; text-decoration: none; font-size: 14px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .back-btn:hover { background: rgba(255,255,255,0.12); }
    </style>
</head>
<body>
<div class="sync-wrap">
    <div class="sync-title">☁️ Sync ke Supabase</div>
    <div class="sync-sub">Sinkronisasi semua data ke cloud — <?= date('d/m/Y H:i:s') ?></div>

    <?php if (!$supabase_ok): ?>
    <div class="status-box offline">✗ Tidak bisa konek ke Supabase. Pastikan terhubung ke internet.</div>
    <?php else: ?>
    <div class="status-box online">✓ Supabase terjangkau — sync berhasil</div>

    <table class="summary-table">
        <tr><th>Tabel</th><th>Records</th><th>Uploaded</th><th>Status</th><th></th></tr>
        <?php
        $grand_total = 0;
        foreach ($results as $table => $res):
            $mysql    = $res['mysql_count'] ?? 0;
            $uploaded = $res['count'] ?? 0;
            $grand_total += $uploaded;
            $note   = isset($res['note']) ? ' <span class="warn">(kosong)</span>' : '';
            $status = $res['success'] ? '<span class="ok">✓ OK</span>' : '<span class="err">✗ Error</span>';
        ?>
        <tr>
            <td><?= $table ?></td>
            <td><?= $mysql ?></td>
            <td><?= $uploaded ?><?= $note ?></td>
            <td><?= $status ?></td>
            <td>
                <?php if ($mysql > 0): ?>
                <button class="toggle-btn" onclick="toggleCards('<?= $table ?>', this)">▼ Detail</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php foreach ($tables as $table):
        $rows = $table_rows[$table] ?? [];
        $pk   = $pk_map[$table] ?? 'id';
        if (empty($rows)) continue;
    ?>
    <div class="cards-section" id="cards-<?= $table ?>">
        <div class="section-label"><?= $table ?> — <?= count($rows) ?> records</div>
        <div class="cards-grid">
        <?php foreach ($rows as $row):
            $pk_val = $row[$pk] ?? '';
        ?>
            <div class="record-card" id="card-<?= $table ?>-<?= htmlspecialchars($pk_val) ?>">
                <div class="card-header">
                    <span class="card-id"><?= $pk ?>: <?= htmlspecialchars($pk_val) ?></span>
                    <button class="del-btn" onclick="deleteRecord('<?= $table ?>', '<?= $pk ?>', '<?= htmlspecialchars($pk_val) ?>')">🗑 Hapus</button>
                </div>
                <div class="card-fields">
                <?php foreach ($row as $key => $val):
                    if ($key === $pk) continue;
                ?>
                    <div class="card-field">
                        <span class="field-key"><?= htmlspecialchars($key) ?></span>
                        <span class="field-val <?= $val === null ? 'null-val' : '' ?>">
                            <?= $val === null ? '(null)' : htmlspecialchars($val) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="total-box">
        <strong>Total: <?= $grand_total ?> records diupload</strong>
    </div>
    <?php endif; ?>

    <a href="index.php" class="back-btn">← Kembali</a>
</div>

<script>
function toggleCards(table, btn) {
    const section = document.getElementById('cards-' + table);
    if (section.classList.contains('open')) {
        section.classList.remove('open');
        btn.textContent = '▼ Detail';
    } else {
        section.classList.add('open');
        btn.textContent = '▲ Tutup';
    }
}

function deleteRecord(table, idCol, idVal) {
    if (!confirm('Hapus record "' + idVal + '" dari Supabase?')) return;
    fetch('sync_to_supabase.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&table=' + table + '&id_col=' + idCol + '&id_val=' + encodeURIComponent(idVal)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const card = document.getElementById('card-' + table + '-' + idVal);
            if (card) {
                card.style.opacity = '0.3';
                card.style.pointerEvents = 'none';
                setTimeout(() => card.remove(), 400);
            }
        } else {
            alert('Gagal hapus dari Supabase!');
        }
    });
}
</script>
</body>
</html>