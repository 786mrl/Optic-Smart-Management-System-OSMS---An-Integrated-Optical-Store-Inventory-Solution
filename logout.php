<?php
// logout.php
session_start();

// 1. Clear all session data on the server
$_SESSION = array();

// 2. If a session cookie exists, delete it too (optional but good for cleanliness)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session
session_destroy();

// 4. Redirect using JavaScript replace so that history is cleared
echo "
<!DOCTYPE html>
<html>
<head>
    <title>Logging Out...</title>
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