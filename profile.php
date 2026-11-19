<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('profile.php');
    }

    if (isset($_POST['update_profile'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        if ($fullName === '') {
            flash_set('error', 'Name is required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            flash_set('error', 'Enter a valid email address.');
        } elseif (strlen($phone) > 30) {
            flash_set('error', 'Phone number must be 30 characters or fewer.');
        } else {
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, $user['id']]);
            if ($duplicate->fetch()) {
                flash_set('error', 'That email address is already used by another account.');
            } else {
                try {
                    $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');
                    $stmt->execute([$fullName, $email, $phone, $user['id']]);
                    // current_user() caches the row for the request; refresh session-backed reads too.
                    $_SESSION['user_email'] = $email;
                    flash_set('success', 'Profile updated.');
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') {
                        flash_set('error', 'That email address is already used by another account.');
                    } else {
                        throw $exception;
                    }
                }
            }
        }
        redirect('profile.php');
    }

    if (isset($_POST['change_password'])) {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $user['password'])) {
            flash_set('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash_set('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash_set('error', 'New passwords do not match.');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash_set('success', 'Password changed. Use it next time you log in.');
        }
        redirect('profile.php');
    }
}

$pageTitle = 'My Account';
$pageSubtitle = 'Update your profile and password';
include __DIR__ . '/includes/header.php';
?>

<div class="grid two">
  <div class="card">
    <h2>Profile</h2>
    <form method="post" action="profile.php">
      <?= csrf_field() ?>
      <input type="hidden" name="update_profile" value="1">

      <label for="full_name">Full Name</label>
      <input type="text" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" required>

      <label for="username">Username</label>
      <input type="text" id="username" value="<?= e($user['username']) ?>" disabled>

      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($user['email'] ?? '') ?>">

      <label for="phone">Phone</label>
      <input type="text" id="phone" name="phone" value="<?= e($user['phone'] ?? '') ?>">

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Profile</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Change Password</h2>
    <form method="post" action="profile.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="change_password" value="1">

      <label for="current_password">Current Password</label>
      <input type="password" id="current_password" name="current_password" required>

      <label for="new_password">New Password</label>
      <input type="password" id="new_password" name="new_password" required minlength="8">

      <label for="confirm_password">Confirm New Password</label>
      <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Change Password</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
