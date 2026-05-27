<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
include 'config_helper.php';
ob_clean();

session_start();
$current_role = $_SESSION['role'] ?? 'staff';
$is_admin     = ($current_role === 'admin');

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

$tables = [
    'settings', 'users', 'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames',
    'prescription_modifications'
    // deletion_queue intentionally excluded from sync
];

$exclude_columns = ['users' => ['password_hash']];

// Auto-create deletion_queue if not exists
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
    $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    if (!empty($hdrs)) { preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0], $m); $status = intval($m[1] ?? 0); }
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

// Handle delete (admin only) — add to deletion queue
if ($is_admin && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $t   = $conn->real_escape_string($_POST['table'] ?? '');
    $c   = $conn->real_escape_string($_POST['id_col'] ?? '');
    $v   = $conn->real_escape_string($_POST['id_val'] ?? '');
    $by  = $conn->real_escape_string($_SESSION['username'] ?? 'admin');
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    // Get ALL approved usernames (not just count) for accurate check
    $ur = $conn->query("SELECT username FROM users WHERE is_approved = 1");
    $all_users = [];
    while ($row = $ur->fetch_assoc()) $all_users[] = $row['username'];
    $total_users = count($all_users);

    // Check if already in queue
    $existing = $conn->query("SELECT id FROM deletion_queue
        WHERE target_table='$t' AND target_id_col='$c' AND target_id_val='$v'");
    if ($existing && $existing->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Already in deletion queue']);
        exit();
    }

    // First confirmer is the current admin user
    $confirmed_list = json_encode([$by]);

    // Add to local MySQL deletion_queue
    $conn->query("INSERT INTO deletion_queue
        (target_table, target_id_col, target_id_val, total_users, confirmed_count, confirmed_by, deleted_by, expires_at)
        VALUES ('$t', '$c', '$v', $total_users, 1, '$confirmed_list', '$by', '$expires')");

    // Add to Supabase deletion_queue
    supabase_request('/rest/v1/deletion_queue', 'POST', [[
        'target_table'    => $t,
        'target_id_col'   => $c,
        'target_id_val'   => $v,
        'total_users'     => $total_users,
        'confirmed_count' => 1,
        'confirmed_by'    => $confirmed_list,
        'deleted_by'      => $by,
        'expires_at'      => $expires
    ]]);

    // Delete from local MySQL immediately (this user confirmed)
    $conn->query("DELETE FROM `$t` WHERE `$c` = '$v'");

    // If only 1 user total → delete from Supabase immediately, clean queue
    if ($total_users <= 1) {
        supabase_request("/rest/v1/{$t}?{$c}=eq." . urlencode($v), 'DELETE');
        supabase_request(
            '/rest/v1/deletion_queue?target_table=eq.' . urlencode($t) . '&target_id_val=eq.' . urlencode($v),
            'DELETE'
        );
        $conn->query("DELETE FROM deletion_queue WHERE target_table='$t' AND target_id_val='$v'");
        echo json_encode(['success' => true, 'message' => 'Deleted immediately (single user)']);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Queued for deletion — waiting for ' . ($total_users - 1) . ' other user(s) to confirm'
        ]);
    }
    exit();
}

$results     = [];
$table_rows  = [];
$supabase_ok = check_supabase();

