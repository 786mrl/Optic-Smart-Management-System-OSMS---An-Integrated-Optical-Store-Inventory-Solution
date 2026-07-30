<?php
    date_default_timezone_set('Asia/Jakarta');
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    include 'auth_check.php';

    // Pastikan user sudah login
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }

    $username = $_SESSION['username'] ?? 'User';
    $role = $_SESSION['role'] ?? 'staff'; 

    // Checks whether any of the given items has ever been logged in
    // activity_log. Used to determine if a tracked button should be locked.
    // Uses exact match (=) rather than LIKE, since each row stores exactly
    // one item as-is; a wildcard match would cause a substring item like
    // "qrcodes [folder]" to also match an unrelated item that merely
    // contains it, such as "main_qrcodes [folder]".
    function isAnyItemLogged($conn, $items) {
        $checkLogStmt = $conn->prepare("SELECT id FROM activity_log WHERE list = ? LIMIT 1");
        foreach ($items as $item) {
            $checkLogStmt->bind_param("s", $item);
            $checkLogStmt->execute();
            $checkLogResult = $checkLogStmt->get_result();
            if ($checkLogResult && $checkLogResult->num_rows > 0) {
                $checkLogStmt->close();
                return true;
            }
        }
        $checkLogStmt->close();
        return false;
    }

    // Blocking time is read from the settings table (same setting_key used
    // elsewhere), with a fallback default if the row doesn't exist yet.
    $blockingTime = '20:30';
    $settingStmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $settingKey = 'db_backup_blocking_time';
    $settingStmt->bind_param("s", $settingKey);
    $settingStmt->execute();
    $settingResult = $settingStmt->get_result();
    if ($settingResult && $settingResult->num_rows > 0) {
        $settingRow = $settingResult->fetch_assoc();
        if (!empty($settingRow['setting_value'])) {
            $blockingTime = $settingRow['setting_value'];
        }
    }
    $settingStmt->close();

    $currentTime = date('H:i');
    $timeReached = ($currentTime >= $blockingTime);

    // Frame Data Entry is locked only when its single tracked log label
    // has been logged in activity_log AND the current time is at/after the
    // blocking time configured in the settings table.
    $frameDataEntryItems = ['FRAME DATA ENTRY'];
    $frameDataEntryLocked = isAnyItemLogged($conn, $frameDataEntryItems) && $timeReached;

    // Pending Records (Staging) follows the same rule, checked independently
    // against its own tracked log label.
    $pendingRecordsItems = ['PENDING RECORDS (STAGING)'];
    $pendingRecordsLocked = isAnyItemLogged($conn, $pendingRecordsItems) && $timeReached;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frame Management - <?php echo htmlspecialchars($STORE_NAME); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .neu-button.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
            filter: grayscale(1);
        }

        /* ===== New neumorphic style for Back & Logout buttons ===== */
        .neu-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #1c1e22;
            border: none;
            border-radius: 32px;
            padding: 6px 16px 6px 6px;
            cursor: pointer;
            box-shadow:
                6px 6px 14px rgba(0, 0, 0, 0.55),
                -6px -6px 14px rgba(255, 255, 255, 0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            font-family: inherit;
        }

        .neu-pill-btn:hover {
            box-shadow:
                6px 6px 16px rgba(0, 0, 0, 0.6),
                -6px -6px 16px rgba(255, 255, 255, 0.04);
        }

        .neu-pill-btn:active {
            transform: scale(0.96);
        }

        /* Overflow hidden so the icon can slide across without spilling out */
        .neu-pill-btn {
            overflow: hidden;
        }

        .neu-pill-icon {
            width: 32px;
            height: 32px;
            min-width: 32px;
            border-radius: 50%;
            background: #17181b;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(103, 232, 249, 0.35);
            transition: box-shadow 0.15s ease, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Pressed state: icon slides to the right, text fades and slides out */
        .neu-pill-btn.pressed {
            box-shadow:
                inset 4px 4px 10px rgba(0, 0, 0, 0.6),
                inset -4px -4px 10px rgba(255, 255, 255, 0.03);
        }

        .neu-pill-btn.pressed .neu-pill-icon {
            transform: translateX(calc(100% + 24px));
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-btn.pressed .neu-pill-text {
            opacity: 0;
            transform: translateX(15px);
        }

        .neu-pill-btn.pressed .neu-pill-icon,
        .neu-pill-btn:active .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-icon svg {
            width: 15px;
            height: 15px;
            stroke: #7fe3f0;
            filter: drop-shadow(0 0 4px rgba(103, 232, 249, 0.8));
        }

        .neu-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            text-align: left;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .neu-pill-text .line1 {
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.4px;
            color: #f2f2f2;
        }

        .neu-pill-text .line2 {
            font-weight: 400;
            font-size: 9px;
            letter-spacing: 0.4px;
            color: #9a9da1;
        }

        /* Logout variant: warm amber/orange tone instead of cyan */
        .neu-pill-btn.logout-variant .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(255, 138, 101, 0.4);
        }

        .neu-pill-btn.logout-variant.pressed .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(255, 138, 101, 0.75);
        }

        .neu-pill-btn.logout-variant .neu-pill-icon svg {
            stroke: #ff8a65;
            filter: drop-shadow(0 0 4px rgba(255, 138, 101, 0.8));
        }

        /* ===== Logo zoom (fly window) effect ===== */
        .logo-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0);
            backdrop-filter: blur(0px);
            -webkit-backdrop-filter: blur(0px);
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
        }

        .logo-backdrop.active {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 1;
            pointer-events: auto;
        }

        .logo-box img {
            cursor: pointer;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        top 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .logo-box img.zoomed {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(2.8);
            z-index: 1000;
        }

    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area">
            <div class="header-container">
                <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                    <span class="neu-pill-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </span>
                    <span class="neu-pill-text">
                        <span class="line1">LOGOUT</span>
                    </span>
                </button>
            
                <div class="brand-section">
                    <div class="logo-box">
                        <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                    </div>
                    <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                    <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                </div>
            </div>
            
            <div class="selection-container">
                <div class="button-grid">
                    <?php if ($frameDataEntryLocked): ?>
                        <button class="neu-button disabled" disabled title="Locked: already logged in activity history">
                            <span class="icon">📥</span>
                            Frame Data Entry
                            <div class="led"></div>
                        </button>
                    <?php else: ?>
                        <button class="neu-button" data-url="frame_data_entry.php" data-log-label="FRAME DATA ENTRY" onclick="handleButtonClick(this)">
                            <span class="icon">📥</span>
                            Frame Data Entry
                            <div class="led"></div>
                        </button>
                    <?php endif; ?>
                
                    <?php if ($pendingRecordsLocked): ?>
                        <button class="neu-button disabled" disabled title="Locked: already logged in activity history">
                            <span class="icon">⏳</span>
                            Pending Records (Staging)
                            <div class="led"></div>
                        </button>
                    <?php else: ?>
                        <button class="neu-button" data-url="pending_records_frame.php" data-log-label="PENDING RECORDS (STAGING)" onclick="handleButtonClick(this)">
                            <span class="icon">⏳</span>
                            Pending Records (Staging)
                            <div class="led"></div>
                        </button>
                    <?php endif; ?>
                
                    <button class="neu-button" data-url="scan_frame.php" onclick="handleButtonClick(this)">
                        <span class="icon">📷</span>
                        Scan Frame (Preview)
                        <div class="led"></div>
                    </button>
                
                    <?php if ($role === 'admin'): ?>
                        <button class="neu-button" data-url="frame_master_database.php" onclick="handleButtonClick(this)">
                            <span class="icon">🗄️</span>
                            Frame Master Database
                            <div class="led"></div>
                        </button>
                
                        <button class="neu-button" data-url="customer_frame_purchase.php" onclick="handleButtonClick(this)">
                            <span class="icon">📜</span>
                            Customer Purchase History
                            <div class="led"></div>
                        </button>
                    <?php endif; ?>
                </div>            
            </div>
        </div>

        <div class="btn-group">
            <button type="button" class="neu-pill-btn" id="backBtn" onclick="handleBackClick(this)">
                <span class="neu-pill-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                </span>
                <span class="neu-pill-text">
                    <span class="line1">RETURN TO</span>
                    <span class="line2">PREVIOUS PAGE</span>
                </span>
            </button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>
    <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>

    <script>
        // Single tap/click on the logo zooms it in (only if not already zoomed).
        function zoomInLogo(imgEl) {
            if (imgEl.classList.contains('zoomed')) return;
            imgEl.classList.add('zoomed');
            document.getElementById('logoBackdrop').classList.add('active');
        }

        // Double tap/click zooms it back out.
        function zoomOutLogo(imgEl) {
            imgEl.classList.remove('zoomed');
            document.getElementById('logoBackdrop').classList.remove('active');
        }

        // Actual pill-shrink animation for the Back button, then navigate.
        function runBackAnimation(element) {
            const icon = element.querySelector('.neu-pill-icon');
            const text = element.querySelector('.neu-pill-text');

            // Make sure nothing else fights with our manual animation.
            element.style.transition = 'none';
            text.style.transition = 'none';

            const startWidth = element.offsetWidth;
            // Target: just the round icon left, with the button's own
            // left/right padding preserved (6px left, 6px right when collapsed).
            const targetWidth = icon.offsetWidth + 12;

            // Hide the text immediately so only the shrinking pill is visible.
            text.style.opacity = '0';

            const duration = 400; // ms
            const startTime = performance.now();

            function step(now) {
                const elapsed = now - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);

                const currentWidth = startWidth - (startWidth - targetWidth) * eased;
                element.style.width = currentWidth + 'px';

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    window.location.href = 'inventory.php';
                }
            }
            requestAnimationFrame(step);
        }

        // Actual pressed animation for the Logout button, then log out.
        function runLogoutAnimation(element) {
            element.classList.add('pressed');
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 220);
        }

        // Reads the data-log-label attribute off a button.
        function getLogLabel(element) {
            return element.getAttribute('data-log-label') || '';
        }

        // Asks the server which of the given labels are NOT already logged
        // in activity_log (exact match). Only those are worth logging -
        // if a label's row already exists, logging it again is unnecessary.
        function fetchUnloggedItems(labels) {
            return fetch('check_logged_items.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ list: labels })
            })
            .then(res => res.json())
            .then(data => (data && data.success) ? data.unloggedItems : labels)
            .catch(() => labels); // if the check fails, fall back to logging everything
        }

        // Back and Logout no longer trigger any logging - they just run
        // their animation and proceed straight away.
        function handleBackClick(element) {
            runBackAnimation(element);
        }

        function handleLogoutClick(element) {
            runLogoutAnimation(element);
        }

        // Function executed when a grid menu button is clicked.
        // If the button is a tracked module (has data-log-label), its label
        // is logged to activity_log right here - but only if it hasn't been
        // logged before. Navigation happens either way, right after.
        function handleButtonClick(element) {
            const targetUrl = element.getAttribute('data-url');
            const logLabel = getLogLabel(element);

            const navigate = () => {
                // 1. Save this URL to localStorage as the active button identity
                localStorage.setItem('activeMenuUrl', targetUrl);

                // 2. Add the active class immediately (for an instant visual effect)
                document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
                element.classList.add('active');

                // 3. Navigate to the page
                window.location.href = targetUrl;
            };

            if (!logLabel) {
                navigate();
                return;
            }

            fetchUnloggedItems([logLabel]).then(unloggedLabels => {
                if (unloggedLabels.length === 0) {
                    // Already logged before - nothing new to save.
                    navigate();
                    return;
                }

                fetch('log_activity.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ list: unloggedLabels })
                })
                .then(navigate)
                .catch(navigate);
            });
        }

        // Function that runs automatically when the page is refreshed or returned to (Back)
        window.addEventListener('DOMContentLoaded', () => {
            const activeUrl = localStorage.getItem('activeMenuUrl');
            
            if (activeUrl) {
                document.querySelectorAll('.neu-button').forEach(btn => {
                    // If the button's data-url matches the one in memory, activate it!
                    if (btn.getAttribute('data-url') === activeUrl) {
                        btn.classList.add('active');
                    }
                });
            }
        });
    </script>
</body>
</html>