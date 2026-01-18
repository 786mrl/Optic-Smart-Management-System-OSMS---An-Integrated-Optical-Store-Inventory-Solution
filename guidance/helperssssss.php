<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Configuration - Glass Neumorphism</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #1a1c1e;
            --card-bg: #222529;
            --accent: #00d4ff;
            --text-main: #ffffff;
            --text-muted: #6a6e73;
            --shadow-dark: rgba(0, 0, 0, 0.4);
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex;
            justify-content: center;
            padding: 40px 20px;
        }

        .config-window {
            width: 100%;
            max-width: 900px;
            background: linear-gradient(145deg, #23272b, #1e2124);
            border-radius: 30px;
            padding: 40px;
            box-shadow: 25px 25px 50px #131517, -10px -10px 30px #2d3135;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .header-title {
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        /* --- CONFIG SECTION --- */
        .config-section {
            margin-bottom: 35px;
            background: rgba(0, 0, 0, 0.15);
            padding: 25px;
            border-radius: 20px;
            box-shadow: inset 4px 4px 10px rgba(0,0,0,0.3);
        }

        .section-header {
            font-size: 14px;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .input-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .input-group.full-width {
            grid-column: span 2;
        }

        label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            margin-left: 5px;
        }

        .input-field {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.05);
            padding: 12px 15px;
            border-radius: 12px;
            color: white;
            outline: none;
            box-shadow: 4px 4px 8px var(--shadow-dark);
            transition: 0.3s;
        }

        .input-field:focus {
            border-color: var(--accent);
            box-shadow: 0 0 10px rgba(0, 212, 255, 0.2);
        }

        .input-field:disabled {
            background: rgba(0, 0, 0, 0.2);
            color: var(--text-muted);
            cursor: not-allowed;
            box-shadow: none;
        }

        .description {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
            font-style: italic;
        }

        /* --- ACTION BAR --- */
        .action-bar {
            display: flex;
            justify-content: center;
            margin-top: 40px;
        }

        .btn-save {
            background: var(--accent);
            border: none;
            padding: 15px 60px;
            border-radius: 15px;
            font-weight: 700;
            color: #000;
            cursor: pointer;
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.3);
            transition: 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-save:hover {
            box-shadow: 0 0 35px rgba(0, 212, 255, 0.5);
            transform: translateY(-3px);
        }

        @media (max-width: 768px) {
            .input-grid { grid-template-columns: 1fr; }
            .input-group.full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>

    <div class="config-window">
        <div class="header-title">
            <h2>System Configuration</h2>
            <p style="color: var(--text-muted); font-size: 13px;">Manage your global system variables and defaults.</p>
        </div>

        <form>
            <div class="config-section">
                <div class="section-header">🏢 Company & Store Details</div>
                
                <div class="input-grid">
                    <div class="input-group">
                        <label>Store Name</label>
                        <input type="text" class="input-field" value="Optik Modern Jaya">
                    </div>

                    <div class="input-group">
                        <label>Store Phone</label>
                        <input type="text" class="input-field" value="+62 21 555 1234">
                    </div>

                    <div class="input-group full-width">
                        <label>Store Address</label>
                        <input type="text" class="input-field" value="Jl. Sudirman No. 45, Jakarta Pusat">
                    </div>

                    <div class="input-group">
                        <label>Brand Image Path</label>
                        <input type="text" class="input-field" value="/assets/img/brand/logo.png">
                    </div>

                    <div class="input-group">
                        <label>Copyright Footer</label>
                        <input type="text" class="input-field" value="© 2026 Modern Optik. All Rights Reserved.">
                    </div>
                </div>
                
                <p class="description">This information will appear on letterheads and the application footer.</p>
            </div>

            <div class="config-section">
                <div class="section-header">🌍 Localization</div>
                <div class="input-grid">
                    <div class="input-group">
                        <label>Currency Code</label>
                        <input type="text" class="input-field" value="IDR">
                    </div>
                    
                    <div class="input-group">
                        <label>Timezone</label>
                        <input type="text" class="input-field" value="Asia/Jakarta">
                    </div>
                </div>
                <p class="description">Ensure the timezone matches the store location for accurate daily reporting.</p>
            </div>

            <div class="config-section">
                <div class="section-header">💰 Financial & Tax</div>
                <div class="input-grid">
                    <div class="input-group">
                        <label>Tax Rate (%)</label>
                        <input type="number" class="input-field" value="11">
                    </div>
                </div>
                <p class="description">Tax (VAT/PPN) will be automatically applied to every new transaction.</p>
            </div>

            <div class="config-section">
                <div class="section-header">📦 Inventory Defaults</div>
                <div class="input-grid">
                    <div class="input-group">
                        <label>UOM - Frame</label>
                        <input type="text" class="input-field" value="Pcs">
                    </div>
                    <div class="input-group">
                        <label>UOM - Lens</label>
                        <input type="text" class="input-field" value="Pair">
                    </div>
                    <div class="input-group">
                        <label>UOM - Other</label>
                        <input type="text" class="input-field" value="Unit">
                    </div>
                    <div class="input-group">
                        <label>Low Stock Threshold</label>
                        <input type="number" class="input-field" value="5">
                    </div>
                    <div class="input-group full-width">
                        <label>Starting Invoice Number</label>
                        <input type="text" class="input-field" value="10001">
                    </div>
                </div>
                <p class="description">Configure unit of measurement (UOM) and low stock alert thresholds globally.</p>
            </div>

            <div class="config-section">
                <div class="section-header">🧾 Receipt & Invoice</div>
                <div class="input-grid">
                    <div class="input-group">
                        <label>Invoice Prefix</label>
                        <input type="text" class="input-field" value="INV-MODERN-Subtle">
                    </div>
                    <div class="input-group">
                        <label>Receipt Footer Message</label>
                        <input type="text" class="input-field" value="Thank you for your visit!">
                    </div>
                </div>
                <p class="description">Defines invoice numbering formats and custom footer messages on shopping receipts.</p>
            </div>

            <div class="config-section">
                <div class="section-header">🛡️ Database Backup (View Only)</div>
                <div class="input-grid">
                    <div class="input-group">
                        <label>Last Backup Date</label>
                        <input type="text" class="input-field" value="18 Jan 2026, 03:00 AM" disabled>
                    </div>
                    <div class="input-group">
                        <label>Backup Location</label>
                        <input type="text" class="input-field" value="/var/www/backups/db_main/" disabled>
                    </div>
                </div>
                <p class="description">These settings can only be modified via the central server configuration.</p>
            </div>

            <div class="action-bar">
                <button type="submit" class="btn-save">Save All Configuration</button>
            </div>
        </form>
    </div>

</body>
</html>