<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frame Database - Pure Display</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-color: #0f1113;
            --card-bg: #16181b;
            --accent: linear-gradient(135deg, #00d4ff 0%, #0055ff 100%);
            --accent-solid: #00d4ff;
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

        .main-container { width: 100%; max-width: 1000px; }

        /* --- INPUT AREA --- */
        .input-bar-container {
            background: var(--card-bg);
            padding: 18px;
            border-radius: 22px;
            box-shadow: 15px 15px 35px var(--shadow-dark), -10px -10px 30px var(--shadow-light);
            display: flex;
            gap: 12px;
            margin-bottom: 40px;
            border: 1px solid rgba(255,255,255,0.02);
            align-items: center;
        }

        .input-cyber {
            flex: 1;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.05);
            padding: 14px 20px;
            border-radius: 14px;
            color: white;
            outline: none;
            box-shadow: inset 6px 6px 12px var(--shadow-dark);
        }

        .btn-cyber {
            background: var(--accent);
            border: none;
            padding: 14px 28px;
            border-radius: 14px;
            color: white;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 85, 255, 0.3);
        }

        .btn-help {
            background: var(--card-bg);
            border: none;
            width: 48px;
            height: 48px;
            border-radius: 14px;
            color: var(--accent-solid);
            font-weight: 800;
            cursor: pointer;
            box-shadow: 4px 4px 10px var(--shadow-dark), -2px -2px 8px var(--shadow-light);
        }

        /* --- EMPTY STATE --- */
        #emptyBlock {
            display: none;
            text-align: center;
            padding: 80px 20px;
            background: var(--card-bg);
            border-radius: 30px;
            box-shadow: 20px 20px 60px var(--shadow-dark);
            border: 1px dashed rgba(255,255,255,0.05);
        }

        .empty-icon { font-size: 50px; margin-bottom: 15px; opacity: 0.3; }

        /* --- TABLE AREA (NO ACTIONS) --- */
        #tableBlock {
            width: 100%;
            background: var(--card-bg);
            border-radius: 25px;
            box-shadow: 20px 20px 60px var(--shadow-dark);
            overflow-x: auto;
            padding: 8px;
        }

        table { width: 100%; border-collapse: separate; border-spacing: 0 10px; min-width: 700px; }
        th { padding: 15px 20px; font-size: 11px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; text-align: left; }
        td { padding: 18px 20px; background: #1a1d21; font-size: 14px; }

        /* Highlight Row Style */
        tr td:first-child { 
            border-radius: 15px 0 0 15px; 
            border-left: 4px solid var(--accent-solid);
            font-weight: 800;
            color: var(--accent-solid);
        }
        tr td:last-child { border-radius: 0 15px 15px 0; font-weight: 600; color: #fff; }

        tr:hover td { background: #1e2227; }

    </style>
</head>
<body>

    <div class="main-container">
        <div class="input-bar-container">
            <input type="text" id="searchInput" class="input-cyber" placeholder="Scan UFC or Model Name...">
            <button class="btn-cyber" onclick="executeSearch()">PROCESS</button>
            <button class="btn-help" onclick="showInstruction()">?</button>
        </div>

        <div id="tableBlock">
            <table>
                <thead>
                    <tr>
                        <th>UFC ID</th>
                        <th>Brand & Frame Model</th>
                        <th>Material/Color</th>
                        <th>Price Unit</th>
                        <th>Stock Qty</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td>UFC-10022</td>
                        <td>Ray-Ban Aviator RB3025</td>
                        <td>Metal / Gold Green</td>
                        <td>IDR 2.450.000</td>
                        <td>12 Unit</td>
                    </tr>
                    <tr>
                        <td>UFC-10045</td>
                        <td>Oakley Holbrook OO9102</td>
                        <td>O-Matter / Prizm Black</td>
                        <td>IDR 2.100.000</td>
                        <td>5 Unit</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="emptyBlock">
            <div class="empty-icon">🔍</div>
            <h3 style="color: var(--accent-solid);">Data Tidak Ditemukan</h3>
            <p style="color: var(--text-muted); font-size: 14px;">Pastikan kode yang Anda masukkan atau hasil scan sudah benar.</p>
        </div>
    </div>

    <script>
        function showInstruction() {
            Swal.fire({
                title: 'Bantuan Sistem',
                html: '<div style="text-align:left; font-size:14px;">• Arahkan kursor ke input untuk scan barcode.<br>• Sistem otomatis menampilkan detail stok.<br>• Data akan disembunyikan jika tidak valid.</div>',
                background: '#16181b', color: '#fff', confirmButtonColor: '#0055ff'
            });
        }

        function executeSearch() {
            const query = document.getElementById('searchInput').value;
            const table = document.getElementById('tableBlock');
            const empty = document.getElementById('emptyBlock');

            // Logika simulasi: Jika input kosong atau "404", sembunyikan tabel
            if(query === "" || query.toLowerCase() === "404") {
                table.style.display = 'none';
                empty.style.display = 'block';
            } else {
                table.style.display = 'block';
                empty.style.display = 'none';
            }
        }
    </script>
</body>
</html>