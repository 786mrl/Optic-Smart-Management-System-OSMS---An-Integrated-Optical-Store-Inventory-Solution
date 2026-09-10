<?php
    // create_user.php
    session_start();
    include 'db_config.php';

    $message = '';

    // Extended-access DB connection loaded on demand only,
    // so the normal optic_pos flow never touches lisani_aos at all.
    function get_lisani_connection() {
        include __DIR__ . '/lisani_aos/db_config.php';
        return $lisani_conn;
    }

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        $access_mode = (isset($_POST['access_mode']) && $_POST['access_mode'] === 'extended')
            ? 'extended'
            : 'normal';

        if ($password !== $confirm_password) {
            $message = "<p style='color: red;'>Error: Passwords do not match.</p>";
        } else if (strlen($password) < 6) {
            $message = "<p style='color: red;'>Error: Password must be at least 6 characters long.</p>";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            if ($access_mode === 'extended') {
                // ========================================================
                // EXTENDED MODE — simpan ke lisani_aos_db
                // ========================================================
                $lisani_conn = get_lisani_connection();

                $check_sql = "SELECT COUNT(*) AS user_count FROM users";
                $result = $lisani_conn->query($check_sql);
                $row = $result->fetch_assoc();
                $user_count = (int)$row['user_count'];

                if ($user_count === 0) {
                    $role = 'admin';
                    $is_approved = 1;
                    $msg_text = "Admin account created successfully. You can now log in.";
                } else {
                    $role = 'staff';
                    $is_approved = 0;
                    $msg_text = "Staff account created successfully. Please wait for an Admin to approve your account.";
                }

                $stmt = $lisani_conn->prepare("INSERT INTO users (username, password_hash, role, is_approved) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $username, $password_hash, $role, $is_approved);

                if ($stmt->execute()) {
                    $_SESSION['success_msg'] = $msg_text;
                    $stmt->close();
                    $lisani_conn->close();
                    close_db_connection($conn);
                    header("Location: create_user.php");
                    exit();
                } else {
                    if ($lisani_conn->errno == 1062) {
                        $message = "<p style='color: red;'>Error: Username '$username' is already taken.</p>";
                    } else {
                        $message = "<p style='color: red;'>Error: " . $stmt->error . "</p>";
                    }
                }
                $stmt->close();
                $lisani_conn->close();

            } else {
                // ========================================================
                // NORMAL MODE — perilaku lama, TIDAK diubah (optic_pos)
                // ========================================================
                $check_sql = "SELECT COUNT(*) AS user_count FROM users";
                $result = $conn->query($check_sql);
                $row = $result->fetch_assoc();
                $user_count = (int)$row['user_count'];

                if ($user_count === 0) {
                    $role = 'admin';
                    $is_approved = 1;
                    $msg_text = "Admin account created successfully. You can now log in.";
                } else {
                    $role = 'staff';
                    $is_approved = 0;
                    $msg_text = "Staff account created successfully. Please wait for an Admin to approve your account.";
                }

                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, is_approved) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $username, $password_hash, $role, $is_approved);

                if ($stmt->execute()) {
                    $_SESSION['success_msg'] = $msg_text;
                    header("Location: create_user.php");
                    exit();
                } else {
                    if ($conn->errno == 1062) {
                        $message = "<p style='color: red;'>Error: Username '$username' is already taken.</p>";
                    } else {
                        $message = "<p style='color: red;'>Error: " . $stmt->error . "</p>";
                    }
                }
                $stmt->close();
            }
        }
    }

    // Retrieve success message from session if it exists
    if (isset($_SESSION['success_msg'])) {
        $message = "<p style='color: #00ff88; font-weight: bold; background: rgba(0,255,136,0.1); padding: 10px; border-radius: 8px;'>" . $_SESSION['success_msg'] . "</p>";
        unset($_SESSION['success_msg']);
    }

    close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New User</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function validatePassword() {
            var password = document.getElementById("password").value;
            var confirmPassword = document.getElementById("confirm_password").value;
            var messageElement = document.getElementById("password_error");

            if (password !== confirmPassword) {
                messageElement.textContent = "Error: Passwords do not match.";
                messageElement.style.color = "red";
                return false;
            } else if (password.length < 6) {
                messageElement.textContent = "Error: Password must be at least 6 characters long.";
                messageElement.style.color = "red";
                return false;
            } else {
                messageElement.textContent = "";
                return true;
            }
        }
    </script>
</head>
<body  style="flex-direction: column">
    
    <div class="user-window">
        <div class="header-section">
            <div class="avatar-box">👤</div>
            <h2>New Operator</h2>
            <p class="subtitle">System access account registration</p>
        </div>

        
        <?php echo $message; ?>
        <form action="create_user.php" method="POST" id="createUserForm">
                <div class="form-group">
                    <input class="input-neu" type="text" name="username" placeholder="Username" required>
                </div>    
    
                <div class="form-group">
                    <input type="password" class="input-neu" name="password" id="password"  placeholder="Password" required>
                </div>
    
                <div class="form-group">
                    <input type="password" class="input-neu"  name="confirm_password" id="confirm_password" placeholder="Repeat Password" required onkeyup="validatePassword()">
                </div>
                
                <div id="password_error" style="margin-bottom: 15px;"></div>

                <input type="hidden" name="access_mode" id="accessModeInput" value="normal">
                <div class="action-area">
                    <button type="button" id="createSubmitBtn" class="btn-submit">CREATE NEW ACCOUNT</button>
                    <button type="button" class="btn-back" onclick="window.location.href='login.php'">Cancel & Go Back</button>
                </div>
        </form>
    </div>

    <script>
        // ------------------------------------------------------------
        // Triple-click / triple-tap detection for CREATE ACCOUNT button
        // 1x click  -> normal mode  (optic_pos DB)
        // 3x clicks within 600ms -> extended mode (lisani_aos_db)
        // ------------------------------------------------------------
        (function () {
            var clickCount = 0;
            var clickTimer = null;
            var CLICK_WINDOW_MS = 600;

            var submitBtn = document.getElementById('createSubmitBtn');
            var form = document.getElementById('createUserForm');
            var accessModeInput = document.getElementById('accessModeInput');

            submitBtn.addEventListener('click', function (e) {
                e.preventDefault();
                clickCount++;
                clearTimeout(clickTimer);

                clickTimer = setTimeout(function () {
                    accessModeInput.value = (clickCount >= 3) ? 'extended' : 'normal';
                    clickCount = 0;
                    if (validatePassword()) {
                        form.submit();
                    }
                }, CLICK_WINDOW_MS);
            });
        })();
    </script>
</body>
</html>