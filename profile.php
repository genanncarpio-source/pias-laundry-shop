<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid request.');
        redirect('profile.php');
    }

    if (isset($_POST['update_profile'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        if ($fullName === '') {
            flash_set('error', 'Name is required.');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->execute([$fullName, $email, $phone, $user['id']]);
            flash_set('success', 'Profile updated.');
        }
        redirect('profile.php');
    }

    if (isset($_POST['change_password'])) {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $user['password'])) {
            flash_set('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            flash_set('error', 'New password must be at least 6 characters.');
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
      <input type="password" id="new_password" name="new_password" required minlength="6">

      <label for="confirm_password">Confirm New Password</label>
      <input type="password" id="confirm_password" name="confirm_password" required minlength="6">

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Change Password</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
