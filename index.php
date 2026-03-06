<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* ===== LOGOUT ===== */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    // Restart a clean empty session so nothing lingers
    session_start();
    session_regenerate_id(true);
    // Fall through to show login form — do NOT redirect
}

$valid_user = "Attendance";
$valid_pass = "SPM@123";
$error = '';

/* ===== LOGIN ===== */
if (isset($_POST['login'])) {
    if ($_POST['username'] === $valid_user && $_POST['password'] === $valid_pass) {
        session_regenerate_id(true);
        $_SESSION['user'] = $valid_user;
        header("Location: register_event.php");
        exit();
    } else {
        $error = "Invalid Username or Password";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>VIT Attendance Segregator - Login</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin:0; padding:0; }
        .main-header { text-align:center; padding:20px 10px; background:white; }
        .logo-row { display:flex; justify-content:center; align-items:center; gap:20px; margin-bottom:10px; }
        .logo-vit { height:100px; width:auto; }
        .logo-iic { height:80px; width:auto; }
        .header-text h2 { margin:0; font-size:18px; color:black; }
        .header-text h1 { margin:5px 0 0; font-size:24px; color:#111; }
        .login-box { width:400px; margin:30px auto; padding:30px; background:#fff; border-radius:10px; box-shadow:0 0 10px rgba(0,0,0,0.2); box-sizing:border-box; }
        .login-box h2 { text-align:center; margin-bottom:20px; }
        .login-box input { width:100%; padding:10px; margin:10px 0; box-sizing:border-box; border:1px solid #ccc; border-radius:5px; }
        .submit-btn { width:100%; padding:10px; background:rgb(27,0,93); color:white; border:none; cursor:pointer; border-radius:5px; font-weight:bold; font-size:15px; }
        .submit-btn:hover { background:rgb(45,0,140); }
        .error { color:red; text-align:center; margin-bottom:10px; }
    </style>

    <!-- Disable right-click and devtools -->
    <script>
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('keydown', e => {
            if (e.key === 'F12') e.preventDefault();
            if (e.ctrlKey && e.shiftKey && ['I','J','C'].includes(e.key)) e.preventDefault();
            if (e.ctrlKey && e.key === 'U') e.preventDefault();
        });
    </script>
</head>
<body>

<div class="main-header">
    <div class="logo-row">
        <img src="vit-logo.png" class="logo-vit">
        <img src="iic-logo.png" class="logo-iic">
    </div>
    <div class="header-text">
        <h2>Office of Innovation, Startup and Technology Transfer (VIT-IST)</h2>
        <h1>SMART ATTENDANCE SEGREGATOR</h1>
    </div>
</div>

<div class="login-box">
    <h2>Login</h2>
    <?php if ($error) echo "<p class='error'>".htmlspecialchars($error)."</p>"; ?>
    <form method="POST" autocomplete="off">
        <input type="text"     name="username" placeholder="Username" required autocomplete="off">
        <input type="password" name="password" placeholder="Password" required autocomplete="off">
        <button type="submit" name="login" class="submit-btn">Sign In</button>
    </form>
</div>

</body>
</html>
