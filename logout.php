<?php
// logout.php
session_start();
include 'db_config.php';

// 1. Delete session
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $conn->query("UPDATE users SET session_token = NULL, session_expires = NULL WHERE user_id = $uid");
}

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