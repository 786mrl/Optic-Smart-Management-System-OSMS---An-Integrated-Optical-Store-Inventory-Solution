<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Staff Accounts - Neumorphism</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #1e2124;
            --shadow-light: #2a2e32;
            --shadow-dark: #121416;
            --accent-color: #00d4ff;
            --text-main: #e2e8f0;
            --text-muted: #718096;
            --success-color: #00ff88;
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

        .window-card {
            background-color: var(--bg-color);
            width: 100%;
            max-width: 850px;
            padding: 40px;
            border-radius: 40px;
            box-shadow: 20px 20px 60px var(--shadow-dark), 
                        -20px -20px 60px var(--shadow-light);
        }

        .header-title {
            text-align: center;
            margin-bottom: 30px;
        }

        h2 { font-size: 22px; font-weight: 700; }
        .subtitle { font-size: 13px; color: var(--text-muted); margin-top: 5px; }

        /* --- CONDITION: IF EMPTY --- */
        .empty-state {
            display: none; /* Change to 'block' to show empty status */
            text-align: center;
            padding: 50px 20px;
            border-radius: 25px;
            box-shadow: inset 10px 10px 20px var(--shadow-dark), 
                        inset -10px -10px 20px var(--shadow-light);
            margin: 20px 0;
        }

        .empty-icon { font-size: 40px; margin-bottom: 15px; opacity: 0.5; }

        /* --- CONDITION: IF DATA EXISTS (TABLE) --- */
        .table-responsive_approve_user {
            width: 100%;
            overflow-x: auto;
            padding: 15px; /* EXTRA SPACE: So edge shadows aren't clipped */
            -webkit-overflow-scrolling: touch;
        }

        /* Adjust table so it doesn't stick to the edges */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 15px; /* Row spacing */
            min-width: 600px; /* Prevents columns from being too cramped on small screens */
        }

        th {
            padding: 10px 20px;
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
        }

        td {
            padding: 20px;
            background: var(--bg-color);
            font-size: 14px;
            /* Use slightly smaller shadows for safety */
            box-shadow: 4px 4px 10px var(--shadow-dark), 
                        -4px -4px 10px var(--shadow-light);
            border: none;
        }

        /* Smooth out row corners */
        tr td:first-child { 
            border-radius: 20px 0 0 20px; 
            padding-left: 25px;
        }

        tr td:last-child { 
            border-radius: 0 20px 20px 0; 
            padding-right: 25px;
        }

        /* --- APPROVE BUTTON --- */
        .btn-approve {
            background: var(--bg-color);
            border: none;
            color: var(--success-color);
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            box-shadow: 4px 4px 8px var(--shadow-dark), 
                        -4px -4px 8px var(--shadow-light);
            transition: 0.2s;
        }

        .btn-approve:active {
            box-shadow: inset 3px 3px 6px var(--shadow-dark), 
                        inset -3px -3px 6px var(--shadow-light);
            transform: scale(0.95);
        }

        .btn-back {
            display: block;
            margin: 30px auto 0;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 13px;
        }
        .window-card {
            /* ... other styles ... */
            overflow: hidden; /* Keeps main card corners rounded */
        }

    </style>
</head>
<body>

    <div class="window-card">
        <div class="header-title">
            <h2>Pending Staff Accounts</h2>
            <p class="subtitle">List of accounts waiting for administrator approval</p>
        </div>

        <div class="table-responsive_approve_user">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Username</th>
                        <th>Registered Date</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="color: var(--accent-color); font-weight: 700;">#STF-082</td>
                        <td>Budi_Tech</td>
                        <td>18 Jan 2026, 14:20</td>
                        <td style="text-align: center;">
                            <button class="btn-approve">APPROVE</button>
                        </td>
                    </tr>
                    <tr>
                        <td style="color: var(--accent-color); font-weight: 700;">#STF-083</td>
                        <td>Santi_Operator</td>
                        <td>18 Jan 2026, 16:05</td>
                        <td style="text-align: center;">
                            <button class="btn-approve">APPROVE</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="empty-state" id="emptyMessage">
            <div class="empty-icon">📂</div>
            <p style="font-weight: 600;">No pending staff accounts</p>
            <p class="subtitle">All account requests have been processed.</p>
        </div>

        <button class="btn-back">Back to Dashboard</button>
    </div>

    <script>
        // Toggle example: if data array is empty, show empty-state
        const hasData = true; // Change to false to see "No Data" view
        if(!hasData) {
            document.querySelector('.table-responsive_approve_user').style.display = 'none';
            document.getElementById('emptyMessage').style.display = 'block';
        }
    </script>

</body>
</html>