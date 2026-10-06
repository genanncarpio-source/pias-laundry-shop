<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid request. Please try again.';
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
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="icon">🧺</div>
      <h1><?= e($shopName) ?></h1>
      <p>Laundry Shop Management System</p>
    </div>

    <?php if ($error): ?>
      <div class="login-err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="index.php" autocomplete="off">
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

    <p class="muted mt-16 mb-0" style="text-align:center;font-size:12px;">
      Default login: <strong>admin / admin123</strong> &middot; staff / staff123
    </p>
  </div>
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
