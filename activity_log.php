<?php
// activity_log.php — v3.0
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

$current_username = $_SESSION['username'] ?? 'admin';

// ── Handle dismiss log entry ──────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'dismiss') {
    $log_id = (int)($_POST['log_id'] ?? 0);
    if ($log_id > 0) {
        $conn->query("UPDATE activity_log SET sync_flag = 0 WHERE id = $log_id");
        $conn->query("DELETE FROM sync_status WHERE log_id = $log_id");
    }
    echo json_encode(['success' => true]);
    close_db_connection($conn);
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

// ── Total approved users ──────────────────────────────────────
$ur = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_approved = 1");
$total_users = $ur ? (int)$ur->fetch_assoc()['total'] : 1;

// ── Pending sync info ─────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS total FROM pending_sync WHERE is_processing = 0");
$pending_count = $r ? (int)$r->fetch_assoc()['total'] : 0;

$r = $conn->query("SELECT COUNT(*) AS total FROM deleted_records WHERE synced = 0");
$deleted_count = $r ? (int)$r->fetch_assoc()['total'] : 0;

$r = $conn->query("SELECT MIN(created_at) AS oldest FROM pending_sync WHERE is_processing = 0");
$oldest_pending = null;
$days_oldest    = 0;
if ($r) {
    $row = $r->fetch_assoc();
    $oldest_pending = $row['oldest'];
    if ($oldest_pending) $days_oldest = (time() - strtotime($oldest_pending)) / 86400;
}

