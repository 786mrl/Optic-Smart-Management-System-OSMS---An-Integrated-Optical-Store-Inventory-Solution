<?php
    // manage_roles.php
    session_start();

    $username = $_SESSION['username'] ?? 'Guest';
    $current_role = $_SESSION['role'] ?? 'N/A';

    include 'db_config.php';  // 1. DB Connection
    include 'config_helper.php';  // 2. Fetch Global Settings (STORE_NAME, BRAND_IMAGE_PATH)

    // Security check: Must be Admin
    if ($_SESSION['role'] !== 'admin') {
        header("Location: index.php");
        exit();
    }

    $message = '';
    $current_admin_id = $_SESSION['user_id'];

    // --- 1. Handle POST Actions (Delete, Promote, Deactivate/Activate) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
        $action = $_POST['action'];
        $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        
        // PREVENT ADMIN FROM MANAGING HIMSELF/HERSELF
        if ($target_user_id === $current_admin_id) {
            $message = "<p style='color: red;'>Error: You cannot manage your own account from this panel.</p>";
        } else if ($target_user_id > 0) {
            
            if ($action === 'delete') {
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $target_user_id);
                if ($stmt->execute()) {
                    $message = "<p style='color: green;'>User ID $target_user_id successfully deleted.</p>";
                } else {
                    $message = "<p style='color: red;'>Error deleting user.</p>";
                }
                $stmt->close();

            } else if ($action === 'promote' || $action === 'demote') {
                $new_role = ($action === 'promote') ? 'admin' : 'staff';
                $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
                $stmt->bind_param("si", $new_role, $target_user_id);
                if ($stmt->execute()) {
                    $message = "<p style='color: green;'>User ID $target_user_id successfully set to role '$new_role'.</p>";
                } else {
                    $message = "<p style='color: red;'>Error changing user role.</p>";
                }
                $stmt->close();

            } else if ($action === 'toggle_active') {
                // Re-using the 'is_approved' column for Activate/Deactivate function
                $current_status_sql = "SELECT is_approved FROM users WHERE user_id = ?";
                $stmt_status = $conn->prepare($current_status_sql);
                $stmt_status->bind_param("i", $target_user_id);
                $stmt_status->execute();
                $result_status = $stmt_status->get_result();
                $current_status = $result_status->fetch_assoc()['is_approved'];
                $new_status = $current_status ? 0 : 1; // Toggle the status (0=Deactivated, 1=Active/Approved)
                $action_name = $new_status ? 'Activated' : 'Deactivated';

                $stmt_update = $conn->prepare("UPDATE users SET is_approved = ? WHERE user_id = ?");
                $stmt_update->bind_param("ii", $new_status, $target_user_id);
                
                if ($stmt_update->execute()) {
                    $message = "<p style='color: green;'>User ID $target_user_id successfully $action_name.</p>";
                } else {
                    $message = "<p style='color: red;'>Error changing user status.</p>";
                }
                $stmt_status->close();
                $stmt_update->close();
            }
        }
    }
    // --- END Handle POST Actions ---

    // --- 2. Handle Create New User (from Admin panel) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_new_user'])) {
        $new_username = $_POST['new_username'];
        $new_password = $_POST['new_password'];
        $new_role = $_POST['new_role']; 
        
        // Simple validation
        if (empty($new_username) || empty($new_password)) {
            $message = "<p style='color: red;'>Username and password cannot be empty.</p>";
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $is_approved = 1; // Admin created users are automatically approved

            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, is_approved) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $new_username, $password_hash, $new_role, $is_approved);

            if ($stmt->execute()) {
                $message = "<p style='color: green;'>New user '$new_username' ($new_role) created successfully and activated.</p>";
            } else {
                if ($conn->errno == 1062) {
                    $message = "<p style='color: red;'>Error: Username '$new_username' is already taken.</p>";
                } else {
                    $message = "<p style='color: red;'>Error creating user: " . $stmt->error . "</p>";
                }
            }
            $stmt->close();
        }
    }
    // --- END Create New User ---

    // --- 3. Fetch All Users (Excluding the currently logged-in Admin) ---
    $all_users = [];
    $sql_users = "SELECT user_id, username, role, is_approved, created_at FROM users WHERE user_id != ?";
    $stmt = $conn->prepare($sql_users);
    $stmt->bind_param("i", $current_admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $all_users[] = $row;
        }
    }
    $stmt->close();
    close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage User Roles</title>
    <link rel="stylesheet" href="style.css">
    <script>let hasData;</script>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            font-size: 11px;
            text-transform: uppercase;
            color: #6a6e73;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        td {
            padding: 20px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.02);
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        /* Reduced outer gap (space around quick-add-bar) */
        .quick-add-bar {
            margin-bottom: 12px;
        }

        /* Reduced inner gap (space between input fields inside the row) */
        .input-row {
            gap: 8px;
        }

        /* --- Collapsible user cards (replaces table) --- */
        .user-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .user-card {
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 10px;
            overflow: hidden;
            background: rgba(255,255,255,0.015);
        }

        .user-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 16px;
            cursor: pointer;
        }

        .user-card-header:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .user-card-summary {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .user-id-badge {
            color: var(--accent);
            font-size: 12px;
        }

        .role-badge {
            font-size: 12px;
            opacity: 0.85;
        }

        .expand-icon {
            transition: transform 0.2s ease;
            opacity: 0.6;
            flex-shrink: 0;
        }

        .user-card.expanded .expand-icon {
            transform: rotate(180deg);
        }

        .user-card-body {
            display: none;
            padding: 0 16px 16px 16px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .user-card.expanded .user-card-body {
            display: block;
        }

        .user-card-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 0;
        }

        .user-card-row .label {
            font-size: 11px;
            text-transform: uppercase;
            color: #6a6e73;
        }

        /* --- Card list: icon + warna --- */
        .user-card-summary {
            gap: 10px;
        }

        .user-card-summary::before {
            content: "👤";
            font-size: 15px;
            line-height: 1;
        }

        .user-id-badge {
            color: #7dd3fc;
            font-weight: 600;
            background: rgba(125, 211, 252, 0.1);
            padding: 2px 8px;
            border-radius: 6px;
        }

        .user-card-summary strong {
            color: #f1f5f9;
        }

        .role-badge {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(167, 139, 250, 0.15);
            color: #a78bfa;
        }

        .status-active {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(74, 222, 128, 0.15);
            color: #4ade80;
        }

        .status-active::before {
            content: "● ";
        }

        .status-inactive {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(248, 113, 113, 0.15);
            color: #f87171;
        }

        .status-inactive::before {
            content: "● ";
        }

        .action-promote {
            color: #4ade80;
        }

        .action-demote {
            color: #fbbf24;
        }

        .action-activate {
            color: #4ade80;
        }

        .action-deactivate {
            color: #fbbf24;
        }

        .action-delete {
            color: #f87171;
        }
    </style>
    <!-- button logout, back animation for logo -->
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

        /* Center the header block (logout button + logo/name/address group)
           on PC to match how it already appears centered on mobile. Only
           the container's own horizontal position is changed here — the
           internal layout is left exactly as in the original code. */
        .header-container {
            margin-left: auto !important;
            margin-right: auto !important;
            width: fit-content !important;
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
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

            <div class="selection-container" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%; max-width: none;">
                <div class="glass-window" style="padding-left: 12px; padding-right: 12px;">
                    <!-- Determine empty or not -->
                    <?php if (count($all_users) > 0): ?>
                        <script>
                            hasData = true;
                        </script>
                    <?php else: ?>
                        <script>
                            hasData = false;
                        </script>
                    <?php endif; ?>

                    <h2 style="margin-bottom: 25px; font-size: 18px;">User Management Panel</h2>
                    
                    <div class="quick-add-bar" style="padding-left: 0; padding-right: 0;">
                        <form action="manage_roles.php" method="POST">
                            <div class="input-row" style="margin-left: 0; margin-right: 0; width: 100%; padding-left: 6px; padding-right: 6px; box-sizing: border-box;">
                                <input type="text" class="input-minimal" name="new_username" placeholder="Username" required>
                                <input type="text" class="input-minimal" name="new_password" placeholder="Password" required>
                                <select name="new_role" class="select-minimal" required>
                                    <option value="" disabled selected>Select Role</option>
                                    <option value="staff">Staff</option>
                                    <option value="admin">Admin</option>
                                    <option value="viewer">Viewer</option>
                                </select>            
                            </div>

                            <div class="button-row">
                                <button type="submit" name="create_new_user"  class="btn-glow">CREATE ACCOUNT</button>
                            </div>
                        </form>
                    </div>

                    <div class="table-responsive_approve_user">
                        <div class="user-list">
                            <?php foreach ($all_users as $user): ?>
                                <div class="user-card">
                                    <div class="user-card-header" onclick="toggleUserCard(this)">
                                        <div class="user-card-summary">
                                            <span class="user-id-badge">#<?php echo $user['user_id']; ?></span>
                                            <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                            <span class="role-badge"><?php echo ucfirst($user['role']); ?></span>
                                            <span class="status-<?php echo $user['is_approved'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $user['is_approved'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>
                                        <span class="expand-icon">▾</span>
                                    </div>

                                    <div class="user-card-body">
                                        <div class="user-card-row">
                                            <span class="label">Registered</span>
                                            <span><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span>
                                        </div>
                                        <div class="user-card-row" style="flex-wrap: wrap;">
                                            <form action="manage_roles.php" method="POST" style="width: 100%;">
                                                <div class="action-group" style="flex-wrap: wrap; width: 100%;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <?php if ($user['role'] === 'staff'): ?>
                                                        <button type="submit" name="action" value="promote" class="action-promote" 
                                                                onclick="event.stopPropagation(); return confirm('Promote <?php echo $user['username']; ?> to Admin? This grants full access.')">Promote</button>
                                                    <?php else: /* role is admin or viewer */ ?>
                                                        <button type="submit" name="action" value="demote" class="action-demote" 
                                                                onclick="event.stopPropagation(); return confirm('Demote <?php echo $user['username']; ?> to Staff?')">Demote</button>
                                                    <?php endif; ?>

                                                    <button type="submit" name="action" value="toggle_active" class="action-<?php echo $user['is_approved'] ? 'deactivate' : 'activate'; ?>"
                                                            onclick="event.stopPropagation();">
                                                        <?php echo $user['is_approved'] ? 'Deactivate' : 'Activate'; ?>
                                                    </button>

                                                    <button type="submit" name="action" value="delete" class="action-delete" 
                                                            onclick="event.stopPropagation(); return confirm('WARNING! Delete user <?php echo $user['username']; ?> permanently?')">Delete</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
            
                    <div class="empty-state" id="emptyMessage">
                        <div class="empty-icon">👥</div>
                        <p style="font-weight: 600;">No other accounts registered</p>
                        <p class="subtitle">You are currently the only user in the system.</p>
                    </div>                    
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
        if(!hasData) {
            document.querySelector('.table-responsive_approve_user').style.display = 'none';
            document.getElementById('emptyMessage').style.display = 'block';
        }

        function toggleUserCard(headerEl) {
            const card = headerEl.closest('.user-card');
            card.classList.toggle('expanded');
        }
    </script>
    <!-- button logout, back animation for logo -->
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

        // Animate the new pill-style Back button before navigating
        function handleBackClick(element) {
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
                    // back direction
                    window.location.href = 'admin.php';
                }
            }
            requestAnimationFrame(step);
        }

        // Animate the new pill-style Logout button before logging out
        function handleLogoutClick(element) {
            element.classList.add('pressed');
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 220);
        }

        // Function executed when a button is clicked
        function handleButtonClick(element) {
            // 1. Get the URL from the data-url attribute
            const targetUrl = element.getAttribute('data-url');
            
            // 2. Save this URL to localStorage as the active button identity
            localStorage.setItem('activeMenuUrl', targetUrl);
            
            // 3. Add the active class immediately (for an instant visual effect)
            document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');

            // 4. Navigate to the page
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