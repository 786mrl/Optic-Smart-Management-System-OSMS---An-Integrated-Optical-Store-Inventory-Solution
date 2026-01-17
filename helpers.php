<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neumorphic Data Window</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #1e2124;
            --shadow-light: #2a2e32;
            --shadow-dark: #121416;
            --accent-color: #00d4ff;
            --danger-color: #ff4b2b;
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
            padding: 20px;
        }

        /* --- WINDOW CONTAINER --- */
        .window-card {
            background-color: var(--bg-color);
            width: 100%;
            max-width: 800px;
            padding: 40px;
            border-radius: 30px;
            box-shadow: 20px 20px 60px var(--shadow-dark), 
                        -20px -20px 60px var(--shadow-light);
        }
        /* ------------------------------------------------------------ */
        h2 {
            margin-bottom: 30px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 1px;
        }
        /* ---------------------------------------------------------------- */
        /* --- TABLE SECTION --- */
        .table-container {
            width: 100%;
            overflow-x: auto;
            margin-bottom: 40px;
            padding: 5px;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 15px; /* Memberi jarak antar baris agar efek timbul terlihat */
        }

        th {
            padding: 15px;
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            text-align: left;
        }

        td {
            padding: 15px;
            background: var(--bg-color);
            font-size: 14px;
        }

        /* Membuat setiap baris terlihat seperti kartu neumorphic */
        tr td:first-child { border-radius: 15px 0 0 15px; }
        tr td:last-child { border-radius: 0 15px 15px 0; }

        tr td {
            box-shadow: 5px 5px 10px var(--shadow-dark), 
                        -5px -5px 10px var(--shadow-light);
        }

        /* --- ACTION BUTTON (DELETE) --- */
        .btn-delete {
            background: var(--bg-color);
            border: none;
            color: var(--danger-color);
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 3px 3px 6px var(--shadow-dark), 
                        -3px -3px 6px var(--shadow-light);
            transition: 0.2s;
        }

        .btn-delete:active {
            box-shadow: inset 2px 2px 5px var(--shadow-dark), 
                        inset -2px -2px 5px var(--shadow-light);
            transform: scale(0.95);
        }

        /* --- INPUT & UPDATE SECTION --- */
        .form-update {
            border-top: 1px solid var(--shadow-light);
            padding-top: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .input-wrapper {
            display: flex;
            /* ---------------------------------------- */
            gap: 15px;
            width: 100%;
            /* ---------------------------------------- */
        }

        input {
            /* --------------------------------- */
            flex: 1;
            /* ----------------------------------- */
            background: var(--bg-color);
            border: none;
            outline: none;
            padding: 15px 20px;
            border-radius: 15px;
            color: var(--text-main);
            box-shadow: inset 5px 5px 10px var(--shadow-dark), 
                        inset -5px -5px 10px var(--shadow-light);
        }

        .btn-update {
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 15px;
            background: var(--bg-color);
            color: var(--accent-color);
            font-weight: 700;
            cursor: pointer;
            box-shadow: 8px 8px 16px var(--shadow-dark), 
                        -8px -8px 16px var(--shadow-light);
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: 0.3s;
        }

        .btn-update:active {
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
        }

        /* Responsive */
        @media (max-width: 600px) {
            .input-wrapper { flex-direction: column; }
            .window-card { padding: 25px; }
        }
    </style>
</head>
<body>

    <div class="window-card">
        <h2>DATABASE TERMINAL</h2>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Device Name</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td>#01</td>
                        <td>Main Server Alpha</td>
                        <td style="color: var(--accent-color);">Active</td>
                        <td><button class="btn-delete">DELETE</button></td>
                    </tr>
                    
                    <tr>
                        <td>#02</td>
                        <td>Backup Module</td>
                        <td style="color: var(--text-muted);">Standby</td>
                        <td><button class="btn-delete">DELETE</button></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form class="form-update" onsubmit="event.preventDefault()">
            <div class="input-wrapper">
                <input type="text" placeholder="Entry ID">
                <input type="text" placeholder="Update Value">
            </div>
            <button type="submit" class="btn-update">Update Data System</button>
        </form>
    </div>

</body>
</html>