<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
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
    </head>
    <body>
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">
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

                        <div class="scanner-container">
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
                <button type="button" class="back-main" onclick="handleBack()">BACK TO PREVIOUS PAGE</button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>              

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

            window.addEventListener('load', restartScanner);
            
            window.addEventListener('beforeunload', () => {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop();
                }
            });
        </script>
    </body>
</html>