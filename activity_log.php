<?php
// activity_log.php — v5.0 (Local Network Sync)
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Jakarta');
ob_start();
include 'db_config.php';
include 'config_helper.php';
ob_clean();

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$current_username = $_SESSION['username'] ?? 'staff';
$is_admin         = ($_SESSION['role'] === 'admin');

// ── Handle dismiss log entry (admin only) ─────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'dismiss' && $is_admin) {
    $log_id = (int)($_POST['log_id'] ?? 0);
    if ($log_id > 0) {
        $conn->query("UPDATE activity_log SET synced = 1 WHERE id = $log_id");
    }
    echo json_encode(['success' => true]);
    close_db_connection($conn);
    exit();
}

// ── Data untuk halaman ────────────────────────────────────────
$logs = [];
if ($is_admin) {
    $result = $conn->query("SELECT * FROM activity_log ORDER BY changed_at DESC LIMIT 200");
    if ($result) while ($row = $result->fetch_assoc()) $logs[] = $row;
}

$pending_count = 0;
$deleted_count = 0;
$oldest_pending = null;
$days_oldest    = 0;
$last_sync_info = null;

$r = $conn->query("SELECT COUNT(*) AS total FROM pending_sync");
if ($r) $pending_count = (int)$r->fetch_assoc()['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM deleted_records WHERE synced = 0");
if ($r) $deleted_count = (int)$r->fetch_assoc()['total'];

$r = $conn->query("SELECT MIN(created_at) AS oldest FROM pending_sync");
if ($r) {
    $row = $r->fetch_assoc();
    $oldest_pending = $row['oldest'];
    if ($oldest_pending) $days_oldest = (time() - strtotime($oldest_pending)) / 86400;
}

$r = $conn->query("SELECT * FROM last_sync ORDER BY synced_at DESC LIMIT 1");
if ($r && $r->num_rows > 0) $last_sync_info = $r->fetch_assoc();

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
        /* Tabs */
        .tab-bar { display:flex; gap:4px; margin-bottom:20px; background:#1e2022; border-radius:12px; padding:4px; }
        .tab-btn { flex:1; padding:10px; border:none; border-radius:9px; background:transparent; color:#666; font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s; }
        .tab-btn.active { background:#25282a; color:#00ccff; box-shadow:2px 2px 6px #1a1c1d; }

        /* Sync panel */
        .sync-panel { background:#1a1c1e; border:1px solid rgba(0,204,255,0.15); border-radius:14px; padding:18px; margin-bottom:18px; }
        .sync-panel-title { font-size:13px; font-weight:700; color:#00ccff; margin-bottom:14px; letter-spacing:0.5px; }
        .sync-info-row { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
        .sync-info-box { flex:1; min-width:90px; background:#25282a; border-radius:10px; padding:10px 12px; text-align:center; }
        .sync-info-num { font-size:22px; font-weight:700; color:#00ccff; }
        .sync-info-num.warn { color:#f6a623; }
        .sync-info-num.red  { color:#ff6b6b; }
        .sync-info-label { font-size:10px; color:#666; margin-top:2px; }

        /* IP Input */
        .ip-input-wrap { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
        .ip-input { flex:1; background:#25282a; border:1px solid rgba(0,204,255,0.2); color:#ccc;
                    padding:10px 12px; border-radius:8px; font-size:13px; min-width:150px;
                    font-family:monospace; }
        .ip-input::placeholder { color:#444; }
        .btn-ping { background:#1e2022; border:1px solid rgba(0,204,255,0.3); color:#00ccff;
                    padding:10px 14px; border-radius:8px; font-size:12px; cursor:pointer; font-weight:600; }

        /* Status badge */
        .status-badge { display:flex; align-items:center; gap:8px; padding:10px 14px;
                        border-radius:10px; font-size:12px; font-weight:600; margin-bottom:14px; }
        .status-online  { background:#1a2c1e; color:#00ff88; border:1px solid rgba(0,255,136,0.15); }
        .status-offline { background:#2c1a1a; color:#ff6b6b; border:1px solid rgba(255,107,107,0.15); }
        .status-waiting { background:#1e2022; color:#666;    border:1px solid rgba(255,255,255,0.05); }
        .status-dot { width:7px; height:7px; border-radius:50%; background:currentColor; box-shadow:0 0 7px currentColor; }

        /* Progress */
        .sync-progress-wrap { margin-bottom:14px; display:none; }
        .sync-progress-label { display:flex; justify-content:space-between; font-size:12px; color:#888; margin-bottom:6px; }
        .sync-progress-bar { height:10px; background:#25282a; border-radius:5px; overflow:hidden; }
        .sync-progress-fill { height:100%; width:0%; background:linear-gradient(90deg,#00ccff,#7b61ff); border-radius:5px; transition:width 0.4s ease; }

        /* Sync buttons */
        .sync-btn { width:100%; padding:14px; border:none; border-radius:10px; font-size:14px;
                    font-weight:700; cursor:pointer; transition:all 0.2s; letter-spacing:0.5px; margin-bottom:8px; }
        .sync-btn.push { background:linear-gradient(135deg,#00ff88,#00ccff); color:#0d0f10; }
        .sync-btn.pull { background:linear-gradient(135deg,#00ccff,#7b61ff); color:#fff; }
        .sync-btn.disabled { background:#1e2022; color:#444; cursor:not-allowed;
                             border:1px solid rgba(255,255,255,0.05); }
        .sync-btn:disabled { opacity:0.7; cursor:not-allowed; }

        /* Result */
        .sync-result { display:none; padding:12px 14px; border-radius:10px; font-size:13px;
                       font-weight:600; margin-top:10px; text-align:center; }
        .sync-result.success { background:#1a2c1e; color:#00ff88; border:1px solid rgba(0,255,136,0.2); }
        .sync-result.error   { background:#2c1a1a; color:#ff6b6b; border:1px solid rgba(255,107,107,0.2); }
        .sync-log { font-size:11px; color:#666; margin-top:10px; max-height:140px;
                    overflow-y:auto; background:#1a1c1e; border-radius:8px; padding:8px; display:none; }
        .sync-log-line { padding:2px 0; border-bottom:1px solid rgba(255,255,255,0.03); }
        .last-sync-info { font-size:11px; color:#555; margin-bottom:12px; padding:8px 12px;
                          background:#1e2022; border-radius:8px; }
        .last-sync-info span { color:#888; }

        /* Activity log cards */
        .log-card { background:#25282a; border-radius:12px; padding:14px; margin-bottom:10px;
                    box-shadow:4px 4px 9px #1a1c1d,-2px -2px 6px #2e3234; }
        .log-header { display:flex; justify-content:space-between; align-items:flex-start;
                      flex-wrap:wrap; gap:8px; margin-bottom:8px; }
        .log-action { display:inline-block; padding:3px 10px; border-radius:6px;
                      font-size:11px; font-weight:700; text-transform:uppercase; }
        .action-INSERT { background:rgba(0,255,136,0.1);  color:#00ff88; border:1px solid rgba(0,255,136,0.2); }
        .action-UPDATE { background:rgba(0,204,255,0.1);  color:#00ccff; border:1px solid rgba(0,204,255,0.2); }
        .action-DELETE { background:rgba(255,107,107,0.1);color:#ff6b6b; border:1px solid rgba(255,107,107,0.2); }
        .log-meta { font-size:12px; color:#888; }
        .btn-view-page { background:#1e2022; border:1px solid rgba(0,204,255,0.2); border-radius:7px;
                         padding:4px 10px; color:#00ccff; font-size:11px; cursor:pointer; text-decoration:none; }
        .btn-dismiss { background:#1e2022; border:1px solid rgba(246,166,35,0.2); border-radius:7px;
                       padding:4px 10px; color:#f6a623; font-size:11px; cursor:pointer; }
        .filter-bar { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
        .filter-btn { background:#1e2022; border:1px solid rgba(255,255,255,0.07); border-radius:7px;
                      padding:5px 12px; color:#666; font-size:12px; cursor:pointer; flex:1; text-align:center; }
        .filter-btn.active { border-color:rgba(0,204,255,0.3); color:#00ccff; background:rgba(0,204,255,0.05); }
        .empty-state { text-align:center; padding:40px; color:#444; font-size:14px; }
        .pending-badge { background:rgba(246,166,35,0.15); color:#f6a623; border-radius:10px;
                         padding:2px 8px; font-size:11px; font-weight:600; margin-left:8px; }
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
            <?php $unsync = count(array_filter($logs, fn($l) => $l['synced'] == 0)); ?>
            <?php if ($unsync > 0): ?>
            <span class="pending-badge">⏳ <?= $unsync ?> belum sync</span>
            <?php endif; ?>
        </h2>

        <!-- Tabs -->
        <div class="tab-bar">
            <?php if ($is_admin): ?>
            <button class="tab-btn active" onclick="switchTab('activity',this)">📋 Log</button>
            <button class="tab-btn" onclick="switchTab('sync',this)">🔄 Sync</button>
            <?php else: ?>
            <button class="tab-btn active" onclick="switchTab('sync',this)">🔄 Sync</button>
            <?php endif; ?>
        </div>

        <!-- ══ TAB: ACTIVITY LOG (admin only) ══ -->
        <?php if ($is_admin): ?>
        <div id="tab-activity">
            <div class="filter-bar">
                <button class="filter-btn active" onclick="filterLogs('all',this)">All</button>
                <button class="filter-btn" onclick="filterLogs('INSERT',this)">Insert</button>
                <button class="filter-btn" onclick="filterLogs('UPDATE',this)">Update</button>
                <button class="filter-btn" onclick="filterLogs('DELETE',this)">Delete</button>
                <button class="filter-btn" onclick="filterLogs('unsynced',this)">Belum Sync</button>
            </div>

            <?php if (empty($logs)): ?>
            <div class="empty-state">✓ Belum ada aktivitas</div>
            <?php else: ?>
            <div id="log-list">
            <?php foreach ($logs as $log):
                $lid      = $log['id'];
                $page_url = $page_map[$log['table_name']] ?? '#';
                $is_unsynced = $log['synced'] == 0;
            ?>
            <div class="log-card" data-action="<?= $log['action'] ?>" data-synced="<?= $is_unsynced?'0':'1' ?>">
                <div class="log-header">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="log-action action-<?= $log['action'] ?>"><?= $log['action'] ?></span>
                        <span style="color:#ccc;font-size:13px;font-weight:600;"><?= htmlspecialchars($log['table_name']) ?></span>
                        <span style="color:#555;font-size:11px;font-family:monospace;">ID: <?= htmlspecialchars($log['record_id']) ?></span>
                    </div>
                    <div style="display:flex;gap:6px;">
                        <?php if ($page_url !== '#'): ?>
                        <a href="<?= $page_url ?>" class="btn-view-page">→ View</a>
                        <?php endif; ?>
                        <?php if ($is_unsynced): ?>
                        <button class="btn-dismiss" onclick="dismissLog(<?= $lid ?>,this)">✕</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="log-meta">
                    By: <span style="color:#ccc;"><?= htmlspecialchars($log['changed_by']) ?></span>
                    &nbsp;·&nbsp; <?= date('d M Y, H:i', strtotime($log['changed_at'])) ?>
                    &nbsp;·&nbsp;
                    <?= $is_unsynced
                        ? '<span style="color:#f6a623">Belum sync</span>'
                        : '<span style="color:#00ff88">Sudah sync</span>' ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ══ TAB: SYNC (semua user) ══ -->
        <div id="tab-sync" style="<?= $is_admin ? 'display:none;' : '' ?>">

            <!-- Info pending di device ini -->
            <div class="sync-panel">
                <div class="sync-panel-title">📦 DATA PENDING DI DEVICE INI</div>
                <div class="sync-info-row">
                    <div class="sync-info-box">
                        <div class="sync-info-num <?= $pending_count > 0 ? 'warn' : '' ?>"><?= $pending_count ?></div>
                        <div class="sync-info-label">INSERT/UPDATE</div>
                    </div>
                    <div class="sync-info-box">
                        <div class="sync-info-num <?= $deleted_count > 0 ? 'red' : '' ?>"><?= $deleted_count ?></div>
                        <div class="sync-info-label">DELETE</div>
                    </div>
                    <div class="sync-info-box">
                        <div class="sync-info-num <?= $days_oldest >= 3 ? 'red' : ($days_oldest > 0 ? 'warn' : '') ?>">
                            <?= $days_oldest > 0 ? round($days_oldest, 1) : '—' ?>
                        </div>
                        <div class="sync-info-label">Hari tertunda</div>
                    </div>
                </div>
            </div>

            <!-- Input IP device tujuan -->
            <div class="sync-panel">
                <div class="sync-panel-title">🌐 TARGET DEVICE</div>

                <?php if ($last_sync_info): ?>
                <div class="last-sync-info">
                    🕐 Sync terakhir: <span><?= date('d M Y, H:i', strtotime($last_sync_info['synced_at'])) ?></span>
                    — <?= $last_sync_info['direction'] === 'push' ? '📤 Push' : '📥 Pull' ?>
                    ke/dari <span><?= htmlspecialchars($last_sync_info['target_ip']) ?></span>
                    (<?= $last_sync_info['total_rows'] ?> row, <?= $last_sync_info['total_dels'] ?> delete)
                </div>
                <?php endif; ?>

                <div class="ip-input-wrap">
                    <input type="text" class="ip-input" id="target-ip"
                        placeholder="192.168.1.10 atau 192.168.1.10:8080"
                        value="<?= htmlspecialchars($last_sync_info['target_ip'] ?? '') ?>">
                    <button class="btn-ping" onclick="pingTarget()">📡 Ping</button>
                </div>

                <!-- Status target device -->
                <div id="target-status" class="status-badge status-waiting">
                    <span class="status-dot"></span>
                    <span id="target-status-text">Belum dicek</span>
                </div>

                <!-- Info pending di target (setelah ping) -->
                <div id="target-pending-info" style="display:none;" class="last-sync-info">
                    Device tujuan punya: <span id="target-pending-count">—</span> data pending
                </div>

                <!-- Progress bar -->
                <div class="sync-progress-wrap" id="sync-progress-wrap">
                    <div class="sync-progress-label">
                        <span id="sync-progress-label">Mempersiapkan...</span>
                        <span id="sync-progress-pct">0%</span>
                    </div>
                    <div class="sync-progress-bar">
                        <div class="sync-progress-fill" id="sync-progress-fill"></div>
                    </div>
                </div>

                <!-- Tombol Push & Pull -->
                <button class="sync-btn push disabled" id="pushBtn" onclick="startPush()" disabled>
                    📤 PUSH — Kirim data device ini ke target
                </button>
                <button class="sync-btn pull disabled" id="pullBtn" onclick="startPull()" disabled>
                    📥 PULL — Ambil data dari target ke device ini
                </button>

                <div class="sync-result" id="sync-result"></div>
                <div class="sync-log" id="sync-log"></div>
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
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
let syncRunning = false;
let targetOnline = false;
let targetPendingCount = 0;

// ── Tab switch ────────────────────────────────────────────────
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if (IS_ADMIN && document.getElementById('tab-activity'))
        document.getElementById('tab-activity').style.display = tab === 'activity' ? '' : 'none';
    document.getElementById('tab-sync').style.display = tab === 'sync' ? '' : 'none';
}

// ── Filter log ────────────────────────────────────────────────
function filterLogs(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.log-card').forEach(card => {
        if (type === 'all')      card.style.display = '';
        else if (type === 'unsynced') card.style.display = card.dataset.synced === '0' ? '' : 'none';
        else card.style.display = card.dataset.action === type ? '' : 'none';
    });
}

// ── Dismiss log ───────────────────────────────────────────────
function dismissLog(logId, btn) {
    btn.disabled = true; btn.textContent = '...';
    fetch('activity_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=dismiss&log_id=' + logId
    }).then(r => r.json()).then(d => {
        if (d.success) {
            const card = btn.closest('.log-card');
            card.style.opacity = '0'; card.style.transition = 'opacity 0.3s';
            setTimeout(() => card.remove(), 300);
        }
    });
}

// ── Helpers ───────────────────────────────────────────────────
function getTargetBase() {
    const ip = document.getElementById('target-ip').value.trim();
    if (!ip) return null;
    // Jika sudah ada port, pakai langsung
    if (ip.includes(':')) return 'http://' + ip;
    return 'http://' + ip;
}

function buildApiUrl(targetBase, action) {
    return targetBase + '/optic_pos/sync_api.php?action=' + action;
}

function setProgress(label, pct) {
    document.getElementById('sync-progress-wrap').style.display = 'block';
    document.getElementById('sync-progress-label').textContent  = label;
    document.getElementById('sync-progress-pct').textContent    = Math.round(pct) + '%';
    document.getElementById('sync-progress-fill').style.width   = pct + '%';
}

function addLog(msg) {
    const log = document.getElementById('sync-log');
    log.style.display = 'block';
    const line = document.createElement('div');
    line.className = 'sync-log-line';
    line.textContent = msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}

function showResult(msg, isSuccess) {
    const el = document.getElementById('sync-result');
    el.style.display = 'block';
    el.className = 'sync-result ' + (isSuccess ? 'success' : 'error');
    el.textContent = msg;
}

function resetButtons() {
    syncRunning = false;
    updateButtons();
}

function updateButtons() {
    const pushBtn = document.getElementById('pushBtn');
    const pullBtn = document.getElementById('pullBtn');
    if (targetOnline) {
        pushBtn.disabled = false;
        pushBtn.className = 'sync-btn push';
        pullBtn.disabled = false;
        pullBtn.className = 'sync-btn pull';
    } else {
        pushBtn.disabled = true; pushBtn.className = 'sync-btn push disabled';
        pullBtn.disabled = true; pullBtn.className = 'sync-btn pull disabled';
    }
}

// ── PING target device ────────────────────────────────────────
async function pingTarget() {
    const base = getTargetBase();
    if (!base) { alert('Masukkan IP device tujuan dulu.'); return; }

    const badge = document.getElementById('target-status');
    const text  = document.getElementById('target-status-text');
    badge.className = 'status-badge status-waiting';
    text.textContent = 'Memeriksa...';
    document.getElementById('target-pending-info').style.display = 'none';

    try {
        const res  = await fetch(buildApiUrl(base, 'ping'), { method: 'POST' });
        const data = await res.json();

        if (data.online) {
            targetOnline = true;
            targetPendingCount = data.total_pending || 0;
            badge.className = 'status-badge status-online';
            text.textContent = 'Online — ' + (data.device || base) + ' · ' + data.server_time;

            const info = document.getElementById('target-pending-info');
            document.getElementById('target-pending-count').textContent = targetPendingCount;
            info.style.display = 'block';
        } else {
            targetOnline = false;
            badge.className = 'status-badge status-offline';
            text.textContent = 'Tidak merespons';
        }
    } catch(e) {
        targetOnline = false;
        badge.className = 'status-badge status-offline';
        text.textContent = 'Tidak dapat dijangkau — cek IP dan koneksi WiFi';
    }
    updateButtons();
}

// ── PUSH: kirim data device ini ke target ────────────────────
async function startPush() {
    if (syncRunning) return;
    if (!confirm('Push semua data pending device ini ke ' + document.getElementById('target-ip').value + '?')) return;

    syncRunning = true;
    document.getElementById('pushBtn').disabled = true;
    document.getElementById('pullBtn').disabled = true;
    document.getElementById('sync-log').innerHTML = '';
    document.getElementById('sync-result').style.display = 'none';

    const base = getTargetBase();
    setProgress('Mengambil data pending...', 10);
    addLog('▶ Mulai push — ' + new Date().toLocaleTimeString());

    try {
        // 1. Ambil pending dari device ini (lokal)
        setProgress('Mengambil data dari device ini...', 20);
        const localRes  = await fetch('sync_api.php?action=get_pending', { method: 'POST' });
        const localData = await localRes.json();

        const total = localData.total || 0;
        if (total === 0) {
            showResult('✓ Tidak ada data untuk di-push.', true);
            addLog('Tidak ada data pending.');
            resetButtons(); return;
        }
        addLog('📦 ' + total + ' item akan dikirim ke target.');

        // 2. Kirim ke target
        setProgress('Mengirim ke device tujuan...', 50);
        const applyRes  = await fetch(buildApiUrl(base, 'apply_changes'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                pending_rows: localData.pending_rows,
                deleted_rows: localData.deleted_rows,
            })
        });
        const applyData = await applyRes.json();

        if (applyData.error) {
            showResult('✗ Error: ' + applyData.error, false);
            addLog('✗ ' + applyData.error);
            resetButtons(); return;
        }

        addLog('✓ Target apply: ' + applyData.applied + ' berhasil, ' +
               applyData.skipped + ' skip, ' + applyData.errors + ' error.');

        // 3. Konfirmasi ke device ini bahwa push berhasil → bersihkan pending_sync
        setProgress('Finalisasi...', 85);
        const confirmRes  = await fetch('sync_api.php?action=confirm_synced', { method: 'POST' });
        const confirmData = await confirmRes.json();

        // 4. Catat ke last_sync
        setProgress('Menyimpan riwayat...', 95);
        await fetch('sync_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_last_sync&direction=push&target_ip=' +
                  encodeURIComponent(document.getElementById('target-ip').value) +
                  '&total_rows=' + applyData.applied +
                  '&total_dels=' + (localData.deleted_rows?.length || 0)
        });

        setProgress('Selesai!', 100);
        addLog('✅ Push selesai pada ' + new Date().toLocaleTimeString());
        showResult('✅ Push berhasil! ' + applyData.applied + ' data terkirim ke target.', true);

        // Update info pending
        document.querySelector('.sync-info-num.warn, .sync-info-num').textContent = '0';

    } catch(e) {
        showResult('✗ Gagal: ' + e.message, false);
        addLog('✗ Error: ' + e.message);
    }
    resetButtons();
}

// ── PULL: ambil data dari target ke device ini ────────────────
async function startPull() {
    if (syncRunning) return;
    if (!confirm('Pull semua data pending dari ' + document.getElementById('target-ip').value + ' ke device ini?')) return;

    syncRunning = true;
    document.getElementById('pushBtn').disabled = true;
    document.getElementById('pullBtn').disabled = true;
    document.getElementById('sync-log').innerHTML = '';
    document.getElementById('sync-result').style.display = 'none';

    const base = getTargetBase();
    addLog('▶ Mulai pull — ' + new Date().toLocaleTimeString());

    try {
        // 1. Ambil pending dari target
        setProgress('Mengambil data dari target...', 20);
        const targetRes  = await fetch(buildApiUrl(base, 'get_pending'), { method: 'POST' });
        const targetData = await targetRes.json();

        const total = targetData.total || 0;
        if (total === 0) {
            showResult('✓ Tidak ada data baru di target.', true);
            addLog('Target tidak punya data pending.');
            resetButtons(); return;
        }
        addLog('📦 ' + total + ' item akan ditarik dari target.');

        // 2. Apply ke device ini (lokal)
        setProgress('Menerapkan data ke device ini...', 55);
        const applyRes  = await fetch('sync_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                pending_rows: targetData.pending_rows,
                deleted_rows: targetData.deleted_rows,
            })
        });

        // Perlu baca body sebagai text dulu karena sync_api action apply_changes via JSON
        const applyText = await applyRes.text();
        let applyData;
        try { applyData = JSON.parse(applyText); }
        catch(e) { throw new Error('Response tidak valid dari sync_api: ' + applyText.substring(0,100)); }

        if (applyData.error) {
            showResult('✗ Error: ' + applyData.error, false);
            addLog('✗ ' + applyData.error);
            resetButtons(); return;
        }
        addLog('✓ Apply lokal: ' + applyData.applied + ' berhasil, ' +
               applyData.skipped + ' skip, ' + applyData.errors + ' error.');

        // 3. Beritahu target bahwa pull sudah selesai → target bersihkan pending_sync-nya
        setProgress('Konfirmasi ke target...', 85);
        await fetch(buildApiUrl(base, 'confirm_synced'), { method: 'POST' });

        // 4. Catat ke last_sync
        setProgress('Menyimpan riwayat...', 95);
        await fetch('sync_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_last_sync&direction=pull&target_ip=' +
                  encodeURIComponent(document.getElementById('target-ip').value) +
                  '&total_rows=' + applyData.applied +
                  '&total_dels=' + (targetData.deleted_rows?.length || 0)
        });

        setProgress('Selesai!', 100);
        addLog('✅ Pull selesai pada ' + new Date().toLocaleTimeString());
        showResult('✅ Pull berhasil! ' + applyData.applied + ' data diterima dari target.', true);

    } catch(e) {
        showResult('✗ Gagal: ' + e.message, false);
        addLog('✗ Error: ' + e.message);
    }
    resetButtons();
}

// ── Ping otomatis jika ada IP tersimpan ───────────────────────
window.addEventListener('DOMContentLoaded', () => {
    const savedIp = document.getElementById('target-ip').value;
    if (savedIp) pingTarget();
});
</script>
</body>
</html>