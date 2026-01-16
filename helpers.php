<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dark Neumorphic Selection</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600&display=swap" rel="stylesheet">
    
    <style>
        /* --- DARK MODE COLOR VARIABLES --- */
        :root {
            --bg-color: #1e2124;        
            --shadow-light: #2a2e32;    
            --shadow-dark: #121416;     
            --accent-color: #00d4ff;    
            --text-color: #a0aec0;
            --text-main: #e2e8f0;
            --text-muted: #718096;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }

        .selection-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 25px;
            width: 100%;
            max-width: 500px;
            padding: 20px;
        }

        /* --- ADDITIONAL HEADER STYLING --- */
    
        .header-container {
            width: 100%;
            max-width: 600px; 
            margin: 0 auto 30px auto;
            position: relative;
            padding: 20px;
        }

        .brand-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 10px;
        }

        /* Logo with Raised Neumorphic Effect */
        .logo-box {
            width: 100px;
            height: 100px;
            background-color: var(--bg-color);
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            box-shadow: 10px 10px 20px var(--shadow-dark), 
                        -10px -10px 20px var(--shadow-light);
            margin-bottom: 15px;
        }

        .company-name {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: 1px;
        }

        .company-address {
            font-size: 13px;
            color: var(--text-muted);
            max-width: 300px;
            line-height: 1.5;
        }

        /* Neumorphic Logout Button */
        .logout-btn {
            position: absolute;
            top: 0;
            right: 0;
            padding: 10px 20px;
            border: none;
            border-radius: 12px;
            background: var(--bg-color);
            color: #ff4b2b; 
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            box-shadow: 5px 5px 10px var(--shadow-dark), 
                        -5px -5px 10px var(--shadow-light);
            transition: all 0.2s ease;
        }

        .logout-btn:hover {
            box-shadow: 2px 2px 5px var(--shadow-dark), 
                        -2px -2px 5px var(--shadow-light);
            transform: scale(0.95);
        }

        .logout-btn:active {
            box-shadow: inset 3px 3px 6px var(--shadow-dark), 
                        inset -3px -3px 6px var(--shadow-light);
        }

        /* --- DARK NEUMORPHIC BUTTON STYLE --- */
        .neu-button {
            flex: 0 0 calc(50% - 13px);
            background-color: var(--bg-color);
            padding: 30px 20px;
            border-radius: 24px;
            border: none;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: var(--text-color);
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);

            /* Convex Effect */
            box-shadow: 8px 8px 16px var(--shadow-dark), 
                        -8px -8px 16px var(--shadow-light);
        }

        .neu-button:nth-child(3):last-child {
            flex: 0 0 70%;
        }

        .neu-button:hover {
            color: #ffffff;
            transform: translateY(-2px);
        }

        /* --- ACTIVE STATE (INSET/PRESSED) --- */
        .neu-button.active {
            box-shadow: inset 6px 6px 12px var(--shadow-dark), 
                        inset -6px -6px 12px var(--shadow-light);
            color: var(--accent-color);
            transform: scale(0.97);
        }

        /* LED Indicator */
        .led {
            width: 6px;
            height: 6px;
            background-color: #444; 
            border-radius: 50%;
            margin-top: 5px;
            transition: all 0.3s ease;
        }

        .active .led {
            background-color: var(--accent-color);
            box-shadow: 0 0 10px var(--accent-color); 
        }

        .icon { font-size: 28px; }

        /* --- FOOTER STYLING --- */
        .footer-container {
            width: 100%;
            text-align: center;
            padding: 40px 20px;
            margin-top: auto;
        }

        .footer-text {
            font-size: 12px;
            color: var(--text-muted);
            letter-spacing: 1px;
            display: inline-block;
            padding: 12px 25px;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
            text-transform: uppercase;
        }

        @media (max-width: 600px) {
            .neu-button, .neu-button:nth-child(3):last-child {
                flex: 0 0 100%;
            }

            .header-container {
                margin-bottom: 20px;
            }
            .logout-btn {
                position: relative;
                margin-bottom: 20px;
                display: block;
                width: 100px;
                margin-left: auto;
            }
            .company-name {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>

<div class="header-container">
    <button class="logout-btn" onclick="alert('Logging out...')">
        <span>Logout</span>
    </button>

    <div class="brand-section">
        <div class="logo-box">
            <span style="font-size: 40px;">🚀</span> </div>
        
        <h1 class="company-name">MODERN TECHNOLOGY LTD.</h1>
        <p class="company-address">123 Sudirman St., South Jakarta, Indonesia</p>
    </div>
</div>

    <div class="selection-container">
        <button class="neu-button" onclick="selectBtn(this)">
            <span class="icon">🎮</span>
            Game Mode
            <div class="led"></div>
        </button>

        <button class="neu-button" onclick="selectBtn(this)">
            <span class="icon">🎬</span>
            Movie Mode
            <div class="led"></div>
        </button>

        <button class="neu-button active" onclick="selectBtn(this)">
            <span class="icon">🎧</span>
            Music Mode
            <div class="led"></div>
        </button>

        <button class="neu-button" onclick="selectBtn(this)">
            <span class="icon">🎬</span>
            Movie Mode
            <div class="led"></div>
        </button>

        <button class="neu-button active" onclick="selectBtn(this)">
            <span class="icon">🎧</span>
            Music Mode
            <div class="led"></div>
        </button>
        
        <footer class="footer-container">
            <div class="footer-text">
                &copy; 2026 Modern Technology Ltd. All rights reserved.
            </div>
        </footer>
    </div>


    <script>
        function selectBtn(element) {
            document.querySelectorAll('.neu-button').forEach(btn => {
                btn.classList.remove('active');
            });
            element.classList.add('active');
        }
    </script>

</body>
</html>