<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Data Display Neumorphic Dark</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* --- VARIABEL WARNA & DASAR --- */
        :root {
            --bg-color: #1e2124;        
            --shadow-light: #2a2e32;    
            --shadow-dark: #121416;     
            --accent-color: #00d4ff;    
            --text-main: #e2e8f0;
            --text-muted: #718096;
            --danger: #ff4b2b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            overflow-x: hidden;
            padding: 20px 10px;
        }

        /* --- 1. HEADER SECTION --- */
        .header-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto 30px auto;
            position: relative;
            text-align: center;
        }

        .logout-btn {
            position: absolute;
            top: 0; right: 0;
            padding: 10px 18px;
            border: none;
            border-radius: 12px;
            background: var(--bg-color);
            color: var(--danger);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 5px 5px 10px var(--shadow-dark), -5px -5px 10px var(--shadow-light);
            transition: 0.3s;
        }

        .logo-box {
            width: 70px; height: 70px;
            background: var(--bg-color);
            margin: 0 auto 15px auto;
            border-radius: 50%;
            display: flex; justify-content: center; align-items: center;
            font-size: 30px;
            box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
        }

        .company-name { font-size: 22px; font-weight: 700; margin-bottom: 5px; }
        .company-address { font-size: 12px; color: var(--text-muted); }

        /* --- 2. TABLE CONTAINER --- */
        .table-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            background: var(--bg-color);
            padding: 30px;
            border-radius: 35px;
            box-shadow: 15px 15px 35px var(--shadow-dark), -15px -15px 35px var(--shadow-light);
        }

        /* Tombol Atas (Action Bar) */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            gap: 15px;
        }

        .action-group { display: flex; gap: 12px; }

        .neu-btn-sm {
            border: none;
            background: var(--bg-color);
            padding: 12px 20px;
            border-radius: 12px;
            color: var(--text-main);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 5px 5px 10px var(--shadow-dark), -5px -5px 10px var(--shadow-light);
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .neu-btn-sm.active, .neu-btn-sm:active {
            box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
            color: var(--accent-color);
        }

        /* Area Tabel Cekung */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            border-radius: 20px;
            box-shadow: inset 8px 8px 16px var(--shadow-dark), inset -8px -8px 16px var(--shadow-light);
            padding: 15px;
            background: var(--bg-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 550px;
        }

        th {
            text-align: left;
            padding: 18px 15px;
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--shadow-light);
        }

        td {
            padding: 18px 15px;
            font-size: 14px;
            color: var(--text-main);
            border-bottom: 1px solid rgba(255,255,255,0.02);
        }

        /* Status Badge */
        .status {
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
        }
        .online { color: var(--accent-color); }
        .offline { color: var(--danger); }

        /* Tombol Navigasi Bawah (Pagination) */
        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
        }

        .page-info {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* --- RESPONSIVE MOBILE --- */
        @media (max-width: 600px) {
            body { padding: 10px 5px; }
            .header-container, .table-container {
                padding: 20px 15px;
                border-radius: 20px;
            }
            .logout-btn { position: relative; width: 100%; margin-bottom: 20px; }
            .action-bar { flex-direction: column; align-items: stretch; }
            .action-group { justify-content: space-between; }
            .neu-btn-sm { flex: 1; justify-content: center; font-size: 14px; }
            th, td { padding: 12px 10px; font-size: 13px; }
        }
    </style>
</head>
<body>

    <header class="header-container">
        <button class="logout-btn">KELUAR</button>
        <div class="logo-box">📊</div>
        <h1 class="company-name">DATABASE MONITOR</h1>
        <p class="company-address">Pusat Data Nasional, Jakarta Pusat</p>
    </header>

    <main class="table-container">
        
        <div class="action-bar">
            <div class="action-group">
                <button class="neu-btn-sm active">Semua</button>
                <button class="neu-btn-sm">Laporan</button>
            </div>
            <div class="action-group">
                <button class="neu-btn-sm" style="color: var(--accent-color);">+ Tambah Data</button>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama Aset</th>
                        <th>Lokasi</th>
                        <th>Status</th>
                        <th style="text-align: center;">Opsi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>#DB-01</td>
                        <td>Main Server</td>
                        <td>Rack A1</td>
                        <td><span class="status online">ONLINE</span></td>
                        <td style="text-align: center;">⚙️</td>
                    </tr>
                    <tr>
                        <td>#DB-02</td>
                        <td>Cloud Storage</td>
                        <td>Rack B4</td>
                        <td><span class="status online">ONLINE</span></td>
                        <td style="text-align: center;">⚙️</td>
                    </tr>
                    <tr>
                        <td>#DB-03</td>
                        <td>Backup Drive</td>
                        <td>Offsite</td>
                        <td><span class="status offline">OFFLINE</span></td>
                        <td style="text-align: center;">⚙️</td>
                    </tr>
                    <tr>
                        <td>#DB-04</td>
                        <td>Network Hub</td>
                        <td>Rack A2</td>
                        <td><span class="status online">ONLINE</span></td>
                        <td style="text-align: center;">⚙️</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pagination-bar">
            <button class="neu-btn-sm">Sebelumnya</button>
            <span class="page-info">Hal 1 dari 12</span>
            <button class="neu-btn-sm">Berikutnya</button>
        </div>

    </main>

</body>
</html>