<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Role Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #1a1c1e;
            --card-bg: #222529;
            --accent: #00d4ff;
            --danger: #ff4d4d;
            --success: #00ffaa;
            --text: #ffffff;
            --shadow-light: rgba(255, 255, 255, 0.03);
            --shadow-dark: rgba(0, 0, 0, 0.4);
        }

        body {
            background-color: var(--bg-color);
            color: var(--text);
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex;
            justify-content: center;
            padding: 40px 20px;
        }

        /* --- MAIN CONTAINER --- */
        .glass-window {
            width: 100%;
            max-width: 1100px; /* Slightly widened to accommodate additional inputs */
            background: linear-gradient(145deg, #23272b, #1e2124);
            border-radius: 30px;
            padding: 35px;
            box-shadow: 25px 25px 50px #131517, -10px -10px 30px #2d3135;
            border: 1px solid rgba(255,255,255,0.05);
        }

        /* --- QUICK ADD BAR --- */
        .quick-add-bar {
            display: flex;
            gap: 12px;
            background: rgba(0, 0, 0, 0.2);
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 40px;
            box-shadow: inset 4px 4px 10px rgba(0,0,0,0.5);
            flex-direction: column;
        }

        .input-minimal, .select-minimal {
            flex: 1;
            min-width: 150px;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.1);
            padding: 12px 15px;
            border-radius: 12px;
            color: white;
            outline: none;
            transition: 0.3s;
            font-family: inherit;
        }

        /* Specific styling for dropdown */
        .select-minimal {
            cursor: pointer;
            background-color: var(--card-bg); /* Ensures visibility across different browsers */
        }

        .select-minimal option {
            background-color: var(--card-bg);
            color: white;
        }

        .input-minimal:focus, .select-minimal:focus {
            border-color: var(--accent);
            background: rgba(255,255,255,0.05);
        }

        .btn-glow {
            background: var(--accent);
            border: none;
            padding: 13px 25px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.3);
            transition: 0.3s;
            white-space: nowrap;
        }

        .btn-glow:hover {
            box-shadow: 0 0 25px rgba(0, 212, 255, 0.5);
            transform: translateY(-2px);
        }

        /* --- MODERN TABLE --- */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            font-size: 11px;
            text-transform: uppercase;
            color: #6a6e73;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        td {
            padding: 20px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.02);
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .badge {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            background: rgba(255,255,255,0.05);
        }

        .action-group {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: var(--card-bg);
            box-shadow: 4px 4px 8px #131517, -2px -2px 6px #2d3135;
            transition: 0.2s;
        }

        .btn-action:active {
            box-shadow: inset 2px 2px 5px #131517;
        }

        .icon-promote { color: var(--success); }
        .icon-delete { color: var(--danger); }

    </style>
</head>
<body>

    <div class="glass-window">
        <h2 style="margin-bottom: 25px; font-size: 18px;">Staff Management</h2>
        
        <div class="quick-add-bar">
            <input type="text" class="input-minimal" placeholder="Username">
            
            <input type="password" class="input-minimal" placeholder="Password">
            
            <select class="select-minimal">
                <option value="" disabled selected>Select Role</option>
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
                <option value="viewer">Viewer</option>
            </select>
            
            <button class="btn-glow">CREATE ACCOUNT</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Account Name</th>
                        <th>Access Role</th>
                        <th>Status</th>
                        <th>Actions Control</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="color: var(--accent);">#US-9901</td>
                        <td><strong>Alex_Vandermid</strong></td>
                        <td><span class="badge">Staff</span></td>
                        <td><span class="badge" style="color: var(--success);">ACTIVE</span></td>
                        <td>
                            <div class="action-group">
                                <button class="btn-action icon-promote" title="Promote">↑</button>
                                <button class="btn-action" title="Deactivate">⊘</button>
                                <button class="btn-action icon-delete" title="Delete">✕</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>