<!DOCTYPE html>
<html lang="en"> <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New User - Dark Neumorphism</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        /* --- WINDOW CONTAINER --- */
        .user-window {
            background-color: var(--bg-color);
            width: 100%;
            max-width: 420px;
            padding: 40px;
            border-radius: 40px;
            box-shadow: 20px 20px 60px var(--shadow-dark), 
                        -20px -20px 60px var(--shadow-light);
        }

        /* --- HEADER SECTION --- */
        .header-section {
            text-align: center;
            margin-bottom: 35px;
        }

        .avatar-box {
            width: 70px;
            height: 70px;
            background: var(--bg-color);
            margin: 0 auto 20px;
            border-radius: 22px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 30px;
            box-shadow: 8px 8px 16px var(--shadow-dark), 
                        -8px -8px 16px var(--shadow-light);
        }
/* ---------------------------------------------------------------------------- */
        h2 { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .subtitle { font-size: 12px; color: var(--text-muted); }
        
        /* --- FORM STYLING --- */
        .form-group {
            margin-bottom: 22px;
        }
        
        label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--text-muted);
            margin-bottom: 8px;
            margin-left: 10px;
        }
        /* ---------------------------------------------------------------------------- */

        .input-neu {
            width: 100%;
            background: var(--bg-color);
            border: none;
            outline: none;
            padding: 16px 20px;
            border-radius: 15px;
            color: var(--text-main);
            font-size: 14px;
            /* Inset Effect */
            box-shadow: inset 5px 5px 10px var(--shadow-dark), 
                        inset -5px -5px 10px var(--shadow-light);
            transition: all 0.3s ease;
        }

        .input-neu:focus {
            box-shadow: inset 2px 2px 5px var(--shadow-dark), 
                        inset -2px -2px 5px var(--shadow-light);
            color: var(--accent-color);
        }

        /* --- ACTION AREA --- */
        .action-area {
            margin-top: 35px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .btn-submit {
            padding: 18px;
            border: none;
            border-radius: 15px;
            background: var(--bg-color);
            color: var(--accent-color);
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            box-shadow: 6px 6px 12px var(--shadow-dark), 
                        -6px -6px 12px var(--shadow-light);
            transition: 0.2s;
        }

        .btn-submit:active {
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
            transform: scale(0.98);
        }

        .btn-back {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            cursor: pointer;
            padding: 5px;
            transition: 0.2s;
        }

        .btn-back:hover { color: var(--danger); }

    </style>
</head>
<body>

    <div class="user-window">
        <div class="header-section">
            <div class="avatar-box">👤</div>
            <h2>New Operator</h2>
            <p class="subtitle">System access account registration</p>
        </div>

        <form onsubmit="event.preventDefault()">
            <div class="form-group">
                <label>Username</label>
                <input type="text" class="input-neu" placeholder="Admin_01" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" class="input-neu" placeholder="••••••••" required>
            </div>

            <div class="form-group">
                <label>Repeat Password</label>
                <input type="password" class="input-neu" placeholder="••••••••" required>
            </div>

            <div class="action-area">
                <button type="submit" class="btn-submit">CREATE NEW ACCOUNT</button>
                <button type="button" class="btn-back">Cancel & Go Back</button>
            </div>
        </form>
    </div>

</body>
</html>

<div class="login-container">
        <h2>Create New User</h2>
        <?php echo $message; ?>
        <form action="create_user.php" method="POST" class="login-form" onsubmit="return validatePassword()"> 
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" id="password" placeholder="Password" required>
            <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required onkeyup="validatePassword()">
            <div id="password_error" style="margin-bottom: 15px;"></div>
            <button type="submit" class="login-button">Register</button>
        </form>
        <p><a href="login.php" class="link-back">Back to Login</a></p>
    </div>