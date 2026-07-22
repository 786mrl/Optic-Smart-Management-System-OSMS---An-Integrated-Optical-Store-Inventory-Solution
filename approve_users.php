<?php
    // approve_users.php
    session_start();

    $username = $_SESSION['username'] ?? 'Guest';
    $current_role = $_SESSION['role'] ?? 'N/A';

    include 'db_config.php'; // 1. DB Connection
    include 'config_helper.php';  // 2. Fetch Global Settings (STORE_NAME, BRAND_IMAGE_PATH)

    // Security check: must be Admin
    if ($_SESSION['role'] !== 'admin') {
        header("Location: index.php");
        exit();
    }

    $message = '';
    $status = '';

    // --- Logic to Handle Approval Action ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['approve_id'])) {
        $user_to_approve_id = $_POST['approve_id'];
        $stmt = $conn->prepare("UPDATE users SET is_approved = 1 WHERE user_id = ? AND role = 'staff'");
        $stmt->bind_param("i", $user_to_approve_id);
    
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "User ID #$user_to_approve_id approved successfully!";
            $status = "success";
        } else {
            $message = "Failed to approve user. It might be already approved or not found.";
            $status = "error";
        }
        $stmt->close();
    }
    // ----------------------------------------

    // --- Logic to Fetch Pending Users ---
    $pending_users = [];
    // Select all staff users who are not yet approved (is_approved = 0)
    $sql_pending = "SELECT user_id, username, created_at FROM users WHERE role = 'staff' AND is_approved = 0";
    $result = $conn->query($sql_pending);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pending_users[] = $row;
        }
    }

    close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Approve Staff Users</title>
        <link rel="stylesheet" href="style.css">
        <script>let hasData;</script>
        <style>
            /* Adjust table so it doesn't stick to the edges */
            table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0 15px; /* Row spacing */
                min-width: 600px; /* Prevents columns from being too cramped on small screens */
            }

            th {
                padding: 10px 20px;
                color: var(--text-muted);
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 1px;
                text-align: left;
            }

            td {
                padding: 20px;
                background: var(--bg-color);
                font-size: 14px;
                /* Use slightly smaller shadows for safety */
                box-shadow: 4px 4px 10px var(--shadow-dark), 
                            -4px -4px 10px var(--shadow-light);
                border: none;
            }

            /* Smooth out row corners */
            tr td:first-child { 
                border-radius: 20px 0 0 20px; 
                padding-left: 25px;
            }

            tr td:last-child { 
                border-radius: 0 20px 20px 0; 
                padding-right: 25px;
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
                    <?php if ($message !== ''): ?>
                        <div class="neu-alert <?php echo $status; ?>" id="alertBox">
                            <span class="alert-icon">
                                <?php echo ($status === 'success') ? '✅' : '⚠️'; ?>
                            </span>
                            <span><?php echo $message; ?></span>
                        </div>
                        
                        <script>
                            // Automatically remove alert after 4 seconds
                            setTimeout(() => {
                                const alert = document.getElementById('alertBox');
                                alert.style.transition = "opacity 0.5s ease";
                                alert.style.opacity = "0";
                                setTimeout(() => alert.remove(), 500);
                            }, 4000);
                        </script>
                    <?php endif; ?>

                    <div class="window-card" style="max-width: none">
                        <!-- Determine empty or not -->
                        <?php if (count($pending_users) > 0): ?>
                            <script>
                                hasData = true;
                            </script>
                        <?php else: ?>
                            <script>
                                hasData = false;
                            </script>
                        <?php endif; ?>
                
                        <div class="header-title">
                            <h2>Pending Staff Accounts</h2>
                            <p class="subtitle">List of accounts waiting for administrator approval</p>
                        </div>
                
                        <div class="table-responsive_approve_user">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User ID</th>
                                        <th>Username</th>
                                        <th>Registered Date</th>
                                        <th style="text-align: center;">Action</th>
                                    </tr>
                                </thead>
                
                                <tbody>
                                    <?php foreach ($pending_users as $user): ?>
                                        <tr>
                                            <td style="color: var(--accent-color); font-weight: 700;"><?php echo $user['user_id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo $user['created_at']; ?></td>
                                            <td style="text-align: center;">
                                                <form action="approve_users.php" method="POST">
                                                    <input type="hidden" name="approve_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit"class="btn-approve">Approve</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                
                        <div class="empty-state" id="emptyMessage">
                            <div class="empty-icon">📂</div>
                            <p style="font-weight: 600;">No pending staff accounts</p>
                            <p class="subtitle">All account requests have been processed.</p>
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