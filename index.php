<?php
// index.php theme: Dark Neumorphism
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_role = $_SESSION['role'];
$username = $_SESSION['username'];

include 'db_config.php';
include 'activity_helper.php';
include 'config_helper.php';
include 'auth_check.php';

// ── Pending sync warning ──────────────────────────────────────
$_pending_days  = get_oldest_pending_days($conn);
$_pending_count = get_pending_count($conn);
$_deleted_count = get_pending_deleted_count($conn);
$_show_warning  = $_pending_days >= 3 && ($_pending_count + $_deleted_count) > 0;

// ── Cek update baru dari cloud ────────────────────────────────
$_safe_user      = $conn->real_escape_string($_SESSION['username'] ?? '');
$_new_from_cloud = 0;
$_ck = $conn->query("SELECT COUNT(*) AS total FROM activity_log al
    WHERE al.sync_flag = 1 AND NOT EXISTS (
        SELECT 1 FROM sync_status ss
        WHERE ss.log_id = al.id AND ss.username = '$_safe_user'
    )");
if ($_ck) $_new_from_cloud = (int)$_ck->fetch_assoc()['total'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optical Store System - Main Menu</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="main-wrapper">
    <div class="content-area">
        <div class="header-container">
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
        
        <div class="selection-container">
            <div class="button-grid">
                <button class="neu-button" data-url="inventory.php" onclick="handleButtonClick(this)">
                    <span class="icon">🏬</span>
                    Inventory Management
                    <div class="led"></div>
                </button>
            
                <button class="neu-button" data-url="customer.php" onclick="handleButtonClick(this)">
                    <span class="icon">📇</span>
                    Customer Data Management
                    <div class="led"></div>
                </button>
            
                <?php if ($current_role === 'admin'): ?>
                    <button class="neu-button" data-url="admin.php" onclick="handleButtonClick(this)">
                        <span class="icon">⚙️</span>
                        Administration
                        <div class="led"></div>
                    </button>
            
                    <button class="neu-button" data-url="bi_report.php" onclick="handleButtonClick(this)">
                        <span class="icon">📊</span>
                        Business Intelligence Report
                        <div class="led"></div>
                    </button>

                    <button class="neu-button" data-url="activity_log.php" onclick="handleButtonClick(this)">
                        <span class="icon">📋</span>
                        Activity Log & Sync
                        <?php
                        $pending_count = 0;
                        $al = $conn->query("SELECT COUNT(*) as c FROM activity_log WHERE sync_flag = 1");
                        if ($al) $pending_count = (int)$al->fetch_assoc()['c'];
                        ?>
                        <?php if ($pending_count > 0): ?>
                        <span style="
                            position:absolute; top:8px; right:8px;
                            background:#f6a623; color:#1e2022;
                            border-radius:10px; padding:2px 7px;
                            font-size:10px; font-weight:700;
                        "><?= $pending_count ?></span>
                        <?php endif; ?>
                        <div class="led"></div>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="footer-container">
        <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>
</div>

    <script>
        function handleButtonClick(element) {
            const targetUrl = element.getAttribute('data-url');
            localStorage.setItem('activeMenuUrl', targetUrl);
            document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');
            window.location.href = targetUrl;
        }

        window.addEventListener('DOMContentLoaded', () => {
            const activeUrl = localStorage.getItem('activeMenuUrl');
            if (activeUrl) {
                document.querySelectorAll('.neu-button').forEach(btn => {
                    if (btn.getAttribute('data-url') === activeUrl) {
                        btn.classList.add('active');
                    }
                });
            }
        });
    </script>
    <?php if ($_show_warning): ?>
    <div id="sync-overlay" style="
        position:fixed; inset:0; background:rgba(0,0,0,0.85);
        z-index:9999; display:flex; align-items:center; justify-content:center;
        backdrop-filter:blur(4px); padding:16px; box-sizing:border-box;">
        <div style="
            background:#1e2022; border-radius:18px; padding:28px 24px;
            max-width:380px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.6);
            border:1px solid rgba(255,107,107,0.3); text-align:center;">
            <div style="font-size:36px; margin-bottom:12px;">⚠️</div>
            <h3 style="color:#ff6b6b; font-size:16px; margin:0 0 10px 0; font-weight:700;">
                DATA BELUM DI-UPLOAD
            </h3>
            <p style="color:#888; font-size:13px; margin:0 0 16px 0; line-height:1.6;">
                Ada <strong style="color:#f6a623"><?= $_pending_count + $_deleted_count ?> data</strong>
                yang belum di-push ke cloud sejak
                <strong style="color:#ff6b6b"><?= round($_pending_days, 0) ?> hari yang lalu</strong>.
                <br><br>
                Upload minimal setiap <strong>3 hari sekali</strong>.
                Upload hanya bisa dilakukan mulai pukul <strong>20:30</strong>.
            </p>
            <?php $can_push = ((int)date('H') * 60 + (int)date('i')) >= (20 * 60 + 30); ?>
            <?php if ($can_push): ?>
            <a href="activity_log.php" style="
                display:block; background:linear-gradient(135deg,#ff6b6b,#f6a623);
                color:#0d0f10; padding:14px; border-radius:10px; font-size:14px;
                font-weight:700; text-decoration:none; margin-bottom:10px;">
                ☁ PUSH SEKARANG
            </a>
            <?php else: ?>
            <div style="
                background:#2c1a0d; border:1px solid rgba(246,166,35,0.3);
                border-radius:10px; padding:12px; font-size:12px; color:#f6a623; margin-bottom:14px;">
                ⏰ Push tersedia mulai jam <strong>20:30</strong>.
                Sekarang: <strong><?= date('H:i') ?></strong>
            </div>
            <a href="activity_log.php" style="
                display:block; background:#1a2c1e; color:#00ff88; padding:12px;
                border-radius:10px; font-size:13px; font-weight:600;
                text-decoration:none; margin-bottom:10px;
                border:1px solid rgba(0,255,136,0.2);">
                📋 Lihat Detail Activity Log
            </a>
            <?php endif; ?>
            <button onclick="document.getElementById('sync-overlay').remove()" style="
                background:transparent; border:1px solid rgba(255,255,255,0.1);
                color:#555; padding:10px; border-radius:8px; font-size:12px;
                cursor:pointer; width:100%;">
                Lanjut ke Dashboard
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($_new_from_cloud > 0): ?>
    <div id="cloud-update-toast" style="
        position:fixed; bottom:20px; left:50%; transform:translateX(-50%);
        z-index:9998; background:#1a2c1e; border:1px solid rgba(0,255,136,0.3);
        border-radius:14px; padding:14px 18px; display:flex; align-items:center;
        gap:12px; box-shadow:0 8px 30px rgba(0,0,0,0.5); max-width:340px; width:90%;">
        <span style="font-size:22px;">🔄</span>
        <div style="flex:1;">
            <div style="color:#00ff88; font-size:13px; font-weight:700; margin-bottom:2px;">
                Ada <?= $_new_from_cloud ?> update dari cloud
            </div>
            <div style="color:#666; font-size:11px;">Data baru dari device lain tersedia.</div>
        </div>
        <div style="display:flex; flex-direction:column; gap:6px;">
            <button onclick="applyCloudUpdate()" style="
                background:#00ff88; color:#0d0f10; border:none; border-radius:7px;
                padding:6px 12px; font-size:11px; font-weight:700; cursor:pointer;">
                Terapkan
            </button>
            <button onclick="document.getElementById('cloud-update-toast').remove()" style="
                background:transparent; border:1px solid rgba(255,255,255,0.1);
                color:#555; border-radius:7px; padding:6px 12px; font-size:11px; cursor:pointer;">
                Nanti
            </button>
        </div>
    </div>
    <script>
    function applyCloudUpdate() {
        const toast = document.getElementById('cloud-update-toast');
        toast.innerHTML = '<span style="font-size:20px">⟳</span><span style="color:#00ff88;font-size:13px;margin-left:10px;">Menerapkan update...</span>';
        fetch('sync_background.php', { method: 'GET' })
            .then(() => {
                toast.innerHTML = '<span style="font-size:20px">✅</span><span style="color:#00ff88;font-size:13px;margin-left:10px;">Selesai! Reload...</span>';
                setTimeout(() => location.reload(), 1500);
            })
            .catch(() => {
                toast.innerHTML = '<span style="color:#ff6b6b;font-size:13px;">Gagal. Coba lagi nanti.</span>';
                setTimeout(() => toast.remove(), 3000);
            });
    }
    </script>
    <?php endif; ?>

</body>
</html>