// ── Last cloud push ───────────────────────────────────────────
$last_push = null;
$r = $conn->query("SELECT pushed_at, pushed_by, total_rows, total_dels
    FROM last_cloud_push ORDER BY pushed_at DESC LIMIT 1");
if ($r && $r->num_rows > 0) $last_push = $r->fetch_assoc();

// ── Waktu boleh push? ─────────────────────────────────────────
$server_h   = (int)date('H');
$server_m   = (int)date('i');
$time_ok    = ($server_h * 60 + $server_m) >= (20 * 60 + 30);
$server_now = date('H:i');

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
        .tab-bar { display:flex; gap:4px; margin-bottom:20px; background:#1e2022; border-radius:12px; padding:4px; }
        .tab-btn { flex:1; padding:10px; border:none; border-radius:9px; background:transparent; color:#666; font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s; }
        .tab-btn.active { background:#25282a; color:#00ccff; box-shadow:2px 2px 6px #1a1c1d; }

        /* Push panel */
        .push-panel { background:#1a1c1e; border:1px solid rgba(0,255,136,0.15); border-radius:14px; padding:18px; margin-bottom:18px; }
        .push-panel-title { font-size:13px; font-weight:700; color:#00ff88; margin-bottom:14px; letter-spacing:0.5px; }
        .push-info-row { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
        .push-info-box { flex:1; min-width:90px; background:#25282a; border-radius:10px; padding:10px 12px; text-align:center; box-shadow:2px 2px 6px #1a1c1d; }
        .push-info-num { font-size:22px; font-weight:700; color:#00ff88; }
        .push-info-num.warn { color:#f6a623; }
        .push-info-num.red  { color:#ff6b6b; }
        .push-info-label { font-size:10px; color:#666; margin-top:2px; }

        /* Progress bar */
        .push-progress-wrap { margin-bottom:14px; display:none; }
        .push-progress-label { display:flex; justify-content:space-between; font-size:12px; color:#888; margin-bottom:6px; }
        .push-progress-bar { height:10px; background:#25282a; border-radius:5px; overflow:hidden; box-shadow:inset 2px 2px 5px #1a1c1d; }
        .push-progress-fill { height:100%; width:0%; background:linear-gradient(90deg, #00ff88, #00ccff); border-radius:5px; transition:width 0.4s ease; }

        /* Push button */
        .push-btn { width:100%; padding:14px; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; transition:all 0.2s; letter-spacing:0.5px; }
        .push-btn.active  { background:linear-gradient(135deg, #00ff88, #00ccff); color:#0d0f10; }
        .push-btn.active:hover { opacity:0.9; }
        .push-btn.disabled { background:#1e2022; color:#444; cursor:not-allowed; border:1px solid rgba(255,255,255,0.05); }
        .push-btn:disabled { opacity:0.7; cursor:not-allowed; }

        /* Time warning */
        .time-warning { background:#2c1a0d; border:1px solid rgba(246,166,35,0.3); border-radius:10px; padding:10px 14px; font-size:12px; color:#f6a623; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
        .last-push-info { font-size:11px; color:#555; margin-bottom:12px; padding:8px 12px; background:#1e2022; border-radius:8px; }
        .last-push-info span { color:#888; }

        /* Status badge */
        .status-badge { display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; font-size:12px; font-weight:600; margin-bottom:14px; }
        .status-online  { background:#1a2c1e; color:#00ff88; border:1px solid rgba(0,255,136,0.15); }
        .status-offline { background:#2c1a1a; color:#ff6b6b; border:1px solid rgba(255,107,107,0.15); }
        .status-dot { width:7px; height:7px; border-radius:50%; background:currentColor; box-shadow:0 0 7px currentColor; }

        /* Activity log cards */
        .log-card { background:#25282a; border-radius:12px; padding:14px; margin-bottom:10px; box-shadow:4px 4px 9px #1a1c1d,-2px -2px 6px #2e3234; }
        .log-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
        .log-action { display:inline-block; padding:3px 10px; border-radius:6px; font-size:11px; font-weight:700; text-transform:uppercase; }
        .action-INSERT { background:rgba(0,255,136,0.1);  color:#00ff88; border:1px solid rgba(0,255,136,0.2); }
        .action-UPDATE { background:rgba(0,204,255,0.1);  color:#00ccff; border:1px solid rgba(0,204,255,0.2); }
        .action-DELETE { background:rgba(255,107,107,0.1);color:#ff6b6b; border:1px solid rgba(255,107,107,0.2); }
        .log-meta { font-size:12px; color:#888; }
        .log-actions { display:flex; gap:6px; flex-wrap:wrap; }
        .btn-view-page { background:#1e2022; border:1px solid rgba(0,204,255,0.2); border-radius:7px; padding:4px 10px; color:#00ccff; font-size:11px; cursor:pointer; text-decoration:none; }
        .btn-dismiss   { background:#1e2022; border:1px solid rgba(246,166,35,0.2); border-radius:7px; padding:4px 10px; color:#f6a623; font-size:11px; cursor:pointer; }
        .sync-bar { margin-top:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .sync-progress { flex:1; height:3px; background:#1e2022; border-radius:2px; min-width:60px; }
        .sync-fill { height:100%; border-radius:2px; background:#00ff88; }
        .sync-text { font-size:11px; color:#888; }
        .sync-text span { color:#00ff88; }

        /* Filter */
        .filter-bar { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
        .filter-btn { background:#1e2022; border:1px solid rgba(255,255,255,0.07); border-radius:7px; padding:5px 12px; color:#666; font-size:12px; cursor:pointer; flex:1; text-align:center; }
        .filter-btn.active { border-color:rgba(0,204,255,0.3); color:#00ccff; background:rgba(0,204,255,0.05); }
        .empty-state { text-align:center; padding:40px; color:#444; font-size:14px; }
        .pending-badge { background:rgba(246,166,35,0.15); color:#f6a623; border-radius:10px; padding:2px 8px; font-size:11px; font-weight:600; margin-left:8px; }

        /* Push result */
        .push-result { display:none; padding:12px 14px; border-radius:10px; font-size:13px; font-weight:600; margin-top:10px; text-align:center; }
        .push-result.success { background:#1a2c1e; color:#00ff88; border:1px solid rgba(0,255,136,0.2); }
        .push-result.error   { background:#2c1a1a; color:#ff6b6b; border:1px solid rgba(255,107,107,0.2); }
        .push-log { font-size:11px; color:#666; margin-top:10px; max-height:120px; overflow-y:auto; background:#1a1c1e; border-radius:8px; padding:8px; display:none; }
        .push-log-line { padding:2px 0; border-bottom:1px solid rgba(255,255,255,0.03); }
    </style>
</head>
<body>
<div class="main-wrapper">
  <div class="content-area" style="flex-direction:column">

    <div class="header-container" style="margin-left:auto;margin-right:auto;width:100%;">
        <button class="logout-btn" onclick="window.location.href='logout.php';"><span>Logout</span></button>
        <div class="brand-section">
            <div class="logo-box">
                <img src="<?= htmlspecialchars($BRAND_IMAGE_PATH) ?>" alt="Brand Logo" style="height:40px;">
            </div>
            <h1 class="company-name"><?= htmlspecialchars($STORE_NAME) ?></h1>
            <p class="company-address"><?= htmlspecialchars($STORE_ADDRESS) ?></p>
        </div>
    </div>

    <div class="main-card" style="width:100%;box-sizing:border-box;">
        <h2>
            ACTIVITY LOG & SYNC
            <?php $pending_badge = count(array_filter($logs, fn($l) => $l['sync_flag'] == 1)); ?>
            <?php if ($pending_badge > 0): ?>
            <span class="pending-badge">⏳ <?= $pending_badge ?> pending</span>
            <?php endif; ?>
        </h2>

        <!-- Tabs -->
        <div class="tab-bar">
            <button class="tab-btn active" onclick="switchTab('activity',this)">📋 Activity Log</button>
            <button class="tab-btn" onclick="switchTab('push',this)">☁️ Push</button>
            <button class="tab-btn" onclick="switchTab('pull',this)">🔽 Pull</button>
        </div>

        <!-- ══ TAB: ACTIVITY LOG ══ -->
        <div id="tab-activity">
            <div class="filter-bar">
                <button class="filter-btn active" onclick="filterLogs('all',this)">All</button>
                <button class="filter-btn" onclick="filterLogs('INSERT',this)">Insert</button>
                <button class="filter-btn" onclick="filterLogs('UPDATE',this)">Update</button>
                <button class="filter-btn" onclick="filterLogs('DELETE',this)">Delete</button>
                <button class="filter-btn" onclick="filterLogs('pending',this)">Pending</button>
            </div>

            <?php if (empty($logs)): ?>
            <div class="empty-state">✓ Belum ada aktivitas</div>
            <?php else: ?>
            <div id="log-list">
            <?php foreach ($logs as $log):
                $lid       = $log['id'];
                $applied   = $sync_map[$lid] ?? [];
                $pct       = $total_users > 0 ? min(100, round(count($applied) / $total_users * 100)) : 0;
                $page_url  = $page_map[$log['table_name']] ?? '#';
                $is_pending= $log['sync_flag'] == 1;
            ?>
            <div class="log-card" data-action="<?= $log['action'] ?>" data-pending="<?= $is_pending?'1':'0' ?>">
                <div class="log-header">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="log-action action-<?= $log['action'] ?>"><?= $log['action'] ?></span>
                        <span style="color:#ccc;font-size:13px;font-weight:600;"><?= htmlspecialchars($log['table_name']) ?></span>
                        <span style="color:#555;font-size:11px;font-family:monospace;">ID: <?= htmlspecialchars($log['record_id']) ?></span>
                    </div>
                    <div class="log-actions">
                        <?php if ($page_url !== '#'): ?>
                        <a href="<?= $page_url ?>" class="btn-view-page">→ View</a>
                        <?php endif; ?>
                        <?php if ($is_pending): ?>
                        <button class="btn-dismiss" onclick="dismissLog(<?= $lid ?>,this)">✕ Dismiss</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="log-meta">
                    By: <span style="color:#ccc;"><?= htmlspecialchars($log['changed_by']) ?></span>
                    &nbsp;·&nbsp; <?= date('d M Y, H:i', strtotime($log['changed_at'])) ?>
                    &nbsp;·&nbsp;
                    <?= $is_pending
                        ? '<span style="color:#f6a623">Menunggu push</span>'
                        : '<span style="color:#00ff88">Sudah sync</span>' ?>
                </div>
                <?php if ($is_pending): ?>
                <div class="sync-bar">
                    <div class="sync-progress"><div class="sync-fill" style="width:<?= $pct ?>%"></div></div>
                    <span class="sync-text">
                        <span><?= count($applied) ?>/<?= $total_users ?></span> device sync
                        <?php if (!empty($applied)): ?>(<?= implode(', ', array_map('htmlspecialchars', $applied)) ?>)<?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ TAB: PULL FROM CLOUD ══ -->
        <div id="tab-pull" style="display:none;">

            <div id="pull-sb-badge" class="status-badge status-offline">
                <span class="status-dot"></span>
                <span id="pull-sb-text">Memeriksa koneksi...</span>
            </div>

            <div id="pull-last-info" class="last-push-info">📅 Memeriksa riwayat pull...</div>

            <div class="push-panel">
                <div class="push-panel-title">🔽 DATA TERSEDIA DI CLOUD</div>
                <div class="push-info-row">
                    <div class="push-info-box">
                        <div class="push-info-num warn" id="pull-info-available">—</div>
                        <div class="push-info-label">Update tersedia</div>
                    </div>
                    <div class="push-info-box">
                        <div class="push-info-num" id="pull-info-time" style="font-size:16px;">—</div>
                        <div class="push-info-label">Waktu server</div>
                    </div>
                </div>

                <div class="push-progress-wrap" id="pull-progress-wrap">
                    <div class="push-progress-label">
                        <span id="pull-progress-label">Mempersiapkan...</span>
                        <span id="pull-progress-pct">0%</span>
                    </div>
                    <div class="push-progress-bar">
                        <div class="push-progress-fill" id="pull-progress-fill"
                             style="background:linear-gradient(90deg,#00ccff,#7b61ff);"></div>
                    </div>
                </div>

                <button class="push-btn disabled" id="pullBtn" onclick="startPull()" disabled>
                    ⟳ Memeriksa...
                </button>

                <div class="push-result" id="pull-result"></div>
                <div class="push-log" id="pull-log"></div>
            </div>
        </div>

        <!-- ══ TAB: PUSH TO CLOUD ══ -->
        <div id="tab-push" style="display:none;">

            <!-- Status Supabase (diisi JS) -->
            <div id="sb-status-badge" class="status-badge status-offline">
                <span class="status-dot"></span>
                <span id="sb-status-text">Memeriksa koneksi...</span>
            </div>

            <!-- Info last push -->
            <?php if ($last_push): ?>
            <div class="last-push-info">
                📅 Push terakhir: <span><?= date('d M Y, H:i', strtotime($last_push['pushed_at'])) ?></span>
                oleh <span><?= htmlspecialchars($last_push['pushed_by']) ?></span>
            </div>
            <?php else: ?>
            <div class="last-push-info">📅 Belum pernah push ke cloud.</div>
            <?php endif; ?>

            <!-- Warning waktu -->
            <div id="time-warning" class="time-warning" style="<?= $time_ok ? 'display:none' : '' ?>">
                ⏰ Upload hanya boleh dilakukan mulai pukul <strong>20:30</strong>.
                Waktu server sekarang: <strong id="server-time-display"><?= $server_now ?></strong>
            </div>

            <!-- Info panel (diisi JS setelah check) -->
            <div class="push-panel">
                <div class="push-panel-title">📦 DATA MENUNGGU PUSH</div>
                <div class="push-info-row">
                    <div class="push-info-box">
                        <div class="push-info-num <?= ($pending_count > 0) ? 'warn' : '' ?>" id="info-pending"><?= $pending_count ?></div>
                        <div class="push-info-label">INSERT/UPDATE</div>
                    </div>
                    <div class="push-info-box">
                        <div class="push-info-num <?= ($deleted_count > 0) ? 'red' : '' ?>" id="info-deleted"><?= $deleted_count ?></div>
                        <div class="push-info-label">DELETE</div>
                    </div>
                    <div class="push-info-box">
                        <div class="push-info-num <?= ($days_oldest >= 3) ? 'red' : (($days_oldest > 0) ? 'warn' : '') ?>" id="info-days">
                            <?= $days_oldest > 0 ? round($days_oldest, 1) : '—' ?>
                        </div>
                        <div class="push-info-label">Hari tertunda</div>
                    </div>
                </div>

                <!-- Progress bar (muncul saat push) -->
                <div class="push-progress-wrap" id="progress-wrap">
                    <div class="push-progress-label">
                        <span id="progress-label">Mempersiapkan...</span>
                        <span id="progress-pct">0%</span>
                    </div>
                    <div class="push-progress-bar">
                        <div class="push-progress-fill" id="progress-fill"></div>
                    </div>
                </div>

                <!-- Tombol Push -->
                <button class="push-btn <?= $time_ok ? 'active' : 'disabled' ?>"
                        id="pushBtn"
                        onclick="startPush()"
                        <?= ($time_ok && ($pending_count + $deleted_count) > 0) ? '' : 'disabled' ?>>
                    <?php if (!$time_ok): ?>
                        ⏰ Push tersedia mulai 20:30
                    <?php elseif ($pending_count + $deleted_count === 0): ?>
                        ✓ Tidak ada data untuk di-push
                    <?php else: ?>
                        ☁ PUSH KE CLOUD SEKARANG
                    <?php endif; ?>
                </button>

                <!-- Hasil push -->
                <div class="push-result" id="push-result"></div>

                <!-- Log detail -->
                <div class="push-log" id="push-log"></div>
            </div>

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
// ── Data dari PHP ─────────────────────────────────────────────
const TOTAL_ITEMS   = <?= $pending_count + $deleted_count ?>;
const PENDING_COUNT = <?= $pending_count ?>;
const DELETE_COUNT  = <?= $deleted_count ?>;
const TIME_OK       = <?= $time_ok ? 'true' : 'false' ?>;

let pushRunning     = false;
let processedSoFar  = 0;

// ── Tab switch ────────────────────────────────────────────────
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-activity').style.display = tab === 'activity' ? '' : 'none';
    document.getElementById('tab-push').style.display     = tab === 'push'     ? '' : 'none';
    document.getElementById('tab-pull').style.display     = tab === 'pull'     ? '' : 'none';
    if (tab === 'push') checkSupabase();
    if (tab === 'pull') checkPullStatus();
}

// ── PULL: cek status ──────────────────────────────────────────
function checkPullStatus() {
    fetch('pull_from_cloud.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check'
    }).then(r => r.json()).then(data => {
        const badge = document.getElementById('pull-sb-badge');
        const text  = document.getElementById('pull-sb-text');
        const btn   = document.getElementById('pullBtn');

        document.getElementById('pull-info-available').textContent = data.available ?? '—';
        document.getElementById('pull-info-time').textContent      = data.server_time ?? '—';

        if (data.last_pull) {
            document.getElementById('pull-last-info').textContent =
                '📅 Pull terakhir: ' + data.last_pull.pulled_at + ' oleh ' + data.last_pull.pulled_by;
        } else {
            document.getElementById('pull-last-info').textContent = '📅 Belum pernah pull dari cloud.';
        }

        if (data.sb_online) {
            badge.className = 'status-badge status-online';
            text.textContent = 'Terhubung ke Supabase';
        } else {
            badge.className = 'status-badge status-offline';
            text.textContent = 'Supabase tidak dapat dijangkau';
        }

        if (!data.sb_online) {
            btn.disabled = true;
            btn.textContent = '✗ Supabase offline';
            btn.className = 'push-btn disabled';
        } else if (data.available === 0) {
            btn.disabled = true;
            btn.textContent = '✓ Data sudah up-to-date';
            btn.className = 'push-btn disabled';
        } else {
            btn.disabled = false;
            btn.textContent = '🔽 PULL DARI CLOUD (' + data.available + ' update)';
            btn.className = 'push-btn active';
            btn.style.background = 'linear-gradient(135deg,#00ccff,#7b61ff)';
        }
    }).catch(() => {
        document.getElementById('pull-sb-text').textContent = 'Gagal cek koneksi';
    });
}

// ── PULL: jalankan pull bertahap ──────────────────────────────
let pullRunning = false;

async function startPull() {
    if (pullRunning) return;
    if (!confirm('Pull semua update dari Supabase ke database lokal?\n\nData lokal akan diperbarui sesuai cloud.')) return;

    pullRunning = true;
    const btn   = document.getElementById('pullBtn');
    btn.disabled = true;
    btn.textContent = '⟳ Sedang pull...';

    document.getElementById('pull-log').innerHTML = '';
    document.getElementById('pull-result').style.display = 'none';
    document.getElementById('pull-progress-wrap').style.display = 'block';

    const totalAvail = parseInt(document.getElementById('pull-info-available').textContent) || 1;
    let totalProcessed = 0;

    addPullLog('▶ Memulai pull dari Supabase — ' + new Date().toLocaleTimeString());

    try {
        let batchDone = false;
        while (!batchDone) {
            const pct = totalAvail > 0 ? Math.min(95, (totalProcessed / totalAvail) * 95) : 50;
            setPullProgress('Menarik update dari cloud...', pct);

            const res  = await fetch('pull_from_cloud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=pull_batch'
            });
            const data = await res.json();

            if (data.error) {
                showPullResult('✗ Error: ' + data.message, false);
                addPullLog('✗ ' + data.message);
                resetPullBtn(btn); return;
            }

            if (data.log_details && data.log_details.length > 0) {
                data.log_details.forEach(l => addPullLog(l));
            }

            totalProcessed += (data.processed || 0);

            if (data.done || data.remaining === 0) {
                batchDone = true;
            } else {
                await sleep(300);
            }
        }

        // Finalize
        setPullProgress('Finalisasi...', 97);
        const finRes  = await fetch('pull_from_cloud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=finalize'
        });
        const finData = await finRes.json();

        setPullProgress('Selesai!', 100);
        addPullLog('✅ Pull selesai pada ' + new Date().toLocaleTimeString());

        showPullResult('✅ ' + totalProcessed + ' update berhasil ditarik dari Supabase!', true);
        document.getElementById('pull-info-available').textContent = '0';
        btn.textContent = '✓ Data sudah up-to-date';
        btn.className = 'push-btn disabled';

    } catch (err) {
        showPullResult('✗ Koneksi terputus: ' + err.message, false);
        addPullLog('✗ Error: ' + err.message);
        resetPullBtn(btn);
    }

    pullRunning = false;
}

function setPullProgress(label, pct) {
    document.getElementById('pull-progress-wrap').style.display = 'block';
    document.getElementById('pull-progress-label').textContent  = label;
    document.getElementById('pull-progress-pct').textContent    = Math.round(pct) + '%';
    document.getElementById('pull-progress-fill').style.width   = pct + '%';
}

function addPullLog(msg) {
    const log = document.getElementById('pull-log');
    log.style.display = 'block';
    const line = document.createElement('div');
    line.className = 'push-log-line';
    line.textContent = msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}

function showPullResult(msg, isSuccess) {
    const el = document.getElementById('pull-result');
    el.style.display = 'block';
    el.className = 'push-result ' + (isSuccess ? 'success' : 'error');
    el.textContent = msg;
}

function resetPullBtn(btn) {
    pullRunning = false;
    btn.disabled = false;
    btn.textContent = '🔽 PULL DARI CLOUD';
    btn.className = 'push-btn active';
    btn.style.background = 'linear-gradient(135deg,#00ccff,#7b61ff)';
}

// ── Filter log ────────────────────────────────────────────────
function filterLogs(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.log-card').forEach(card => {
        if (type === 'all')     card.style.display = '';
        else if (type === 'pending') card.style.display = card.dataset.pending === '1' ? '' : 'none';
        else card.style.display = card.dataset.action === type ? '' : 'none';
    });
}

// ── Dismiss log entry ─────────────────────────────────────────
function dismissLog(logId, btn) {
    if (!confirm('Dismiss log ini?')) return;
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

// ── Cek Supabase ──────────────────────────────────────────────
function checkSupabase() {
    fetch('push_to_cloud.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check'
    }).then(r => r.json()).then(data => {
        const badge = document.getElementById('sb-status-badge');
        const text  = document.getElementById('sb-status-text');
        if (data.sb_online) {
            badge.className = 'status-badge status-online';
            text.textContent = 'Terhubung ke Supabase';
        } else {
            badge.className = 'status-badge status-offline';
            text.textContent = 'Supabase tidak dapat dijangkau';
        }

        // Update info
        document.getElementById('info-pending').textContent = data.pending_count;
        document.getElementById('info-deleted').textContent = data.deleted_count;
        document.getElementById('info-days').textContent    =
            data.days_oldest > 0 ? data.days_oldest.toFixed(1) : '—';
        document.getElementById('server-time-display').textContent = data.server_time;

        const timeWarn = document.getElementById('time-warning');
        const pushBtn  = document.getElementById('pushBtn');
        const total    = data.pending_count + data.deleted_count;

        if (!data.time_allowed) {
            timeWarn.style.display = 'flex';
            pushBtn.disabled = true;
            pushBtn.textContent = '⏰ Push tersedia mulai 20:30';
            pushBtn.className = 'push-btn disabled';
        } else if (total === 0) {
            timeWarn.style.display = 'none';
            pushBtn.disabled = true;
            pushBtn.textContent = '✓ Tidak ada data untuk di-push';
            pushBtn.className = 'push-btn disabled';
        } else if (!data.sb_online) {
            pushBtn.disabled = true;
            pushBtn.textContent = '✗ Supabase offline';
            pushBtn.className = 'push-btn disabled';
        } else {
            timeWarn.style.display = 'none';
            pushBtn.disabled = false;
            pushBtn.textContent = '☁ PUSH KE CLOUD SEKARANG (' + total + ' item)';
            pushBtn.className = 'push-btn active';
        }
    }).catch(() => {
        document.getElementById('sb-status-text').textContent = 'Gagal cek koneksi';
    });
}

// ── Update progress bar ───────────────────────────────────────
function setProgress(label, pct) {
    document.getElementById('progress-wrap').style.display = 'block';
    document.getElementById('progress-label').textContent  = label;
    document.getElementById('progress-pct').textContent    = Math.round(pct) + '%';
    document.getElementById('progress-fill').style.width   = pct + '%';
}

function addLog(msg) {
    const log = document.getElementById('push-log');
    log.style.display = 'block';
    const line = document.createElement('div');
    line.className   = 'push-log-line';
    line.textContent = msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}

function showResult(msg, isSuccess) {
    const el = document.getElementById('push-result');
    el.style.display = 'block';
    el.className = 'push-result ' + (isSuccess ? 'success' : 'error');
    el.textContent = msg;
}

// ── MAIN: start push ──────────────────────────────────────────
async function startPush() {
    if (pushRunning) return;
    if (!confirm('Push semua data pending ke Supabase sekarang?\n\nProses ini tidak dapat dibatalkan.')) return;

    pushRunning    = true;
    processedSoFar = 0;
    const total    = TOTAL_ITEMS;
    const btn      = document.getElementById('pushBtn');

    btn.disabled   = true;
    btn.textContent = '⟳ Sedang push...';
    document.getElementById('push-log').innerHTML = '';
    document.getElementById('push-result').style.display = 'none';

    setProgress('Memulai push...', 0);
    addLog('▶ Memulai push ke Supabase — ' + new Date().toLocaleTimeString());

    try {
        // ── PHASE 1: Push INSERT/UPDATE batches ───────────────
        let offset = 0;
        let batchDone = false;
        while (!batchDone) {
            setProgress('Push data INSERT/UPDATE...', total > 0 ? (processedSoFar / total * 80) : 40);
            const res = await fetch('push_to_cloud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=push_batch&offset=' + offset
            });
            const data = await res.json();

            if (data.error) {
                showResult('✗ Error: ' + data.message, false);
                addLog('✗ ' + data.message);
                resetBtn(btn); return;
            }

            if (data.processed > 0) {
                processedSoFar += data.processed;
                addLog('✓ Batch: ' + data.processed + ' row dikirim. Sisa: ' + data.remaining);
            }

            if (data.done) { batchDone = true; }
            else { offset += data.processed || 10; await sleep(300); }
        }
        addLog('✓ Selesai push INSERT/UPDATE.');

        // ── PHASE 2: Push deletes ─────────────────────────────
        setProgress('Push data DELETE...', 85);
        const delRes = await fetch('push_to_cloud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=push_deletes'
        });
        const delData = await delRes.json();
        if (delData.error) {
            showResult('✗ Error saat push deletes: ' + delData.message, false);
            addLog('✗ ' + delData.message);
            resetBtn(btn); return;
        }
        addLog('✓ DELETE sync: ' + delData.pushed_deletes + ' record.');

        // ── PHASE 3: Finalize ─────────────────────────────────
        setProgress('Finalisasi...', 95);
        const finRes = await fetch('push_to_cloud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=finalize'
        });
        const finData = await finRes.json();
        if (finData.error) {
            showResult('✗ Gagal finalisasi: ' + finData.message, false);
            addLog('✗ ' + finData.message);
            resetBtn(btn); return;
        }

        setProgress('Selesai!', 100);
        addLog('✅ Push selesai pada ' + new Date().toLocaleTimeString());

        const leftover = (finData.remaining_pending || 0) + (finData.remaining_deleted || 0);
        if (leftover > 0) {
            showResult('⚠ Push selesai, tapi masih ada ' + leftover + ' item tersisa. Coba push lagi.', false);
        } else {
            showResult('✅ Semua data berhasil di-push ke Supabase!', true);
            btn.textContent = '✓ Tidak ada data untuk di-push';
            btn.className   = 'push-btn disabled';
        }

    } catch (err) {
        showResult('✗ Koneksi terputus: ' + err.message, false);
        addLog('✗ Error: ' + err.message);
        resetBtn(btn);
    }

    pushRunning = false;
}

function resetBtn(btn) {
    pushRunning = false;
    btn.disabled = false;
    btn.textContent = '☁ PUSH KE CLOUD SEKARANG';
    btn.className = 'push-btn active';
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ── Auto-check saat tab push dibuka ──────────────────────────
// Sudah di-handle di switchTab()
</script>
</body>
</html>
