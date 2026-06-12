<!DOCTYPE html>
<html lang="en"> <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Secure System</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* --- COLOR VARIABLES (CONSISTENT) --- */
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
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        /* --- LOGIN CARD --- */
        .login-card {
            width: 100%;
            max-width: 400px;
            background-color: var(--bg-color);
            padding: 50px 40px;
            border-radius: 40px;
            box-shadow: 20px 20px 60px var(--shadow-dark), 
                        -20px -20px 60px var(--shadow-light);
            text-align: center;
        }

        .login-logo {
            width: 80px;
            height: 80px;
            background: var(--bg-color);
            margin: 0 auto 30px auto;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 30px;
            box-shadow: 8px 8px 16px var(--shadow-dark), 
                        -8px -8px 16px var(--shadow-light);
        }

        h2 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        p.subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 40px;
        }

        /* --- INPUT STYLING --- */
        .input-group {
            margin-bottom: 25px;
            text-align: left;
        }

        label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 10px;
            margin-left: 15px;
            text-transform: uppercase;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        input {
            width: 100%;
            border: none;
            outline: none;
            background: var(--bg-color);
            padding: 16px 20px;
            border-radius: 18px;
            color: var(--text-main);
            font-size: 15px;
            /* Neumorphic Inset Effect */
            box-shadow: inset 6px 6px 12px var(--shadow-dark), 
                        inset -6px -6px 12px var(--shadow-light);
            transition: 0.3s;
        }

        input:focus {
            color: var(--accent-color);
            box-shadow: inset 3px 3px 6px var(--shadow-dark), 
                        inset -3px -3px 6px var(--shadow-light);
        }

        /* --- BUTTON STYLING --- */
        .login-btn {
            width: 100%;
            margin-top: 20px;
            padding: 18px;
            border: none;
            border-radius: 18px;
            background: var(--bg-color);
            color: var(--accent-color);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            /* Neumorphic Shadow Effect */
            box-shadow: 8px 8px 16px var(--shadow-dark), 
                        -8px -8px 16px var(--shadow-light);
            transition: all 0.2s ease;
        }

        .login-btn:hover {
            transform: scale(0.99);
            color: #fff;
        }

        .login-btn:active {
            box-shadow: inset 5px 5px 10px var(--shadow-dark), 
                        inset -5px -5px 10px var(--shadow-light);
        }

        .forgot-pass {
            display: block;
            margin-top: 25px;
            font-size: 12px;
            color: var(--text-muted);
            text-decoration: none;
            transition: 0.3s;
        }

        .forgot-pass:hover {
            color: var(--accent-color);
        }

        /* --- MOBILE RESPONSIVENESS --- */
        @media (max-width: 480px) {
            .login-card {
                padding: 40px 25px;
                box-shadow: 10px 10px 30px var(--shadow-dark), 
                            -10px -10px 30px var(--shadow-light);
            }
            h2 { font-size: 20px; }
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-logo">🔐</div>
        
        <h2>Welcome Back</h2>
        <p class="subtitle">Please login to access your dashboard</p>

        <form>
            <div class="input-group">
                <label>Username</label>
                <div class="input-wrapper">
                    <input type="text" placeholder="Enter your username" required>
                </div>
            </div>

            <div class="input-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <input type="password" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="login-btn">LOGIN TO SYSTEM</button>
        </form>

        <a href="#" class="forgot-pass">Forgot your password?</a>
    </div>

</body>
</html>