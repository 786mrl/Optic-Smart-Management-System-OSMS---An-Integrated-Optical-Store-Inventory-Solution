<?php
// activity_log.php — Admin Activity Monitor
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

include 'db_config.php';
include 'config_helper.php';

// Handle manual turn off sync_flag
if (isset($_POST['action']) && $_POST['action'] === 'dismiss') {
    $log_id = (int)($_POST['log_id'] ?? 0);
    if ($log_id > 0) {
        $conn->query("UPDATE activity_log SET sync_flag = 0 WHERE id = $log_id");
        $conn->query("DELETE FROM sync_status WHERE log_id = $log_id");

        // Also update Supabase
        include_once 'activity_helper.php';
        supabase_req_ah('/rest/v1/activity_log?id=eq.' . $log_id, 'PATCH',
            ['sync_flag' => 0],
            ['Content-Type: application/json', 'apikey: ' . SUPABASE_KEY_AH,
             'Authorization: Bearer ' . SUPABASE_KEY_AH, 'Prefer: resolution=merge-duplicates,return=minimal']
        );
        supabase_req_ah('/rest/v1/sync_status?log_id=eq.' . $log_id, 'DELETE', null,
            ['Content-Type: application/json', 'apikey: ' . SUPABASE_KEY_AH,
             'Authorization: Bearer ' . SUPABASE_KEY_AH]
        );
        supabase_req_ah('/rest/v1/activity_log?id=eq.' . $log_id, 'DELETE', null,
            ['Content-Type: application/json', 'apikey: ' . SUPABASE_KEY_AH,
             'Authorization: Bearer ' . SUPABASE_KEY_AH]
        );

        echo json_encode(['success' => true]);
        exit();
    }
}

// Get activity logs
$logs = [];
$result = $conn->query("SELECT * FROM activity_log ORDER BY changed_at DESC LIMIT 200");
if ($result) while ($row = $result->fetch_assoc()) $logs[] = $row;

// Get sync status per log
$sync_map = [];
$ss = $conn->query("SELECT log_id, username FROM sync_status");
if ($ss) while ($row = $ss->fetch_assoc()) {
    $sync_map[$row['log_id']][] = $row['username'];
}

// Get total approved users
$ur = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_approved = 1");
$total_users = $ur ? (int)$ur->fetch_assoc()['total'] : 1;

