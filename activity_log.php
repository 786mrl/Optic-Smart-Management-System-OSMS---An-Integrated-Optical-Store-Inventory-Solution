<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'db_config.php';
include 'config_helper.php';
ob_clean();

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

define('SUPABASE_URL', 'https://rnuyhoxlmpivkoxwyxln.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs');

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

$page_map = [
    'frames_main'                => 'frame_data_entry.php',
    'frame_staging'              => 'frame_data_entry.php',
    'customer_examinations'      => 'customer_prescription.php',
    'customer_orders'            => 'invoice.php',
    'custom_frames'              => 'custom_frame_save.php',
    'prescription_modifications' => 'customer_prescription.php',
    'settings'                   => 'manage_settings.php',
    'users'                      => 'manage_roles.php',
];

$sync_tables = [
    'settings', 'users', 'frames_main', 'frame_staging',
    'customer_examinations', 'customer_orders', 'custom_frames',
    'prescription_modifications'
];

$exclude_columns = ['users' => ['password_hash']];

function supabase_req($path, $method, $body = null) {
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
    $hdrs = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if (!empty($hdrs)) { preg_match('/HTTP\/\S+\s+(\d+)/', $hdrs[0], $m); $status = intval($m[1] ?? 0); }
    return ['body' => $response, 'status' => $status];
}

function check_supabase() {
    $res = supabase_req('/rest/v1/settings?limit=1', 'GET');
    return $res['status'] >= 200 && $res['status'] < 500;
}

// ── Handle dismiss log entry ──────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'dismiss') {
    $log_id = (int)($_POST['log_id'] ?? 0);
    if ($log_id > 0) {
        $conn->query("UPDATE activity_log SET sync_flag = 0 WHERE id = $log_id");
        $conn->query("DELETE FROM sync_status WHERE log_id = $log_id");
        supabase_req('/rest/v1/activity_log?id=eq.' . $log_id, 'PATCH', ['sync_flag' => 0]);
        supabase_req('/rest/v1/sync_status?log_id=eq.' . $log_id, 'DELETE');
        supabase_req('/rest/v1/activity_log?id=eq.' . $log_id, 'DELETE');
        echo json_encode(['success' => true]);
        exit();
    }
}

// ── Handle manual sync ────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'manual_sync') {
    $supabase_ok = check_supabase();
    $synced = 0;
    if ($supabase_ok) {
        foreach ($sync_tables as $table) {
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
                $res = supabase_req('/rest/v1/' . $table, 'POST', $batch);
                if ($res['status'] >= 200 && $res['status'] < 300) $synced += count($batch);
            }
        }
        echo json_encode(['success' => true, 'synced' => $synced]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Supabase unreachable']);
    }
    exit();
}

// ── Handle delete record (via activity_helper logic) ─────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_record') {
    include_once 'activity_helper.php';
    $t  = $conn->real_escape_string($_POST['table'] ?? '');
    $c  = $conn->real_escape_string($_POST['id_col'] ?? '');
    $v  = $conn->real_escape_string($_POST['id_val'] ?? '');
    $by = $_SESSION['username'] ?? 'admin';

    $conn->query("DELETE FROM `$t` WHERE `$c` = '$v'");
    log_activity($conn, $t, $v, 'DELETE', $by);

    echo json_encode(['success' => true]);
    exit();
}

// ── Get activity logs ─────────────────────────────────────────
$logs = [];
$result = $conn->query("SELECT * FROM activity_log ORDER BY changed_at DESC LIMIT 200");
if ($result) while ($row = $result->fetch_assoc()) $logs[] = $row;

// ── Get sync status per log ───────────────────────────────────
$sync_map = [];
$ss = $conn->query("SELECT log_id, username FROM sync_status");
if ($ss) while ($row = $ss->fetch_assoc()) $sync_map[$row['log_id']][] = $row['username'];

// ── Get total approved users ──────────────────────────────────
$ur = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_approved = 1");
$total_users = $ur ? (int)$ur->fetch_assoc()['total'] : 1;

// ── Get table data for view ───────────────────────────────────
$table_rows = [];
$supabase_ok = check_supabase();
if ($supabase_ok) {
    foreach ($sync_tables as $table) {
        $result = $conn->query("SELECT * FROM `$table`");
        if (!$result) continue;
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (isset($exclude_columns[$table]))
                foreach ($exclude_columns[$table] as $col) unset($row[$col]);
            $rows[] = $row;
        }
        $table_rows[$table] = $rows;
    }
}

