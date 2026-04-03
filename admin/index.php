<?php
/**
 * admin/index.php — Admin login page.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once dirname(__DIR__) . '/config.php';

// Already logged in?
if (!empty($_SESSION[ADMIN_SESSION_KEY])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (hash_equals(ADMIN_PASSWORD, $password)) {
        $_SESSION[ADMIN_SESSION_KEY] = true;
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
       background: #f0f2f5; display: flex; justify-content: center;
       align-items: center; min-height: 100vh; }
.login-box { background: #fff; border-radius: 12px; padding: 40px 36px;
             box-shadow: 0 4px 20px rgba(0,0,0,.1); width: 100%; max-width: 360px; }
h1 { font-size: 22px; margin-bottom: 6px; color: #1a1a2e; }
p  { font-size: 13px; color: #6b7280; margin-bottom: 24px; }
label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #374151; }
input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db;
  border-radius: 6px; font-size: 14px; margin-bottom: 16px; }
input[type=password]:focus { outline: none; border-color: #4f46e5; }
button { width: 100%; padding: 11px; background: #4f46e5; color: #fff;
         border: none; border-radius: 6px; font-size: 15px; cursor: pointer;
         font-weight: 600; }
button:hover { background: #4338ca; }
.error { background: #fee2e2; color: #991b1b; padding: 10px 14px; border-radius: 6px;
         font-size: 14px; margin-bottom: 16px; }
</style>
</head>
<body>
<div class="login-box">
  <h1>🔐 Admin Login</h1>
  <p>Enter your admin password to continue.</p>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autofocus required>
    <button type="submit">Sign in</button>
  </form>
</div>
</body>
</html>
