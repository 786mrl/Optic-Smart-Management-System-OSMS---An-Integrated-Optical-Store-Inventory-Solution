<?php
    session_start();
    include 'db_config.php';
    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // Ajax logic to fetch frame data
    if (isset($_GET['ajax_ufc'])) {
        $ufc = $_GET['ajax_ufc'];
        $stmt = $conn->prepare("SELECT * FROM frames_main WHERE ufc = ?");
        $stmt->bind_param("s", $ufc);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo json_encode(['status' => 'success', 'data' => $row]);
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
        <style>
            :root {
                --bg-color: #1a1c1e;
                --shadow-light: #25282c;
                --shadow-dark: #101113;
                --accent: #00d4ff;
                --success: #00ff88;
                --text: #e2e8f0;
                --muted: #6a6e73;
            }

            * { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
                background-color: var(--bg-color);
                color: var(--text);
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                padding: 20px;
            }

            .scanner-window {
                width: 100%;
                max-width: 420px;
                background: var(--bg-color);
                padding: 35px 25px;
                border-radius: 40px;
                box-shadow: 20px 20px 60px var(--shadow-dark), -20px -20px 60px var(--shadow-light);
            }

            .header-info { text-align: center; margin-bottom: 25px; }
            .header-info h2 { font-size: 16px; letter-spacing: 2px; color: var(--accent); font-weight: 800; }

            /* Scanner Container */
            .scan-container {
                position: relative;
                border-radius: 30px;
                overflow: hidden;
                margin-bottom: 25px;
                background: var(--bg-color);
                box-shadow: inset 10px 10px 20px var(--shadow-dark), inset -10px -10px 20px var(--shadow-light);
                padding: 10px;
            }

            #reader { width: 100%; border: none !important; border-radius: 20px; overflow: hidden; }
            #reader video { object-fit: cover; }

            /* Laser Line */
            #laser-line {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 2px;
                background: var(--accent);
                box-shadow: 0 0 15px var(--accent);
                z-index: 10;
                animation: scanLine 2s infinite linear;
            }

            @keyframes scanLine {
                0% { top: 5%; }
                100% { top: 95%; }
            }

            /* Result Card */
            .result-card {
                display: none;
                padding: 20px;
                background: var(--bg-color);
                border-radius: 25px;
                box-shadow: inset 5px 5px 15px var(--shadow-dark), inset -5px -5px 15px var(--shadow-light);
                margin-bottom: 20px;
                animation: fadeIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .detail-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
                border-bottom: 1px solid rgba(255,255,255,0.03);
            }

            .detail-row span:first-child { color: var(--muted); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
            .detail-row span:last-child { color: var(--text); font-weight: 500; font-size: 13px; }

            /* Specific Highlight for Stock & Price */
            .detail-row.highlight span:last-child { color: var(--success); font-weight: 700; font-size: 15px; }

            .btn-action {
                width: 100%;
                padding: 18px;
                border: none;
                border-radius: 20px;
                background: var(--bg-color);
                color: var(--accent);
                font-weight: 700;
                font-size: 13px;
                letter-spacing: 1px;
                cursor: pointer;
                box-shadow: 6px 6px 12px var(--shadow-dark), -6px -6px 12px var(--shadow-light);
                transition: all 0.2s ease;
                margin-top: 15px;
            }

            .btn-action:active {
                box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
                transform: scale(0.98);
            }

            .btn-secondary { color: var(--muted); font-size: 11px; box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light); }

            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
        </style>
    </head>
    <body>
        <div class="scanner-window">
            <div class="header-info">
                <h2 style="font-weight: 800; text-shadow: 0 0 10px rgba(0, 212, 255, 0.2);">
                    SMART SCANNER
                </h2>
                <p style="font-size: 11px; opacity: 0.7;">OPTIMIZED FOR MINI BARCODE</p>
            </div>

            <div class="scan-container neumorphic-inset" style="position: relative; border-radius: 30px; overflow: hidden; margin-bottom: 25px;">
                <div id="reader"></div>
                <div id="laser-line" style="position: absolute; top: 0; width: 100%; height: 2px; background: var(--accent); box-shadow: 0 0 10px var(--accent); animation: scanLine 2s infinite; display: block;"></div>
            </div>

            <div id="result-area" class="result-card">
                <div style="background: rgba(0,212,255,0.1); padding: 10px; border-radius: 12px; margin-bottom: 15px;">
                    <h3 style="text-align: center; color: var(--accent); font-size: 12px; letter-spacing: 2px;">FRAME IDENTIFIED</h3>
                </div>
                <div id="detail-content"></div>
                <button class="btn-action" style="background: linear-gradient(145deg, #1c1e20, #17191b); color: var(--success);" onclick="restartScanner()">
                    SCAN NEXT FRAME
                </button>
            </div>

            <div class="footer-actions">
                <button class="btn-action" style="font-size: 12px;" onclick="window.location.href='frame_management.php'">
                    EXIT TO DASHBOARD
                </button>
            </div>
        </div>

        <script>
            const html5QrCode = new Html5Qrcode("reader");
            const config = { fps: 30, qrbox: { width: 120, height: 120 }, aspectRatio: 1.0 }; 

            function onScanSuccess(decodedText, decodedResult) {
                if (navigator.vibrate) {
                    navigator.vibrate(100);
                }
                console.log("Scan Successful:", decodedText);
                
                // Hide laser when scan is successful
                const laser = document.getElementById('laser-line');
                if(laser) laser.style.display = 'none';

                html5QrCode.stop().then(() => {
                    document.getElementById('reader').style.display = 'none';
                    fetchData(decodedText);
                }).catch(err => {
                    console.warn("Failed to stop camera:", err);
                    fetchData(decodedText);
                });
            }

            function fetchData(ufc) {
                fetch(`scan_frame.php?ajax_ufc=${ufc}`)
                    .then(response => response.json())
                    .then(res => {
                        if (res.status === 'success') {
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

            function showDetail(data) {
                const container = document.getElementById('detail-content');
                if (!data) return;

                // Ordered fields with labels - corrected & complete
                const fields = [
                    { label: 'UFC', value: data.ufc, highlight: false },
                    { label: 'BRAND', value: data.brand, highlight: false },
                    { label: 'MODEL CODE', value: data.frame_code, highlight: false },
                    { label: 'SIZE', value: data.frame_size, highlight: false },
                    { label: 'COLOR', value: data.color_code, highlight: false },
                    { label: 'MATERIAL', value: data.material, highlight: false },
                    { label: 'SHAPE', value: data.lens_shape, highlight: false }, // Added back
                    { label: 'STOCK', value: data.stock, highlight: true },
                    { label: 'SELLING PRICE', value: 'IDR ' + parseInt(data.sell_price).toLocaleString('id-ID'), highlight: true },
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
                
                // Hide scan instructions to keep the result view clean
                const headerP = document.querySelector('.header-info p');
                if (headerP) headerP.style.display = 'none';
            }

            async function restartScanner() {
                const laser = document.getElementById('laser-line');
                if(laser) laser.style.display = 'block';

                document.getElementById('result-area').style.display = 'none';
                document.getElementById('reader').style.display = 'block';

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

            window.addEventListener('load', restartScanner);
        </script>
    </body>
</html>