close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log & Sync</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { padding: 0; }

        /* Tabs */
        .tab-bar { display: flex; gap: 4px; margin-bottom: 20px; background: #1e2022; border-radius: 12px; padding: 4px; }
        .tab-btn {
            flex: 1; padding: 10px; border: none; border-radius: 9px;
            background: transparent; color: #666; font-size: 13px;
            font-weight: 600; cursor: pointer; transition: all 0.2s;
        }
        .tab-btn.active { background: #25282a; color: #00ccff; box-shadow: 2px 2px 6px #1a1c1d; }

        /* Manual sync button */
        .sync-now-btn {
            background: #1a2c1e; border: 1px solid rgba(0,255,136,0.2);
            border-radius: 10px; padding: 10px 20px; color: #00ff88;
            font-size: 13px; font-weight: 600; cursor: pointer;
            width: 100%; margin-bottom: 16px; transition: all 0.2s;
        }
        .sync-now-btn:hover { background: rgba(0,255,136,0.1); }
        .sync-now-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Status badge */
        .status-badge {
            display: flex; align-items: center; gap: 8px;
            padding: 12px 16px; border-radius: 12px;
            font-size: 13px; font-weight: 600; margin-bottom: 16px;
            box-shadow: inset 2px 2px 5px rgba(0,0,0,0.3);
        }
        .status-online  { background: #1a2c1e; color: #00ff88; border: 1px solid rgba(0,255,136,0.15); }
        .status-offline { background: #2c1a1a; color: #ff6b6b; border: 1px solid rgba(255,107,107,0.15); }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; box-shadow: 0 0 8px currentColor; }

        /* Sync summary table */
        .sync-table-wrap { overflow-x: auto; margin-bottom: 16px; }
        table.sync-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 380px; }
        .sync-table th { padding: 8px 12px; text-align: left; color: #555; font-size: 11px; font-weight: 700; text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .sync-table td { padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); color: #ccc; }
        .sync-table tr:last-child td { border-bottom: none; }
        .badge-ok    { color: #00ff88; font-weight: 700; }
        .badge-err   { color: #ff6b6b; font-weight: 700; }
        .badge-empty { color: #444; font-style: italic; font-size: 11px; }
        .view-btn {
            background: #1e2022; border: none; border-radius: 6px;
            padding: 4px 10px; color: #00ccff; font-size: 11px; cursor: pointer;
            box-shadow: 2px 2px 4px #1a1c1d;
        }

        /* Record cards */
        .records-section { display: none; margin-bottom: 20px; }
        .records-section.open { display: block; }
        .section-title { font-size: 11px; font-weight: 700; color: #00ccff; text-transform: uppercase; letter-spacing: 0.8px; padding: 10px 0; border-bottom: 1px solid rgba(0,204,255,0.1); margin-bottom: 12px; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
        .record-card { background: #25282a; border-radius: 12px; padding: 14px; box-shadow: 4px 4px 9px #1a1c1d, -2px -2px 6px #2e3234; }
        .card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .card-pk { font-size: 11px; color: #555; font-family: monospace; background: #1e2022; padding: 2px 8px; border-radius: 5px; }
        .delete-btn { background: #2c1a1a; border: 1px solid rgba(255,107,107,0.2); border-radius: 6px; padding: 4px 10px; color: #ff6b6b; font-size: 11px; cursor: pointer; }
        .card-fields { display: flex; flex-direction: column; gap: 6px; }
        .card-field { display: flex; gap: 8px; }
        .field-key { min-width: 100px; flex-shrink: 0; font-size: 11px; color: #00ccff; font-weight: 600; opacity: 0.7; }
        .field-val { font-size: 11px; color: #ccc; word-break: break-word; }
        .field-val.is-null { color: #3a3a3a; font-style: italic; }

        /* Activity log */
        .log-card { background: #25282a; border-radius: 12px; padding: 14px; margin-bottom: 10px; box-shadow: 4px 4px 9px #1a1c1d, -2px -2px 6px #2e3234; }
        .log-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
        .log-action { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .action-INSERT { background: rgba(0,255,136,0.1); color: #00ff88; border: 1px solid rgba(0,255,136,0.2); }
        .action-UPDATE { background: rgba(0,204,255,0.1); color: #00ccff; border: 1px solid rgba(0,204,255,0.2); }
        .action-DELETE { background: rgba(255,107,107,0.1); color: #ff6b6b; border: 1px solid rgba(255,107,107,0.2); }
        .log-meta { font-size: 12px; color: #888; }
        .log-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-view-page { background: #1e2022; border: 1px solid rgba(0,204,255,0.2); border-radius: 7px; padding: 4px 10px; color: #00ccff; font-size: 11px; cursor: pointer; text-decoration: none; }
        .btn-dismiss { background: #1e2022; border: 1px solid rgba(246,166,35,0.2); border-radius: 7px; padding: 4px 10px; color: #f6a623; font-size: 11px; cursor: pointer; }
        .sync-bar { margin-top: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .sync-progress { flex: 1; height: 3px; background: #1e2022; border-radius: 2px; min-width: 60px; }
        .sync-fill { height: 100%; border-radius: 2px; background: #00ff88; }
        .sync-text { font-size: 11px; color: #888; }
        .sync-text span { color: #00ff88; }
        .pending-badge { background: rgba(246,166,35,0.15); color: #f6a623; border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 600; margin-left: 8px; }
        .filter-bar { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; width: 100%; }
        .filter-btn { background: #1e2022; border: 1px solid rgba(255,255,255,0.07); border-radius: 7px; padding: 5px 12px; color: #666; font-size: 12px; cursor: pointer; flex: 1; text-align: center; }
        .filter-btn.active { border-color: rgba(0,204,255,0.3); color: #00ccff; background: rgba(0,204,255,0.05); }
        .empty-state { text-align: center; padding: 40px; color: #444; font-size: 14px; }
        .total-line { padding: 10px 12px; font-size: 13px; color: #888; border-top: 1px solid rgba(255,255,255,0.06); }
        .total-line span { color: #00ff88; font-weight: 700; }
    </style>
</head>
<body>
<div class="main-wrapper">
    <div class="content-area" style="flex-direction:column">

        <div class="header-container" style="margin-left:auto; margin-right:auto; width:100%;">
            <button class="logout-btn" onclick="window.location.href='logout.php';"><span>Logout</span></button>
            <div class="brand-section">
                <div class="logo-box">
                    <img src="<?= htmlspecialchars($BRAND_IMAGE_PATH) ?>" alt="Brand Logo" style="height:40px;">
                </div>
                <h1 class="company-name"><?= htmlspecialchars($STORE_NAME) ?></h1>
                <p class="company-address"><?= htmlspecialchars($STORE_ADDRESS) ?></p>
            </div>
        </div>

        <div class="main-card" style="width:100%; box-sizing:border-box;">
            <h2>
                ACTIVITY LOG & SYNC
                <?php $pending = count(array_filter($logs, function($l) { return $l['sync_flag'] == 1; })); ?>
                <?php if ($pending > 0): ?>
                <span class="pending-badge">⏳ <?= $pending ?> pending</span>
                <?php endif; ?>
            </h2>

            <!-- Tabs -->
            <div class="tab-bar">
                <button class="tab-btn active" onclick="switchTab('activity', this)">📋 Activity Log</button>
                <button class="tab-btn" onclick="switchTab('sync', this)">☁️ Cloud Sync</button>
            </div>

            <!-- ── TAB: ACTIVITY LOG ── -->
            <div id="tab-activity">
                <div class="filter-bar">
                    <button class="filter-btn active" onclick="filterLogs('all', this)">All</button>
                    <button class="filter-btn" onclick="filterLogs('INSERT', this)">Insert</button>
                    <button class="filter-btn" onclick="filterLogs('UPDATE', this)">Update</button>
                    <button class="filter-btn" onclick="filterLogs('DELETE', this)">Delete</button>
                    <button class="filter-btn" onclick="filterLogs('pending', this)">Pending</button>
                </div>

                <?php if (empty($logs)): ?>
                <div class="empty-state">✓ No activity recorded yet</div>
                <?php else: ?>
                <div id="log-list">
                <?php foreach ($logs as $log):
                    $log_id    = $log['id'];
                    $applied   = $sync_map[$log_id] ?? [];
                    $pct       = $total_users > 0 ? min(100, round(count($applied) / $total_users * 100)) : 0;
                    $page_url  = $page_map[$log['table_name']] ?? '#';
                    $is_pending = $log['sync_flag'] == 1;
                ?>
                <div class="log-card" data-action="<?= $log['action'] ?>" data-pending="<?= $is_pending ? '1' : '0' ?>">
                    <div class="log-header">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span class="log-action action-<?= $log['action'] ?>"><?= $log['action'] ?></span>
                            <span style="color:#ccc; font-size:13px; font-weight:600;"><?= htmlspecialchars($log['table_name']) ?></span>
                            <span style="color:#555; font-size:11px; font-family:monospace;">ID: <?= htmlspecialchars($log['record_id']) ?></span>
                        </div>
                        <div class="log-actions">
                            <?php if ($page_url !== '#'): ?>
                            <a href="<?= $page_url ?>" class="btn-view-page">→ View</a>
                            <?php endif; ?>
                            <?php if ($is_pending): ?>
                            <button class="btn-dismiss" onclick="dismissLog(<?= $log_id ?>, this)">✕ Dismiss</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="log-meta">
                        By: <span style="color:#ccc;"><?= htmlspecialchars($log['changed_by']) ?></span>
                        &nbsp;·&nbsp; <?= date('d M Y, H:i', strtotime($log['changed_at'])) ?>
                        &nbsp;·&nbsp; <?= $is_pending ? '<span style="color:#f6a623">Syncing</span>' : '<span style="color:#00ff88">Done</span>' ?>
                    </div>
                    <?php if ($is_pending): ?>
                    <div class="sync-bar">
                        <div class="sync-progress"><div class="sync-fill" style="width:<?= $pct ?>%"></div></div>
                        <span class="sync-text"><span><?= count($applied) ?>/<?= $total_users ?></span> synced
                        <?php if (!empty($applied)): ?>(<?= implode(', ', array_map('htmlspecialchars', $applied)) ?>)<?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── TAB: CLOUD SYNC ── -->
            <div id="tab-sync" style="display:none;">

                <!-- Manual Sync Button -->
                <button class="sync-now-btn" id="syncNowBtn" onclick="manualSync()">
                    ☁ Sync Now to Supabase
                </button>

                <!-- Status -->
                <div class="status-badge <?= $supabase_ok ? 'status-online' : 'status-offline' ?>">
                    <span class="status-dot"></span>
                    <?= $supabase_ok ? 'Connected to Supabase' : 'Supabase unreachable — check internet' ?>
                </div>

                <?php if ($supabase_ok): ?>
                <!-- Summary Table -->
                <div class="sync-table-wrap">
                    <table class="sync-table">
                        <tr>
                            <th>Table</th>
                            <th>Records</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        <?php $grand_total = 0; foreach ($sync_tables as $table):
                            $rows = $table_rows[$table] ?? [];
                            $count = count($rows);
                            $grand_total += $count;
                        ?>
                        <tr>
                            <td><?= $table ?></td>
                            <td><?= $count ?></td>
                            <td><span class="badge-ok">✓ OK</span></td>
                            <td>
                                <?php if ($count > 0): ?>
                                <button class="view-btn" onclick="toggleRecords('<?= $table ?>', this)">▼ View</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <div class="total-line">Total: <span><?= $grand_total ?> records</span></div>
                </div>

                <!-- Record Cards -->
                <?php foreach ($sync_tables as $table):
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
                                <button class="delete-btn" onclick="deleteRecord('<?= $table ?>', '<?= $pk ?>', '<?= htmlspecialchars($pk_val) ?>')">🗑</button>
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

        </div>

        <div style="padding:0 16px 16px;">
            <button type="button" class="back-main" style="width:100%;" onclick="window.location.href='index.php'">
                BACK TO MAIN MENU
            </button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?= $COPYRIGHT_FOOTER ?></p>
        </footer>
    </div>
</div>

<script>
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-activity').style.display = tab === 'activity' ? '' : 'none';
    document.getElementById('tab-sync').style.display = tab === 'sync' ? '' : 'none';
}

function filterLogs(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.log-card').forEach(card => {
        if (type === 'all') card.style.display = '';
        else if (type === 'pending') card.style.display = card.dataset.pending === '1' ? '' : 'none';
        else card.style.display = card.dataset.action === type ? '' : 'none';
    });
}

function dismissLog(logId, btn) {
    if (!confirm('Dismiss this log entry?')) return;
    btn.disabled = true; btn.textContent = '...';
    fetch('activity_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=dismiss&log_id=' + logId
    }).then(r => r.json()).then(data => {
        if (data.success) {
            const card = btn.closest('.log-card');
            card.style.opacity = '0'; card.style.transition = 'opacity 0.3s';
            setTimeout(() => card.remove(), 300);
        }
    });
}

function manualSync() {
    const btn = document.getElementById('syncNowBtn');
    btn.disabled = true; btn.textContent = '⟳ Syncing...';
    fetch('activity_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=manual_sync'
    }).then(r => r.json()).then(data => {
        if (data.success) {
            btn.textContent = '✓ Synced ' + data.synced + ' records';
            btn.style.color = '#00ff88';
        } else {
            btn.textContent = '✗ ' + (data.message || 'Failed');
            btn.style.color = '#ff6b6b';
        }
        setTimeout(() => { btn.disabled = false; btn.textContent = '☁ Sync Now to Supabase'; btn.style.color = ''; }, 3000);
    });
}

function toggleRecords(table, btn) {
    const sec = document.getElementById('records-' + table);
    if (sec.classList.contains('open')) { sec.classList.remove('open'); btn.textContent = '▼ View'; }
    else { sec.classList.add('open'); btn.textContent = '▲ Hide'; sec.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}

function deleteRecord(table, idCol, idVal) {
    if (!confirm('Delete "' + idVal + '" from local DB and Supabase?')) return;
    fetch('activity_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete_record&table=' + table + '&id_col=' + idCol + '&id_val=' + encodeURIComponent(idVal)
    }).then(r => r.json()).then(data => {
        if (data.success) {
            const card = document.getElementById('card-' + table + '-' + idVal);
            if (card) { card.style.opacity = '0'; card.style.transition = 'opacity 0.3s'; setTimeout(() => card.remove(), 300); }
        } else alert('Failed to delete.');
    });
}
</script>
</body>
</html>