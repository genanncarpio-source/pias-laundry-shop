<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'This form has expired. Refresh the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (!$user['is_active']) {
                $error = 'This account has been deactivated. Please contact the administrator.';
            } else {
                session_regenerate_id(true);
                unset($_SESSION['customer_id']);
                $_SESSION['user_id'] = $user['id'];
                redirect('dashboard.php');
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$shopName = get_setting('shop_name', "Pia's Laundry Shop");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · <?= e($shopName) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=8">
<meta name="theme-color" content="#f5e8ff">
<style>
.login-page .login-wrap{position:relative;isolation:isolate;overflow:hidden;min-height:100vh;padding:30px 18px;background:#f5e8ff url('assets/img/login-lavender.svg') center/cover no-repeat}
.login-page .login-card{background:rgba(255,250,255,.94);border:1px solid #dfc5ef;border-top:4px solid #7b31a8;box-shadow:0 22px 65px rgba(61,24,82,.2);backdrop-filter:blur(8px)}
.login-page .login-brand .icon{filter:drop-shadow(0 4px 12px rgba(123,49,168,.22))}
.login-page .login-brand h1{color:#321a3d}
.login-page .login-brand p{color:#78617f}
.login-page .login-card label{color:#4b3154}
.login-page .login-card input{background:#fffaff;border-color:#d9c5e2;color:#29182d}
.login-page .login-card input:focus{border-color:#8d48c2;box-shadow:0 0 0 3px rgba(141,72,194,.16)}
.login-page .login-card .btn-primary{background:#58265f;border-color:#58265f;color:#fff}
.login-page .login-card .btn-primary:hover{background:#49204f}
.login-page .login-wrap>p a{color:#704478}
@media(max-width:560px){.login-page .login-wrap{padding:20px 16px;background-position:center}}
</style>
</head>
<body class="login-page">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="icon">🧺</div>
      <h1><?= e($shopName) ?></h1>
      <p>Laundry Management System</p>
    </div>

    <?php if ($error): ?>
      <div class="login-err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="off">
      <?= csrf_field() ?>
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus
             value="<?= e($_POST['username'] ?? '') ?>">

      <label for="password">Password</label>
      <div class="password-field">
        <input type="password" id="password" name="password" required>
        <button type="button" class="toggle-password" id="togglePassword"
                aria-label="Show password" title="Show password">👁️</button>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-block">Sign In</button>
      </div>
    </form>

  </div>
  <p style="text-align:center;margin:16px 0"><a href="index.php">← Back to shop website</a></p>
</div>

<script>
(function () {
  var field = document.getElementById('password');
  var toggle = document.getElementById('togglePassword');
  if (field && toggle) {
    toggle.addEventListener('click', function () {
      var show = field.type === 'password';
      field.type = show ? 'text' : 'password';
      toggle.textContent = show ? '🙈' : '👁️';
      toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      toggle.setAttribute('title', show ? 'Hide password' : 'Show password');
      field.focus();
    });
  }
})();
</script>
</body>
</html>
