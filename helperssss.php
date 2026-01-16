<!DOCTYPE html>
<html lang="en"> <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Welcome - Neumorphic System</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
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
            overflow: hidden;
        }

        .welcome-container {
            text-align: center;
            animation: fadeIn 1.2s ease-out;
        }

        /* --- NEUMORPHIC PROFILE PICTURE --- */
        .profile-circle {
            width: 150px;
            height: 150px;
            background: var(--bg-color);
            margin: 0 auto 30px auto;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 60px;
            box-shadow: 15px 15px 30px var(--shadow-dark), 
                        -15px -15px 30px var(--shadow-light);
            position: relative;
        }

        /* Light Ring Decoration */
        .profile-circle::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 2px solid var(--accent-color);
            opacity: 0.3;
            animation: pulse 2s infinite;
        }

        h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .user-name {
            color: var(--accent-color);
            display: block;
            font-size: 32px;
            margin-top: 5px;
        }

        .status-msg {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 15px;
            letter-spacing: 1px;
        }

        /* --- NEUMORPHIC LOADING BAR --- */
        .loader-track {
            width: 200px;
            height: 8px;
            background: var(--bg-color);
            margin: 40px auto 0 auto;
            border-radius: 10px;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), 
                        inset -3px -3px 6px var(--shadow-light);
            overflow: hidden;
            position: relative;
        }

        .loader-fill {
            position: absolute;
            height: 100%;
            width: 40%;
            background: var(--accent-color);
            border-radius: 10px;
            box-shadow: 0 0 10px var(--accent-color);
            animation: loadingMove 2s infinite ease-in-out;
        }

        /* --- ANIMATIONS --- */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.3; }
            50% { transform: scale(1.1); opacity: 0.1; }
            100% { transform: scale(1); opacity: 0.3; }
        }

        @keyframes loadingMove {
            0% { left: -40%; }
            100% { left: 110%; }
        }

        @media (max-width: 600px) {
            h1 { font-size: 22px; }
            .user-name { font-size: 26px; }
            .profile-circle { width: 120px; height: 120px; font-size: 50px; }
        }
    </style>
</head>
<body>

    <div class="welcome-container">
        <div class="profile-circle">
            👤
        </div>

        <h1>Welcome,</h1>
        <span class="user-name">Budi Santoso</span>
        
        <p class="status-msg">Preparing your Dashboard...</p>

        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>

    <script>
        // Simulate redirect to main page after 3.5 seconds
        setTimeout(() => {
            console.log("Redirecting to Dashboard...");
            // window.location.href = 'dashboard.html'; 
        }, 3500);
    </script>

</body>
</html>