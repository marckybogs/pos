<?php
// ============================================================
// login.php — Authentication page
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$timeout = !empty($_GET['timeout']);

// Handle logout
if (!empty($_GET['logout'])) {
    session_unset();
    session_destroy();
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Location: login.php');
    exit;
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['last_active'] = time();
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$shop_name = get_setting($conn, 'shop_name', 'QuickMart POS');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Login — <?= h($shop_name) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
body { background: var(--primary-color); display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-container { width: 100%; max-width: 420px; padding: 20px; }
.login-card { background: #fff; border-radius: 18px; padding: 40px 36px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
[data-theme="dark"] .login-card { background: #1a2133; }
.login-logo { text-align: center; margin-bottom: 28px; }
.login-logo .icon { width: 64px; height: 64px; background: var(--accent-color); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #1a2744; margin-bottom: 12px; }
.login-logo h1 { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.5rem; color: var(--text-primary); }
.login-logo p { color: var(--text-muted); font-size: .875rem; margin-top: 4px; }
.login-btn { width: 100%; padding: 13px; font-size: 1rem; font-weight: 600; border-radius: 10px; background: var(--primary-color); color: #fff; border: none; cursor: pointer; transition: background .15s; margin-top: 6px; font-family: 'Syne', sans-serif; }
.login-btn:hover { background: #253a6e; }
.hint { text-align: center; margin-top: 18px; font-size: .78rem; color: var(--text-muted); }
.hint code { background: var(--bg-color); padding: 1px 5px; border-radius: 4px; font-size: .82em; }
</style>
</head>
<body>
<div class="login-container">
  <div class="login-card">
    <div class="login-logo">
      <div class="icon"><i class="fa-solid fa-cash-register"></i></div>
      <h1><?= h($shop_name) ?></h1>
      <p>Point of Sale System</p>
    </div>

    <?php if ($timeout): ?>
    <div class="alert alert-warning"><i class="fa-solid fa-clock"></i> Your session has expired. Please log in again.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group">
        <label class="form-label">Username</label>
        <div class="search-bar">
          <i class="fa-solid fa-user search-icon"></i>
          <input type="text" name="username" class="form-control" placeholder="Enter username"
                 value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <div class="search-bar">
          <i class="fa-solid fa-lock search-icon"></i>
          <input type="password" name="password" class="form-control" placeholder="Enter password"
                 autocomplete="current-password" required>
        </div>
      </div>
      <button type="submit" class="login-btn"><i class="fa-solid fa-right-to-bracket"></i> &nbsp;Sign In</button>
    </form>

    <div class="hint">
      Default: <code>admin</code> / <code>password</code> &nbsp;|&nbsp; <code>cashier</code> / <code>password</code>
    </div>
  </div>
</div>
<script>
const saved = localStorage.getItem('pos_theme') || 'light';
document.documentElement.setAttribute('data-theme', saved);
</script>
</body>
</html>
