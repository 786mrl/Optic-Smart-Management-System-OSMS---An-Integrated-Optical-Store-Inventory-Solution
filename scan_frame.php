<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // Ajax logic to fetch frame data
    if (isset($_GET['ajax_ufc'])) {
        $ufc = $_GET['ajax_ufc'];

        // Always search BOTH tables (frames_main and frame_staging), even if a match
        // was already found in one of them, since data may exist in both at once.
        $tablesToSearch = ['frames_main', 'frame_staging'];
        $row = null;
        $foundInTables = [];

        foreach ($tablesToSearch as $table) {
            $stmt = $conn->prepare("SELECT * FROM `$table` WHERE ufc = ?");
            $stmt->bind_param("s", $ufc);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($currentRow = $result->fetch_assoc()) {
                $foundInTables[] = $table;
                // Use the first match found as the displayed data (frames_main takes priority)
                if ($row === null) {
                    $row = $currentRow;
                }
            }
        }

        if ($row) {
            // Include which database/table(s) the frame was found in
            echo json_encode(['status' => 'success', 'data' => $row, 'source_tables' => $foundInTables]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Data not found']);
        }
        exit;
    }
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Scan Frame - <?php echo $STORE_NAME; ?></title>
        <link rel="stylesheet" href="style.css">
        <script src="https://unpkg.com/html5-qrcode"></script>
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

                <div class="main-card" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%; 
                max-width: none;
                place-items: center;">
                    <div class="scanner-window">
                        <div class="header-info">
                            <h2>SMART SCANNER</h2>
                            <p>Point your camera at the product barcode</p>
                        </div>

                        <!-- Camera control button: camera stays off until the user starts it -->
                        <div class="camera-control-group" style="display: flex; gap: 10px; justify-content: center; margin-bottom: 15px;">
                            <button type="button" class="btn-action" id="startCameraBtn" onclick="startCamera()" style="background: var(--accent); color: #0a0a0a; font-weight: 700; box-shadow: 0 0 14px rgba(0, 212, 255, 0.6);">
                                START CAMERA
                            </button>
                        </div>

                        <div class="scanner-container" style="display: none;">
                            <div class="camera-feed" id="reader">
                                <div class="corner top-left"></div>
                                <div class="corner top-right"></div>
                                <div class="corner bottom-left"></div>
                                <div class="corner bottom-right"></div>
                                
                                <div id="laser-line" class="laser-line"></div>
                                
                                <span style="font-size: 50px; opacity: 0.2;">📷</span>
                            </div>
                        </div>

                        <div id="result-area" class="result-card">
                            <div style="background: rgba(0,212,255,0.1); padding: 10px; border-radius: 12px; margin-bottom: 15px;">
                                <h3 style="text-align: center; color: var(--accent); font-size: 12px; letter-spacing: 2px;">FRAME IDENTIFIED</h3>
                            </div>
                            <div id="detail-content"></div>
                            <button class="btn-action" onclick="restartScanner()">
                                SCAN NEXT FRAME
                            </button>
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
            const html5QrCode = new Html5Qrcode("reader");
            const config = { fps: 30, qrbox: { width: 120, height: 120 }, aspectRatio: 1.0 }; 

            function onScanSuccess(decodedText, decodedResult) {
                // 1. Provide immediate haptic feedback (vibration)
                if (navigator.vibrate) {
                    navigator.vibrate(100);
                }
                
                console.log("Scan Successful:", decodedText);

                // 2. Add a 0.6-second (600ms) delay so the user can see the final scan animation
                // Update laser style to indicate the scan is being processed
                const laser = document.getElementById('laser-line') || document.querySelector('.laser-line');
                if(laser) {
                    laser.style.background = "var(--success)"; // Change laser color to green upon success
                    laser.style.boxShadow = "0 0 15px var(--success)";
                }

                // Delay the next execution step
                setTimeout(() => {
                    html5QrCode.stop().then(() => {
                        // Hide the scanner container
                        const scannerContainer = document.querySelector('.scanner-container');
                        if (scannerContainer) scannerContainer.style.display = 'none';
                        
                        // Fetch data from the server
                        fetchData(decodedText);
                    }).catch(err => {
                        console.warn("Failed to stop camera:", err);
                        // Attempt to fetch data even if the camera stop fails
                        fetchData(decodedText);
                    });
                }, 600); // 0.6-second delay. You can adjust this value as needed.
            }

            function fetchData(ufc) {
                fetch(`scan_frame.php?ajax_ufc=${ufc}`)
                    .then(response => response.json())
                    .then(res => {
                        if (res.status === 'success') {
                            // Attach source table info to the data object so it can be displayed
                            res.data.source_tables = res.source_tables;
                            showDetail(res.data);
                        } else {
                            alert("Frame Not Registered!");
                            restartScanner();
                        }
                    })
                    .catch(err => {
                        console.error("Fetch error:", err);
                        restartScanner();
                    });
            }

            // Convert the raw table name(s) into a readable database label
            function sourceTableLabel(sourceTables) {
                if (!sourceTables || sourceTables.length === 0) return '-';

                const labels = sourceTables.map(table => {
                    if (table === 'frames_main') return 'MAIN DATABASE';
                    if (table === 'frame_staging') return 'STAGING DATABASE';
                    return table;
                });

                return labels.join(' & ');
            }

            function showDetail(data) {
                const container = document.getElementById('detail-content');
                if (!data) return;

                // Ordered fields with labels - corrected & complete
                const fields = [
                    { label: 'SOURCE DATABASE', value: sourceTableLabel(data.source_tables), highlight: true },
                    { label: 'UFC', value: data.ufc, highlight: false },
                    { label: 'BRAND', value: data.brand, highlight: false },
                    { label: 'MODEL CODE', value: data.frame_code, highlight: false },
                    { label: 'SIZE', value: data.frame_size, highlight: false },
                    { label: 'COLOR', value: data.color_code, highlight: false },
                    { label: 'MATERIAL', value: data.material, highlight: false },
                    { label: 'SHAPE', value: data.lens_shape, highlight: false },
                    { label: 'GENDER CATEGORY', value: data.gender_category ? data.gender_category.toUpperCase() : '-', highlight: false },
                    { label: 'STOCK', value: data.stock, highlight: true },
                    { label: 'SELLING PRICE', value: 'IDR ' + (parseInt(data.sell_price) || 0).toLocaleString('id-ID'), highlight: true },
                    { label: 'SECRET CODE', value: data.price_secret_code, highlight: false }
                ];

                let html = '';
                fields.forEach(field => {
                    // If data is empty, display a dash (-)
                    const valueDisplay = field.value && field.value !== "" ? field.value : '-';
                    const hClass = field.highlight ? 'detail-row highlight' : 'detail-row';
                    
                    html += `<div class="${hClass}">
                                <span>${field.label}</span>
                                <span>${valueDisplay}</span>
                            </div>`;
                });
                
                container.innerHTML = html;
                document.getElementById('result-area').style.display = 'block';
                
                const scannerContainer = document.querySelector('.scanner-container');
                if (scannerContainer) {
                    scannerContainer.style.display = 'none';
                }

                // Hide scan instructions to keep the result view clean
                const headerP = document.querySelector('.header-info p');
                if (headerP) headerP.style.display = 'none';
            }

            async function restartScanner() {
                const laser = document.getElementById('laser-line');
                if(laser) laser.style.display = 'block';

                document.getElementById('result-area').style.display = 'none';
                const scannerContainer = document.querySelector('.scanner-container');
                if (scannerContainer) {
                    scannerContainer.style.display = 'flex'; // Gunakan flex sesuai style awalmu
                }

                // 3. Tampilkan kembali elemen reader
                document.getElementById('reader').style.display = 'block';

                // 4. Tampilkan kembali instruksi header
                const headerP = document.querySelector('.header-info p');
                if (headerP) headerP.style.display = 'block';

                try {
                    const devices = await Html5Qrcode.getCameras();
                    let selectedId = devices[devices.length - 1].id; 

                    for (const device of devices) {
                        if (device.label.toLowerCase().includes('back') || 
                            device.label.toLowerCase().includes('rear')) {
                            selectedId = device.id;
                            break;
                        }
                    }

                    await html5QrCode.start(
                        selectedId, 
                        {
                            ...config,
                            videoConstraints: {
                                facingMode: "environment",
                                focusMode: "continuous",
                                advanced: [
                                    { zoom: 2.0 }, 
                                    { focusDistance: 0.1 }
                                ]
                            }
                        },
                        onScanSuccess
                    );
                } catch (err) {
                    html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess);
                }
            }

            // Start the camera when the user clicks the "Start Camera" button
            async function startCamera() {
                const scannerContainer = document.querySelector('.scanner-container');
                if (scannerContainer) scannerContainer.style.display = 'flex';

                // Hide the Start Camera button once the camera is active
                document.getElementById('startCameraBtn').style.display = 'none';

                await restartScanner();
            }

            function handleBack() {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop().then(() => {
                        window.location.href = 'frame_management.php';
                    }).catch(err => {
                        window.location.href = 'frame_management.php';
                    });
                } else {
                    window.location.href = 'frame_management.php';
                }
            }

            // Camera no longer auto-starts on page load; user must press "START CAMERA" first.

            window.addEventListener('beforeunload', () => {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop();
                }
            });
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
                        // Make sure the camera is closed before leaving this page
                        if (html5QrCode && html5QrCode.isScanning) {
                            html5QrCode.stop().finally(() => {
                                window.location.href = 'frame_management.php';
                            });
                        } else {
                            window.location.href = 'frame_management.php';
                        }
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