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

    // Frame Data Entry is locked only when at least one of its tracked items
    // has been logged in activity_log AND the current time is at/after the
    // blocking time configured in the settings table.
    $frameDataEntryItems = ['frame_staging', 'qrcodes [folder]', 'data_json [folder]', 'phpqrcode/cache [folder]'];
    $frameDataEntryLocked = isAnyItemLogged($conn, $frameDataEntryItems) && $timeReached;

    // Pending Records (Staging) follows the same rule, checked independently
    // against its own set of tracked items.
    $pendingRecordsItems = ['frame_staging', 'qrcodes [folder]', 'main_qrcodes [folder]', 'phpqrcode/cache [folder]'];
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

        /* ===== Affected-items confirmation fly window ===== */
        .affected-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0);
            backdrop-filter: blur(0px);
            -webkit-backdrop-filter: blur(0px);
            z-index: 1100;
            opacity: 0;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
        }

        .affected-backdrop.active {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 1;
            pointer-events: auto;
        }

        .affected-modal {
            background: #1c1e22;
            border-radius: 18px;
            padding: 24px;
            width: 90%;
            max-width: 420px;
            box-shadow:
                8px 8px 20px rgba(0, 0, 0, 0.55),
                -8px -8px 20px rgba(255, 255, 255, 0.03);
            transform: scale(0.9);
            opacity: 0;
            transition: transform 0.25s ease, opacity 0.25s ease;
        }

        .affected-backdrop.active .affected-modal {
            transform: scale(1);
            opacity: 1;
        }

        .affected-modal h2 {
            color: #f2f2f2;
            font-size: 15px;
            letter-spacing: 0.5px;
            margin: 0 0 16px 0;
            text-align: center;
        }

        .affected-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .affected-table th, .affected-table td {
            text-align: left;
            padding: 8px 10px;
            font-size: 13px;
            color: #e0e0e0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        /* Colored item name (list content) inside the affected-items table */
        .affected-table td.affected-item-name {
            color: #7fe3f0;
            font-weight: 600;
        }

        .affected-table th {
            color: #9a9da1;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .affected-table input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #ff8a65;
            cursor: pointer;
        }

        .affected-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .affected-actions button {
            border: none;
            border-radius: 24px;
            padding: 8px 18px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.4px;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .affected-actions button:active {
            transform: scale(0.96);
        }

        .affected-confirm-btn {
            background: #17181b;
            color: #7fe3f0;
            box-shadow:
                inset 2px 2px 5px rgba(0, 0, 0, 0.6),
                inset -2px -2px 5px rgba(255, 255, 255, 0.04);
        }

        .affected-cancel-btn {
            background: #17181b;
            color: #9a9da1;
            box-shadow:
                inset 2px 2px 5px rgba(0, 0, 0, 0.6),
                inset -2px -2px 5px rgba(255, 255, 255, 0.04);
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
                        <button class="neu-button" data-url="frame_data_entry.php" data-affected-items="frame_staging, qrcodes [folder], data_json [folder], phpqrcode/cache [folder]" onclick="handleButtonClick(this)">
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
                        <button class="neu-button" data-url="pending_records_frame.php" data-affected-items="frame_staging, qrcodes [folder], main_qrcodes [folder], phpqrcode/cache [folder]" onclick="handleButtonClick(this)">
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

    <!-- Fly window: confirmation before leaving the page after visiting a tracked module -->
    <div class="affected-backdrop" id="affectedBackdrop">
        <div class="affected-modal">
            <h2>AFFECTED FILES OR DATABASE</h2>
            <table class="affected-table">
                <thead>
                    <tr>
                        <th style="width: 30px;"></th>
                        <th>Item</th>
                    </tr>
                </thead>
                <tbody id="affectedTableBody">
                    <!-- Rows are rendered dynamically by JS based on the items tied to the button clicked -->
                </tbody>
            </table>
            <div class="affected-actions">
                <button type="button" class="affected-cancel-btn" onclick="cancelAffectedModal()">Cancel</button>
                <button type="button" class="affected-confirm-btn" onclick="confirmAffectedModal()">Confirm</button>
            </div>
        </div>
    </div>
    
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

        // Holds the action ('back' or 'logout') and element waiting to run
        // after the affected-items confirmation modal is resolved.
        let pendingAction = null;
        let pendingElement = null;

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

        function openAffectedModal(items) {
            // Render one checkbox row per affected item. Each item gets its
            // own row/state so they can be selected or deselected individually.
            const tbody = document.getElementById('affectedTableBody');
            tbody.innerHTML = '';
            items.forEach((item, index) => {
                const row = document.createElement('tr');

                const checkboxCell = document.createElement('td');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = true;
                checkbox.className = 'affected-item-checkbox';
                checkbox.setAttribute('data-item', item);
                checkbox.id = 'affectedItemCheckbox' + index;
                checkboxCell.appendChild(checkbox);

                const nameCell = document.createElement('td');
                nameCell.className = 'affected-item-name';
                nameCell.textContent = item;

                row.appendChild(checkboxCell);
                row.appendChild(nameCell);
                tbody.appendChild(row);
            });

            document.getElementById('affectedBackdrop').classList.add('active');
        }

        function closeAffectedModal() {
            document.getElementById('affectedBackdrop').classList.remove('active');
        }

        // Runs whichever action (back/logout) is currently pending.
        function executePendingAction() {
            const action = pendingAction;
            const element = pendingElement;
            pendingAction = null;
            pendingElement = null;

            if (action === 'back') {
                runBackAnimation(element);
            } else if (action === 'logout') {
                runLogoutAnimation(element);
            }
        }

        // Cancel button: close the modal and abort the pending action entirely.
        function cancelAffectedModal() {
            closeAffectedModal();
            pendingAction = null;
            pendingElement = null;
        }

        // Confirm button: log to activity_log only for items still checked,
        // then proceed with the pending action either way. Each checked item
        // is sent as a separate entry, so it is stored as its own row rather
        // than being combined together. Once confirmed (regardless of what
        // was checked), the accumulated visited-modules tracker is cleared
        // so it doesn't carry over into the next visit.
        function confirmAffectedModal() {
            const checkedItems = Array.from(document.querySelectorAll('.affected-item-checkbox:checked'))
                .map(cb => cb.getAttribute('data-item'));
            closeAffectedModal();

            if (checkedItems.length === 0) {
                clearVisitedModules();
                executePendingAction();
                return;
            }

            fetch('log_activity.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ list: checkedItems })
            })
            .then(() => {
                clearVisitedModules();
                executePendingAction();
            })
            .catch(() => {
                clearVisitedModules();
                executePendingAction();
            });
        }

        // ===== Universal "visitedModules" tracker =====
        // Any button with a data-affected-items attribute is a "tracked
        // module". Each tracked module pressed gets recorded here (its own
        // entry, keyed by URL) so multiple tracked buttons pressed in the
        // same visit all contribute their items - but only the ones that
        // were actually pressed, nothing assumed.
        const VISITED_MODULES_KEY = 'visitedModules';

        function getVisitedModules() {
            try {
                const raw = localStorage.getItem(VISITED_MODULES_KEY);
                const parsed = raw ? JSON.parse(raw) : [];
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function saveVisitedModules(modules) {
            localStorage.setItem(VISITED_MODULES_KEY, JSON.stringify(modules));
        }

        // Records/updates a tracked module's affected items. If the same
        // module is pressed again, its entry is replaced (not duplicated).
        function recordVisitedModule(moduleUrl, items) {
            const modules = getVisitedModules().filter(m => m.module !== moduleUrl);
            modules.push({ module: moduleUrl, items: items });
            saveVisitedModules(modules);
        }

        function clearVisitedModules() {
            localStorage.removeItem(VISITED_MODULES_KEY);
        }

        // Merges the items of every tracked module pressed so far into one
        // deduplicated list, to be shown in the confirmation modal.
        function getMergedAffectedItems() {
            const modules = getVisitedModules();
            const merged = [];
            modules.forEach(m => {
                (m.items || []).forEach(item => {
                    if (!merged.includes(item)) {
                        merged.push(item);
                    }
                });
            });
            return merged;
        }

        // Reads the comma-separated data-affected-items attribute off a
        // button and turns it into a clean array of item names.
        function getAffectedItems(element) {
            const raw = element.getAttribute('data-affected-items') || '';
            return raw.split(',').map(item => item.trim()).filter(item => item.length > 0);
        }

        // Whether the affected-items confirmation modal should be shown:
        // relevant whenever at least one tracked module has been pressed
        // since the last confirmation.
        function shouldConfirmAffectedItems() {
            return getVisitedModules().length > 0;
        }

        // Asks the server which of the merged items are NOT already
        // logged in activity_log (exact match). Only those are worth
        // asking the user to confirm - if an item's row already exists,
        // re-showing it in the modal would just re-log the same thing.
        function fetchUnloggedItems(items) {
            return fetch('check_logged_items.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ list: items })
            })
            .then(res => res.json())
            .then(data => (data && data.success) ? data.unloggedItems : items)
            .catch(() => items); // if the check fails, fall back to showing everything
        }

        // Shared logic for both Back and Logout: merge tracked items,
        // ask the server which ones are still unlogged, then either show
        // the modal with just those, or skip the modal entirely and run
        // the action directly if every tracked item is already logged.
        function proceedWithConfirmation(action, element) {
            if (!shouldConfirmAffectedItems()) {
                if (action === 'back') runBackAnimation(element);
                else if (action === 'logout') runLogoutAnimation(element);
                return;
            }

            const mergedItems = getMergedAffectedItems();
            fetchUnloggedItems(mergedItems).then(unloggedItems => {
                if (unloggedItems.length === 0) {
                    // Everything tracked is already logged - nothing new
                    // to confirm, so clear the tracker and proceed as if
                    // nothing had been pending.
                    clearVisitedModules();
                    if (action === 'back') runBackAnimation(element);
                    else if (action === 'logout') runLogoutAnimation(element);
                    return;
                }

                pendingAction = action;
                pendingElement = element;
                openAffectedModal(unloggedItems);
            });
        }

        // Animate the new pill-style Back button before navigating
        function handleBackClick(element) {
            proceedWithConfirmation('back', element);
        }

        // Animate the new pill-style Logout button before logging out
        function handleLogoutClick(element) {
            proceedWithConfirmation('logout', element);
        }

        // Function executed when a grid menu button is clicked.
        // Navigates directly - the affected-items modal is not triggered
        // here, only when leaving the page afterwards via Back/Logout.
        // If the button is a tracked module (has data-affected-items),
        // its items are recorded first so they can be shown later.
        function handleButtonClick(element) {
            const targetUrl = element.getAttribute('data-url');
            const items = getAffectedItems(element);
            if (items.length > 0) {
                recordVisitedModule(targetUrl, items);
            }

            // 1. Save this URL to localStorage as the active button identity
            localStorage.setItem('activeMenuUrl', targetUrl);

            // 2. Add the active class immediately (for an instant visual effect)
            document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');

            // 3. Navigate to the page
            window.location.href = targetUrl;
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