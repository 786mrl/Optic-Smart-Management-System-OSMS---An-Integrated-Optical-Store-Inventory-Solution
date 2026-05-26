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
    $headers_arr = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    if (!empty($headers_arr)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $headers_arr[0], $m);
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

// Handle delete (admin only)
if ($is_admin && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $t = $_POST['table'] ?? '';
    $c = $_POST['id_col'] ?? '';
    $v = $_POST['id_val'] ?? '';
    $res = supabase_request("/rest/v1/{$t}?{$c}=eq." . urlencode($v), 'DELETE');
    echo json_encode(['success' => ($res['status'] >= 200 && $res['status'] < 300)]);
    exit();
}

$results     = [];
$table_rows  = [];
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

if (!$is_admin) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync to Supabase</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #1e2022; min-height: 100vh; padding: 0; margin: 0; }

        .page-wrapper {
            max-width: 780px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        /* Header */
        .page-header {
            background: #25282a;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 5px 5px 10px #1a1c1d, -3px -3px 8px #2e3234;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-title { font-size: 18px; font-weight: 700; color: #e0e0e0; letter-spacing: 0.3px; }
        .page-sub   { font-size: 12px; color: #666; margin-top: 2px; }
        .back-btn {
            background: #1e2022;
            border: none;
            border-radius: 10px;
            padding: 10px 18px;
            color: #aaa;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 3px 3px 7px #1a1c1d, -2px -2px 5px #2e3234;
            transition: all 0.2s;
        }
        .back-btn:hover { box-shadow: inset 2px 2px 5px #1a1c1d; color: #ccc; }

        /* Status badge */
        .status-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            box-shadow: inset 2px 2px 5px rgba(0,0,0,0.3);
        }
        .status-online  { background: #1a2c1e; color: #00ff88; border: 1px solid rgba(0,255,136,0.15); }
        .status-offline { background: #2c1a1a; color: #ff6b6b; border: 1px solid rgba(255,107,107,0.15); }
        .status-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 8px currentColor;
            flex-shrink: 0;
        }

        /* Summary table card */
        .summary-card {
            background: #25282a;
            border-radius: 16px;
            padding: 0;
            margin-bottom: 24px;
            box-shadow: 5px 5px 10px #1a1c1d, -3px -3px 8px #2e3234;
            overflow: hidden;
        }
        .summary-card-header {
            padding: 14px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 12px;
            font-weight: 700;
            color: #00ccff;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        table.sync-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .sync-table th {
            padding: 10px 16px;
            text-align: left;
            color: #666;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        .sync-table td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            color: #ccc;
        }
        .sync-table tr:last-child td { border-bottom: none; }
        .sync-table tr:hover td { background: rgba(255,255,255,0.02); }
        .badge-ok  { color: #00ff88; font-weight: 600; }
        .badge-err { color: #ff6b6b; font-weight: 600; }
        .badge-empty { color: #444; font-style: italic; font-size: 12px; }

        .detail-btn {
            background: #1e2022;
            border: none;
            border-radius: 8px;
            padding: 5px 12px;
            color: #00ccff;
            font-size: 11px;
            cursor: pointer;
            box-shadow: 2px 2px 5px #1a1c1d, -1px -1px 3px #2e3234;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .detail-btn:hover { box-shadow: inset 2px 2px 4px #1a1c1d; }

        /* Total row */
        .total-row { padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.06); }
        .total-row span { color: #00ff88; font-weight: 700; font-size: 14px; }

        /* Records section */
        .records-section { display: none; margin-bottom: 20px; }
        .records-section.open { display: block; }

        .section-header {
            background: #25282a;
            border-radius: 12px 12px 0 0;
            padding: 12px 18px;
            font-size: 12px;
            font-weight: 700;
            color: #00ccff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 5px 0 10px #1a1c1d, -3px 0 8px #2e3234;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 14px;
            padding: 14px 0;
        }

        .record-card {
            background: #25282a;
            border-radius: 14px;
            padding: 16px;
            box-shadow: 4px 4px 9px #1a1c1d, -2px -2px 6px #2e3234;
            transition: box-shadow 0.2s;
            position: relative;
        }
        .record-card:hover { box-shadow: 6px 6px 12px #1a1c1d, -3px -3px 8px #2e3234; }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .card-pk {
            font-size: 11px;
            color: #555;
            font-family: monospace;
            background: #1e2022;
            padding: 3px 8px;
            border-radius: 6px;
            box-shadow: inset 1px 1px 3px #1a1c1d;
        }

        .delete-btn {
            background: #2c1a1a;
            border: 1px solid rgba(255,107,107,0.2);
            border-radius: 8px;
            padding: 5px 10px;
            color: #ff6b6b;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.15s;
            box-shadow: 2px 2px 5px #1a1c1d;
        }
        .delete-btn:hover { background: #3a1f1f; box-shadow: inset 2px 2px 4px #1a1c1d; }

        .card-fields { display: flex; flex-direction: column; gap: 7px; }
        .card-field  { display: flex; gap: 10px; align-items: flex-start; }
        .field-key   {
            min-width: 120px;
            flex-shrink: 0;
            font-size: 11px;
            color: #00ccff;
            font-weight: 600;
            padding-top: 1px;
            opacity: 0.7;
        }
        .field-val {
            font-size: 12px;
            color: #ccc;
            word-break: break-word;
            line-height: 1.4;
        }
        .field-val.is-null { color: #3a3a3a; font-style: italic; font-size: 11px; }

        .empty-state {
            padding: 20px;
            text-align: center;
            color: #444;
            font-size: 13px;
            font-style: italic;
        }
    </style>
</head>
<body>
<div class="page-wrapper">

    <!-- Header -->
    <div class="page-header">
        <div>
            <div class="page-title">☁ Cloud Sync — Supabase</div>
            <div class="page-sub">Synchronized on <?= date('d M Y, H:i:s') ?></div>
        </div>
        <a href="index.php" class="back-btn">← Back to Menu</a>
    </div>

    <!-- Status -->
    <?php if (!$supabase_ok): ?>
    <div class="status-badge status-offline">
        <span class="status-dot"></span>
        Supabase unreachable — check your internet connection
    </div>
    <?php else: ?>
    <div class="status-badge status-online">
        <span class="status-dot"></span>
        Connected to Supabase — sync completed successfully
    </div>

    <!-- Summary Table -->
    <div class="summary-card">
        <div class="summary-card-header">Sync Summary</div>
        <table class="sync-table">
            <tr>
                <th>Table</th>
                <th>Local Records</th>
                <th>Uploaded</th>
                <th>Status</th>
                <th></th>
            </tr>
            <?php
            $grand_total = 0;
            foreach ($results as $table => $res):
                $mysql    = $res['mysql_count'] ?? 0;
                $uploaded = $res['count'] ?? 0;
                $grand_total += $uploaded;
                $is_empty = isset($res['note']) && $res['note'] === 'empty';
                $status_html = $res['success']
                    ? '<span class="badge-ok">✓ OK</span>'
                    : '<span class="badge-err">✗ Error</span>';
                $uploaded_html = $is_empty
                    ? '<span class="badge-empty">— empty</span>'
                    : $uploaded;
            ?>
            <tr>
                <td><?= $table ?></td>
                <td><?= $mysql ?></td>
                <td><?= $uploaded_html ?></td>
                <td><?= $status_html ?></td>
                <td>
                    <?php if ($mysql > 0): ?>
                    <button class="detail-btn" onclick="toggleRecords('<?= $table ?>', this)">▼ View</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div class="total-row">
            Total uploaded: <span><?= $grand_total ?> records</span>
        </div>
    </div>

    <!-- Record Cards per Table -->
    <?php foreach ($tables as $table):
        $rows = $table_rows[$table] ?? [];
        $pk   = $pk_map[$table] ?? 'id';
        if (empty($rows)) continue;
    ?>
    <div class="records-section" id="records-<?= $table ?>">
        <div class="section-header"><?= $table ?> — <?= count($rows) ?> records</div>
        <div class="cards-grid">
        <?php foreach ($rows as $row):
            $pk_val = $row[$pk] ?? '';
        ?>
            <div class="record-card" id="card-<?= $table ?>-<?= htmlspecialchars($pk_val) ?>">
                <div class="card-top">
                    <span class="card-pk"><?= $pk ?>: <?= htmlspecialchars($pk_val) ?></span>
                    <button class="delete-btn"
                        onclick="deleteRecord('<?= $table ?>', '<?= $pk ?>', '<?= htmlspecialchars($pk_val) ?>')">
                        🗑 Delete
                    </button>
                </div>
                <div class="card-fields">
                <?php foreach ($row as $key => $val):
                    if ($key === $pk) continue;
                ?>
                    <div class="card-field">
                        <span class="field-key"><?= htmlspecialchars($key) ?></span>
                        <span class="field-val <?= $val === null ? 'is-null' : '' ?>">
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

    <?php endif; ?>
</div>

<script>
function toggleRecords(table, btn) {
    const sec = document.getElementById('records-' + table);
    if (sec.classList.contains('open')) {
        sec.classList.remove('open');
        btn.textContent = '▼ View';
    } else {
        sec.classList.add('open');
        btn.textContent = '▲ Hide';
        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function deleteRecord(table, idCol, idVal) {
    if (!confirm('Delete "' + idVal + '" from Supabase?\nThis cannot be undone.')) return;
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
                card.style.transition = 'opacity 0.3s, transform 0.3s';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.95)';
                setTimeout(() => card.remove(), 300);
            }
        } else {
            alert('Failed to delete from Supabase.');
        }
    });
}
</script>
</body>
</html>