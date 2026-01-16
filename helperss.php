<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dark Neumorphic Form & Selection</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* --- VARIABEL WARNA DARK MODE --- */
        :root {
            --bg-color: #1e2124;        
            --shadow-light: #2a2e32;    
            --shadow-dark: #121416;     
            --accent-color: #00d4ff;    
            --text-main: #e2e8f0;
            --text-muted: #718096;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 40px 20px;
        }

        .main-card {
            background-color: var(--bg-color);
            width: 100%;
            max-width: 600px;
            padding: 40px;
            border-radius: 40px;
            box-shadow: 20px 20px 60px var(--shadow-dark), 
                        -20px -20px 60px var(--shadow-light);
        }

        h2 {
            text-align: center;
            margin-bottom: 35px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        /* --- STYLE TAMBAHAN UNTUK HEADER --- */
    
    .header-container {
        width: 100%;
        max-width: 600px; /* Samakan dengan max-width main-card agar sejajar */
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

    /* Logo dengan efek Neumorphic Timbul */
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

    /* Tombol Logout Neumorphic */
    .logout-btn {
        position: absolute;
        top: 0;
        right: 0;
        padding: 10px 20px;
        border: none;
        border-radius: 12px;
        background: var(--bg-color);
        color: #ff4b2b; /* Warna merah untuk indikasi keluar */
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

        /* --- GRID LOGIC (2 ATAS, 1 TENGAH) --- */
        .form-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 25px;
        }

        .input-group {
            flex: 0 0 calc(50% - 13px);
            display: flex;
            flex-direction: column;
        }

        /* Elemen ke-3 otomatis ke tengah */
        .input-group:nth-child(3):last-child, 
        .selection-label {
            flex: 0 0 100%;
        }

        /* --- STYLE INPUT DARK NEUMORPHIC --- */
        label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 12px;
            margin-left: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        input, select {
            border: none;
            outline: none;
            background: var(--bg-color);
            padding: 16px 20px;
            border-radius: 15px;
            color: var(--text-main);
            font-size: 15px;
            box-shadow: inset 6px 6px 12px var(--shadow-dark), 
                        inset -6px -6px 12px var(--shadow-light);
            transition: all 0.3s ease;
        }

        input:focus {
            box-shadow: inset 2px 2px 5px var(--shadow-dark), 
                        inset -5px -5px 10px var(--shadow-light);
            color: var(--accent-color);
        }

        /* --- SELECTION BUTTONS (WINDOWS STYLE) --- */
        .selection-wrapper {
            display: flex;
            gap: 15px;
            width: 100%;
            margin-top: 10px;
        }

        .neu-btn {
            flex: 1;
            background: var(--bg-color);
            border: none;
            padding: 20px;
            border-radius: 18px;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            box-shadow: 6px 6px 12px var(--shadow-dark), 
                        -6px -6px 12px var(--shadow-light);
            transition: all 0.3s ease;
        }

        .neu-btn.active {
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
            color: var(--accent-color);
        }

        .led {
            width: 5px;
            height: 5px;
            background: #444;
            border-radius: 50%;
            transition: 0.3s;
        }

        .active .led {
            background: var(--accent-color);
            box-shadow: 0 0 8px var(--accent-color);
        }

        /* --- SUBMIT BUTTON --- */
        .submit-main {
            width: 100%;
            margin-top: 40px;
            padding: 18px;
            border: none;
            border-radius: 15px;
            background: var(--bg-color);
            color: var(--accent-color);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 8px 8px 16px var(--shadow-dark), 
                        -8px -8px 16px var(--shadow-light);
            transition: 0.2s;
        }

        .submit-main:hover {
            transform: scale(0.99);
            color: #fff;
        }

        .submit-main:active {
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
        }

        /* RESPONSIVE */
        @media (max-width: 750px) {
            .input-group, .input-group:nth-child(3):last-child { 
                flex: 0 0 100%;
            }

            .selection-wrapper { 
                flex-direction: column;
            }

            .main-card, .header-container {
                margin-bottom: 20px;
                padding: 10px
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
        <button class="logout-btn" onclick="alert('Keluar...')">
            <span>Logout</span>
        </button>

        <div class="brand-section">
            <div class="logo-box">
                <span style="font-size: 40px;">🚀</span> </div>
            
            <h1 class="company-name">PT. TEKNOLOGI MODERN</h1>
            <p class="company-address">Jl. Sudirman No. 123, Jakarta Selatan, Indonesia</p>
        </div>
    </div>

    <div class="main-card">
        <h2>Control Panel</h2>
        
        <form onsubmit="event.preventDefault()">
            <div class="form-grid">
                <div class="input-group">
                    <label>Username</label>
                    <input type="text" placeholder="Admin_01">
                </div>

                <div class="input-group">
                    <label>Suhu Sistem</label>
                    <input type="text" placeholder="45°C">
                </div>

                <div class="input-group">
                    <label>Kategori Server</label>
                    <select>
                        <option>Mainframe</option>
                        <option>Database</option>
                        <option>Cloud Storage</option>
                    </select>
                </div>

                <div class="input-group">
                    <label>Pilih Mode Operasi</label>
                    <div class="selection-wrapper">
                        <button type="button" class="neu-btn active" onclick="toggleNeu(this)">
                            <span>Auto</span>
                            <div class="led"></div>
                        </button>
                        <button type="button" class="neu-btn" onclick="toggleNeu(this)">
                            <span>Manual</span>
                            <div class="led"></div>
                        </button>
                        <button type="button" class="neu-btn" onclick="toggleNeu(this)">
                            <span>Eco</span>
                            <div class="led"></div>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-main">Update Configuration</button>
        </form>
    </div>

    <script>
        function toggleNeu(el) {
            const parent = el.parentElement;
            parent.querySelectorAll('.neu-btn').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
        }
    </script>

</body>
</html>