// Page URL map for quick links
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
    <title>Activity Log</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { padding: 0; }
        .log-card {
            background: #25282a;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 4px 4px 9px #1a1c1d, -2px -2px 6px #2e3234;
            transition: box-shadow 0.2s;
        }
        .log-card:hover { box-shadow: 6px 6px 12px #1a1c1d, -3px -3px 8px #2e3234; }
        .log-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
        .log-action {
            display: inline-block; padding: 3px 10px; border-radius: 6px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
        }
        .action-INSERT { background: rgba(0,255,136,0.1); color: #00ff88; border: 1px solid rgba(0,255,136,0.2); }
        .action-UPDATE { background: rgba(0,204,255,0.1); color: #00ccff; border: 1px solid rgba(0,204,255,0.2); }
        .action-DELETE { background: rgba(255,107,107,0.1); color: #ff6b6b; border: 1px solid rgba(255,107,107,0.2); }
        .log-meta { font-size: 12px; color: #888; }
        .log-meta span { color: #ccc; }
        .sync-bar { margin-top: 10px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .sync-progress {
            flex: 1; height: 4px; background: #1e2022; border-radius: 2px;
            box-shadow: inset 1px 1px 3px #1a1c1d; min-width: 80px;
        }
        .sync-fill { height: 100%; border-radius: 2px; background: #00ff88; transition: width 0.3s; }
        .sync-text { font-size: 11px; color: #888; white-space: nowrap; }
        .sync-text span { color: #00ff88; }
        .log-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-view {
            background: #1e2022; border: 1px solid rgba(0,204,255,0.2);
            border-radius: 8px; padding: 5px 12px; color: #00ccff;
            font-size: 11px; cursor: pointer; text-decoration: none;
            box-shadow: 2px 2px 5px #1a1c1d;
        }
        .btn-view:hover { background: rgba(0,204,255,0.1); }
        .btn-dismiss {
            background: #1e2022; border: 1px solid rgba(246,166,35,0.2);
            border-radius: 8px; padding: 5px 12px; color: #f6a623;
            font-size: 11px; cursor: pointer;
            box-shadow: 2px 2px 5px #1a1c1d;
        }
        .btn-dismiss:hover { background: rgba(246,166,35,0.1); }
        .empty-state { text-align: center; padding: 40px; color: #444; font-size: 14px; }
        .filter-bar { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .filter-btn {
            background: #1e2022; border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px; padding: 6px 14px; color: #888;
            font-size: 12px; cursor: pointer; transition: all 0.15s;
        }
        .filter-btn.active, .filter-btn:hover {
            border-color: rgba(0,204,255,0.3); color: #00ccff;
            background: rgba(0,204,255,0.05);
        }
        .pending-badge {
            display: inline-block; background: rgba(246,166,35,0.15);
            color: #f6a623; border-radius: 10px; padding: 2px 8px;
            font-size: 11px; font-weight: 600; margin-left: 8px;
        }
    </style>
</head>
<body>
<div class="main-wrapper">
    <div class="content-area" style="flex-direction:column">

        <div class="header-container" style="margin-left: auto; margin-right: auto; width: 100%;">
            <button class="logout-btn" onclick="window.location.href='logout.php';">
                <span>Logout</span>
            </button>
            <div class="brand-section">
                <div class="logo-box">
                    <img src="<?= htmlspecialchars($BRAND_IMAGE_PATH) ?>" alt="Brand Logo" style="height:40px;">
                </div>
                <h1 class="company-name"><?= htmlspecialchars($STORE_NAME) ?></h1>
                <p class="company-address"><?= htmlspecialchars($STORE_ADDRESS) ?></p>
            </div>
        </div>

        <div class="main-card">
            <h2>
                ACTIVITY LOG
                <?php $pending = count(array_filter($logs, function($l) { return $l['sync_flag'] == 1; })); ?>
                <?php if ($pending > 0): ?>
                <span class="pending-badge">⏳ <?= $pending ?> pending</span>
                <?php endif; ?>
            </h2>
            <p style="color:#666; font-size:12px; margin-bottom:16px;">
                All data changes across all devices — <?= count($logs) ?> records
            </p>

            <!-- Filter bar -->
            <div class="filter-bar">
                <button class="filter-btn active" onclick="filterLogs('all', this)">All</button>
                <button class="filter-btn" onclick="filterLogs('INSERT', this)">Insert</button>
                <button class="filter-btn" onclick="filterLogs('UPDATE', this)">Update</button>
                <button class="filter-btn" onclick="filterLogs('DELETE', this)">Delete</button>
                <button class="filter-btn" onclick="filterLogs('pending', this)">Pending Only</button>
            </div>

            <?php if (empty($logs)): ?>
            <div class="empty-state">
                ✓ No activity recorded yet
            </div>
            <?php else: ?>

            <div id="log-list">
            <?php foreach ($logs as $log):
                $log_id     = $log['id'];
                $applied    = $sync_map[$log_id] ?? [];
                $applied_count = count($applied);
                $pct        = $total_users > 0 ? min(100, round($applied_count / $total_users * 100)) : 0;
                $page_url   = $page_map[$log['table_name']] ?? '#';
                $is_pending = $log['sync_flag'] == 1;
            ?>
            <div class="log-card" data-action="<?= $log['action'] ?>" data-pending="<?= $is_pending ? '1' : '0' ?>">
                <div class="log-header">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span class="log-action action-<?= $log['action'] ?>"><?= $log['action'] ?></span>
                        <span style="color:#ccc; font-size:13px; font-weight:600;"><?= htmlspecialchars($log['table_name']) ?></span>
                        <span style="color:#555; font-size:12px; font-family:monospace;">
                            ID: <?= htmlspecialchars($log['record_id']) ?>
                        </span>
                    </div>
                    <div class="log-actions">
                        <?php if ($page_url !== '#'): ?>
                        <a href="<?= $page_url ?>" class="btn-view">→ View Page</a>
                        <?php endif; ?>
                        <?php if ($is_pending): ?>
                        <button class="btn-dismiss" onclick="dismissLog(<?= $log_id ?>, this)">✕ Dismiss</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="log-meta">
                    By: <span><?= htmlspecialchars($log['changed_by']) ?></span>
                    &nbsp;·&nbsp;
                    <?= date('d M Y, H:i', strtotime($log['changed_at'])) ?>
                    &nbsp;·&nbsp;
                    Status: <span><?= $is_pending ? '<span style="color:#f6a623">Syncing</span>' : '<span style="color:#00ff88">Done</span>' ?></span>
                </div>

                <?php if ($is_pending): ?>
                <div class="sync-bar">
                    <div class="sync-progress">
                        <div class="sync-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="sync-text">
                        <span><?= $applied_count ?>/<?= $total_users ?></span> devices synced
                        <?php if (!empty($applied)): ?>
                        (<?= implode(', ', array_map('htmlspecialchars', $applied)) ?>)
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

        <div style="padding: 0 16px 16px;">
            <button type="button" class="back-main" style="width:100%;"
                onclick="window.location.href='index.php'">
                BACK TO MAIN MENU
            </button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?= $COPYRIGHT_FOOTER ?></p>
        </footer>
    </div>
</div>

<script>
function filterLogs(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    document.querySelectorAll('.log-card').forEach(card => {
        if (type === 'all') {
            card.style.display = '';
        } else if (type === 'pending') {
            card.style.display = card.dataset.pending === '1' ? '' : 'none';
        } else {
            card.style.display = card.dataset.action === type ? '' : 'none';
        }
    });
}

function dismissLog(logId, btn) {
    if (!confirm('Dismiss this log entry?\nAll devices will stop syncing this change.')) return;
    btn.disabled = true;
    btn.textContent = '...';

    fetch('activity_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=dismiss&log_id=' + logId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const card = btn.closest('.log-card');
            card.style.transition = 'opacity 0.3s';
            card.style.opacity = '0';
            setTimeout(() => card.remove(), 300);
        }
    });
}
</script>
</body>
</html>