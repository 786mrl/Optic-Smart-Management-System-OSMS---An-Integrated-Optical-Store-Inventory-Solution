<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frame Database Pro - Fixed</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f1113;
            --card-bg: #16181b;
            --accent: linear-gradient(135deg, #00d4ff 0%, #0055ff 100%);
            --accent-solid: #00d4ff;
            --danger: #ff4b2b;
            --shadow-dark: #08090a;
            --shadow-light: #1f2226;
            --text-main: #ffffff;
            --text-muted: #808b96;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            padding: 40px 15px;
            margin: 0;
            display: flex;
            justify-content: center;
        }

        .main-container {
            width: 100%;
            max-width: 1100px;
            overflow: hidden; /* Mencegah seluruh halaman meluap */
        }

        /* --- INPUT AREA --- */
        .input-bar-container {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 25px;
            box-shadow: 15px 15px 35px var(--shadow-dark), -10px -10px 30px var(--shadow-light);
            display: flex;
            gap: 15px;
            margin-bottom: 40px;
            border: 1px solid rgba(255,255,255,0.02);
        }

        .input-cyber {
            flex: 1;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.05);
            padding: 15px 20px;
            border-radius: 15px;
            color: white;
            outline: none;
            box-shadow: inset 6px 6px 12px var(--shadow-dark);
        }

        .btn-cyber {
            background: var(--accent);
            border: none;
            padding: 0 30px;
            border-radius: 15px;
            color: white;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 85, 255, 0.3);
        }

        /* --- TABLE AREA (FIXED LIMITS) --- */
        .table-responsive {
            width: 100%;
            background: var(--card-bg);
            border-radius: 30px;
            box-shadow: 20px 20px 60px var(--shadow-dark);
            overflow-x: auto; /* Mengaktifkan scroll horizontal jika data terlalu lebar */
            border: 1px solid rgba(255,255,255,0.03);
            padding: 10px; /* Jarak aman untuk bayangan dalam */
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
            min-width: 900px; /* Menjamin tabel punya ruang cukup dan tidak berhimpitan */
            table-layout: auto;
        }

        th {
            padding: 15px 20px;
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            text-align: left;
        }

        td {
            padding: 18px 20px;
            background: #1a1d21;
            border-top: 1px solid rgba(255,255,255,0.02);
            border-bottom: 1px solid rgba(255,255,255,0.02);
            font-size: 14px;
            white-space: nowrap; /* Menjaga teks tetap satu baris */
        }

        /* Efek Border Kiri Glow */
        tr td:first-child { 
            border-radius: 15px 0 0 15px; 
            border-left: 3px solid var(--accent-solid);
            box-shadow: -5px 0 10px rgba(0, 212, 255, 0.1);
        }
        tr td:last-child { border-radius: 0 15px 15px 0; border-right: 1px solid rgba(255,255,255,0.02); }

        /* Row Hover Glow */
        tr:hover td {
            background: #1d2126;
            border-color: rgba(0, 212, 255, 0.2);
            color: var(--accent-solid);
            cursor: default;
        }

        .ufc-tag {
            background: rgba(0, 212, 255, 0.1);
            color: var(--accent-solid);
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
        }

        /* --- ACTION CONTROLS --- */
        .action-flex {
            display: flex;
            gap: 10px;
        }

        .btn-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: none;
            background: var(--card-bg);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 3px 3px 6px var(--shadow-dark), -2px -2px 6px var(--shadow-light);
            transition: 0.3s;
        }

        .btn-edit:hover { color: var(--accent-solid); box-shadow: 0 0 10px rgba(0, 212, 255, 0.2); }
        .btn-delete:hover { color: var(--danger); box-shadow: 0 0 10px rgba(255, 75, 43, 0.2); }

        /* Custom Scrollbar for the table */
        .table-responsive::-webkit-scrollbar { height: 6px; }
        .table-responsive::-webkit-scrollbar-thumb { background: #333; border-radius: 10px; }
    </style>
</head>
<body>

    <div class="main-container">
        <div class="input-bar-container">
            <input type="text" class="input-cyber" placeholder="Enter UFC or Scan Barcode...">
            <button class="btn-cyber">PROCESS</button>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>UFC Tag</th>
                        <th>Brand & Model</th>
                        <th>Specs</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="ufc-tag">UFC-88921</span></td>
                        <td>
                            <div style="font-weight: 600;">Oakley Holbrook</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Sport Edition</div>
                        </td>
                        <td>O-Matter / Prizm Black</td>
                        <td>IDR 2.100.000</td>
                        <td><strong>8</strong></td>
                        <td>
                            <div class="action-flex">
                                <button class="btn-icon btn-edit" title="Edit">✎</button>
                                <button class="btn-icon btn-delete" title="Delete">✕</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>