// Get pending deletions from local queue
$pending_deletions = [];
$pq = $conn->query("SELECT * FROM deletion_queue WHERE expires_at > NOW() ORDER BY created_at DESC");
if ($pq) while ($row = $pq->fetch_assoc()) $pending_deletions[] = $row;

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
    <?php include 'pwa_head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloud Sync — Supabase</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Status badge */
        .sync-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            box-shadow: inset 2px 2px 5px rgba(0,0,0,0.3);
        }
        .sync-online  { background: #1a2c1e; color: #00ff88; border: 1px solid rgba(0,255,136,0.15); }
        .sync-offline { background: #2c1a1a; color: #ff6b6b; border: 1px solid rgba(255,107,107,0.15); }
        .status-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 8px currentColor;
            flex-shrink: 0;
        }

        /* Summary table */
        .sync-table-wrap { overflow-x: auto; margin-bottom: 20px; }
        table.sync-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 420px; }
        .sync-table th {
            padding: 10px 14px; text-align: left;
            color: #666; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .sync-table td {
            padding: 11px 14px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: #ccc; font-size: 13px;
        }
        .sync-table tr:last-child td { border-bottom: none; }
        .sync-table tr:hover td { background: rgba(255,255,255,0.02); }
        .badge-ok    { color: #00ff88; font-weight: 700; }
        .badge-err   { color: #ff6b6b; font-weight: 700; }
        .badge-empty { color: #444; font-style: italic; font-size: 12px; }
        .total-line  { padding: 12px 14px; font-size: 13px; color: #888; border-top: 1px solid rgba(255,255,255,0.06); }
        .total-line span { color: #00ff88; font-weight: 700; font-size: 15px; }

        .view-btn {
            background: #1e2022;
            border: none; border-radius: 8px;
            padding: 5px 12px; color: #00ccff;
            font-size: 11px; cursor: pointer;
            box-shadow: 2px 2px 5px #1a1c1d, -1px -1px 3px #2e3234;
            white-space: nowrap; transition: all 0.15s;
        }
        .view-btn:hover { box-shadow: inset 2px 2px 4px #1a1c1d; }

        /* Records section */
        .records-section { display: none; margin-bottom: 24px; }
        .records-section.open { display: block; }

        .section-title {
            font-size: 11px; font-weight: 700; color: #00ccff;
            text-transform: uppercase; letter-spacing: 0.8px;
            padding: 10px 0 10px;
            border-bottom: 1px solid rgba(0,204,255,0.1);
            margin-bottom: 14px;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 14px;
        }

        .record-card {
            background: #25282a;
            border-radius: 14px;
            padding: 16px;
            box-shadow: 4px 4px 9px #1a1c1d, -2px -2px 6px #2e3234;
            transition: box-shadow 0.2s;
        }
        .record-card:hover { box-shadow: 6px 6px 12px #1a1c1d, -3px -3px 8px #2e3234; }

        .card-top {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .card-pk {
            font-size: 11px; color: #555; font-family: monospace;
            background: #1e2022; padding: 3px 8px;
            border-radius: 6px; box-shadow: inset 1px 1px 3px #1a1c1d;
        }
        .delete-btn {
            background: #2c1a1a; border: 1px solid rgba(255,107,107,0.2);
            border-radius: 8px; padding: 5px 10px;
            color: #ff6b6b; font-size: 11px; cursor: pointer;
            box-shadow: 2px 2px 5px #1a1c1d; transition: all 0.15s;
        }
        .delete-btn:hover { background: #3a1f1f; box-shadow: inset 2px 2px 4px #1a1c1d; }

        .card-fields { display: flex; flex-direction: column; gap: 7px; }
        .card-field  { display: flex; gap: 10px; align-items: flex-start; }
        .field-key   {
            min-width: 110px; flex-shrink: 0;
            font-size: 11px; color: #00ccff;
            font-weight: 600; padding-top: 1px; opacity: 0.7;
        }
        .field-val { font-size: 12px; color: #ccc; word-break: break-word; line-height: 1.4; }
        .field-val.is-null { color: #3a3a3a; font-style: italic; font-size: 11px; }
    </style>
</head>
<body>
<div class="main-wrapper">
    <div class="content-area" style="flex-direction: column">

        <!-- Header — same as customer_prescription.php -->
        <div class="header-container" style="margin-left: auto; margin-right: auto; width: 100%;">
            <button class="logout-btn" onclick="window.location.href='logout.php';">
                <span>Logout</span>
            </button>
            <div class="brand-section">
                <div class="logo-box">
                    <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;">
                </div>
                <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
            </div>
        </div>

        <!-- Main Card -->
        <div class="main-card" style="margin-left: auto; margin-right: auto; width: 100%;">
            <h2>CLOUD SYNC — SUPABASE</h2>
            <p style="color: #666; font-size: 12px; margin-bottom: 20px;">
                Synchronized on <?= date('d M Y, H:i:s') ?>
            </p>

            <!-- Status -->
            <?php if (!$supabase_ok): ?>
            <div class="sync-status sync-offline">
                <span class="status-dot"></span>
                Supabase unreachable — check your internet connection
            </div>
            <?php else: ?>
            <div class="sync-status sync-online">
                <span class="status-dot"></span>
                Connected to Supabase — sync completed successfully
            </div>

            <!-- Pending Deletions -->
            <?php if (!empty($pending_deletions)): ?>
            <div style="margin-bottom: 20px;">
                <div style="font-size:11px; font-weight:700; color:#f6a623; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px;">
                    ⏳ Pending Deletions — <?= count($pending_deletions) ?> item(s) waiting
                </div>
                <?php foreach ($pending_deletions as $pd):
                    $confirmed = json_decode($pd['confirmed_by'] ?? '[]', true);
                    if (!is_array($confirmed)) $confirmed = [];
                    $waiting = $pd['total_users'] - count($confirmed);
                ?>
                <div style="background:#2a2200; border:1px solid rgba(246,166,35,0.2); border-radius:10px; padding:12px 14px; margin-bottom:8px; font-size:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                        <div>
                            <span style="color:#f6a623; font-weight:600;"><?= htmlspecialchars($pd['target_table']) ?></span>
                            <span style="color:#666; margin:0 6px;">→</span>
                            <span style="color:#ccc; font-family:monospace;"><?= htmlspecialchars($pd['target_id_val']) ?></span>
                        </div>
                        <div style="color:#888; font-size:11px;">
                            Deleted by: <span style="color:#ccc;"><?= htmlspecialchars($pd['deleted_by']) ?></span>
                        </div>
                    </div>
                    <div style="margin-top:8px; display:flex; gap:12px; flex-wrap:wrap;">
                        <span style="color:#888; font-size:11px;">
                            Confirmed: <span style="color:#00ff88;"><?= count($confirmed) ?>/<?= $pd['total_users'] ?></span>
                            (<?= implode(', ', array_map('htmlspecialchars', $confirmed)) ?>)
                        </span>
                        <span style="color:#888; font-size:11px;">
                            Waiting: <span style="color:#f6a623;"><?= $waiting ?> user(s)</span>
                        </span>
                        <span style="color:#888; font-size:11px;">
                            Expires: <?= date('d M Y', strtotime($pd['expires_at'])) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Summary Table -->
            <div class="sync-table-wrap">
                <table class="sync-table">
                    <tr>
                        <th>Table</th>
                        <th>Local</th>
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
                            <button class="view-btn" onclick="toggleRecords('<?= $table ?>', this)">▼ View</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <div class="total-line">
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
                <div class="section-title"><?= $table ?> — <?= count($rows) ?> records</div>
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

        <!-- Back button outside card, full width -->
        <div style="padding: 0 16px 16px;">
            <button type="button" class="back-main" style="width: 100%;" onclick="window.location.href='index.php'">
                BACK TO MAIN MENU
            </button>
        </div>

        <!-- Footer -->
        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>

    </div>
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
    if (!confirm('Queue "' + idVal + '" for deletion?\n\nThis will be removed from all devices when all users have logged in.')) return;
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
                card.style.opacity = '0.3';
                card.style.transform = 'scale(0.97)';
                // Show pending indicator on card
                card.style.border = '1px solid rgba(246,166,35,0.4)';
                const msg = document.createElement('div');
                msg.style.cssText = 'margin-top:10px; padding:6px 10px; background:rgba(246,166,35,0.1); border-radius:6px; font-size:11px; color:#f6a623;';
                msg.textContent = '⏳ ' + (data.message || 'Queued for deletion');
                card.appendChild(msg);
            }
            // Reload after 1.5s to show updated pending list
            setTimeout(() => location.reload(), 1500);
        } else {
            alert('Error: ' + (data.message || 'Failed to queue deletion'));
        }
    });
}
</script>
</body>
</html>