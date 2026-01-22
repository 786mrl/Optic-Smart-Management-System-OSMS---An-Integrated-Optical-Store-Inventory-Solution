<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode Scanner - Dark Neumorphism</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #1a1c1e;
            --shadow-light: #25282c;
            --shadow-dark: #101113;
            --accent: #00d4ff;
            --text: #e2e8f0;
            --muted: #6a6e73;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
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
            max-width: 400px;
            background: var(--bg-color);
            padding: 40px 30px;
            border-radius: 40px;
            box-shadow: 20px 20px 60px var(--shadow-dark), 
                        -20px -20px 60px var(--shadow-light);
            text-align: center;
        }

        .header-info { margin-bottom: 30px; }
        .header-info h2 { font-size: 22px; margin-bottom: 8px; font-weight: 700; }
        .header-info p { font-size: 13px; color: var(--muted); }

        /* --- SCANNER AREA --- */
        .scanner-container {
            position: relative;
            width: 280px;
            height: 280px;
            margin: 0 auto 40px;
            background: var(--bg-color);
            border-radius: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            /* Inset Shadow Effect */
            box-shadow: inset 10px 10px 20px var(--shadow-dark), 
                        inset -10px -10px 20px var(--shadow-light);
            overflow: hidden;
        }

        /* Camera/Video Feed Simulation */
        .camera-feed {
            width: 85%;
            height: 85%;
            background: #000;
            border-radius: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        /* Animated Laser Line */
        .laser-line {
            position: absolute;
            width: 90%;
            height: 2px;
            background: var(--accent);
            box-shadow: 0 0 15px var(--accent);
            z-index: 10;
            animation: scanMove 2s infinite ease-in-out;
        }

        @keyframes scanMove {
            0% { top: 10%; }
            50% { top: 90%; }
            100% { top: 10%; }
        }

        /* Corner Markers */
        .corner {
            position: absolute;
            width: 20px;
            height: 20px;
            border: 3px solid var(--accent);
            z-index: 5;
        }
        .top-left { top: 15px; left: 15px; border-right: 0; border-bottom: 0; border-top-left-radius: 10px; }
        .top-right { top: 15px; right: 15px; border-left: 0; border-bottom: 0; border-top-right-radius: 10px; }
        .bottom-left { bottom: 15px; left: 15px; border-right: 0; border-top: 0; border-bottom-left-radius: 10px; }
        .bottom-right { bottom: 15px; right: 15px; border-left: 0; border-top: 0; border-bottom-right-radius: 10px; }

        /* --- MANUAL INPUT SECTION --- */
        .manual-input {
            margin-bottom: 30px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .input-neu {
            width: 100%;
            background: var(--bg-color);
            border: none;
            padding: 15px;
            border-radius: 15px;
            color: white;
            outline: none;
            text-align: center;
            letter-spacing: 2px;
            box-shadow: inset 5px 5px 10px var(--shadow-dark), 
                        inset -5px -5px 10px var(--shadow-light);
            transition: 0.3s ease;
        }
        
        .input-neu:focus {
            box-shadow: inset 2px 2px 5px var(--shadow-dark), 
                        inset -2px -2px 5px var(--shadow-light);
            color: var(--accent);
        }

        .btn-action {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 15px;
            background: var(--bg-color);
            color: var(--accent);
            font-weight: 700;
            cursor: pointer;
            box-shadow: 8px 8px 16px var(--shadow-dark), 
                        -8px -8px 16px var(--shadow-light);
            transition: 0.2s;
        }

        .btn-action:active {
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
            transform: scale(0.98);
        }

        .btn-close {
            margin-top: 20px;
            background: transparent;
            border: none;
            color: var(--muted);
            font-size: 13px;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .btn-close:hover {
            color: var(--text);
        }
    </style>
</head>
<body>

    <div class="scanner-window">
        <div class="header-info">
            <h2>Scan Barcode</h2>
            <p>Point your camera at the product barcode</p>
        </div>

        <div class="scanner-container">
            <div class="camera-feed">
                <div class="corner top-left"></div>
                <div class="corner top-right"></div>
                <div class="corner bottom-left"></div>
                <div class="corner bottom-right"></div>
                
                <div class="laser-line"></div>
                
                <span style="font-size: 50px; opacity: 0.2;">📷</span>
            </div>
        </div>

        <div class="manual-input">
            <label style="font-size: 11px; color: var(--muted); text-transform: uppercase; font-weight: 700;">Or Enter Manually</label>
            <input type="text" class="input-neu" placeholder="880123456789">
        </div>

        <button class="btn-action">CONFIRM CODE</button>
        
        <button class="btn-close">Cancel & Go Back</button>
    </div>

</body>
</html>