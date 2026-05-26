<?php
// logout.php
session_start();

// 1. Sync background ke Supabase sebelum logout
$host     = $_SERVER['HTTP_HOST'];
$base     = dirname($_SERVER['SCRIPT_NAME']);
$sync_url = 'http://' . $host . $base . '/sync_background.php';
@file_get_contents($sync_url, false, stream_context_create([
    'http' => ['timeout' => 1, 'ignore_errors' => true],
    'ssl'  => ['verify_peer' => false]
]));

// 2. Clear all session data on the server
$_SESSION = array();

// 3. If a session cookie exists, delete it too (optional but good for cleanliness)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destroy the session
session_destroy();

// 5. Redirect using JavaScript replace so that history is cleared
echo "
<!DOCTYPE html>
<html>
<head>
    <title>Logging Out...</title>
    <?php include 'pwa_head.php'; ?>
</head>
<body>
    <script>
        // Clear local cache (if any)
        localStorage.clear();
        sessionStorage.clear();
        
        // Replace current history position with login.php to prevent 'Back' navigation
        window.location.replace('login.php');
    </script>
</body>
</html>
";
exit;
?>