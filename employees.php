<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();

$pdo = db();

/* ---------- Add employee ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $role     = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';

        if ($fullName === '' || $username === '' || strlen($password) < 8) {
            flash_set('error', 'Full name, username and a password of at least 8 characters are required.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (full_name, username, password, email, phone, role) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fullName, $username, password_hash($password, PASSWORD_DEFAULT), $email, $phone, $role]);
                add_audit_log('Staff account created', 'users', (int)$pdo->lastInsertId(), null, ['username' => $username, 'role' => $role]);
                flash_set('success', 'Employee account created.');
            } catch (PDOException $ex) {
                if ($ex->getCode() == 23000) {
                    flash_set('error', 'That username is already taken.');
                } else {
                    flash_set('error', 'Unable to create this account. Check the details and try again.');
                }
            }
        }
    }
    redirect('employees.php');
}

/* ---------- Toggle active ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
    } else {
        $id = (int)$_POST['id'];
        if ($id === (int)$_SESSION['user_id']) {
            flash_set('error', 'You cannot deactivate your own account.');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?");
            $stmt->execute([$id]);
            add_audit_log('User active status toggled', 'users', $id);
            flash_set('success', 'Account status updated.');
        }
    }
    redirect('employees.php');
}

/* ---------- Reset password ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
    } else {
        $newPass = (string)($_POST['new_password'] ?? '');
        if (strlen($newPass) < 8) {
            flash_set('error', 'New password must be at least 8 characters.');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($newPass, PASSWORD_DEFAULT), (int)$_POST['id']]);
            add_audit_log('User password reset by administrator', 'users', (int)$_POST['id']);
            flash_set('success', 'Password reset.');
        }
    }
    redirect('employees.php');
}

/* ---------- Delete ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
    } else {
        $id = (int)$_POST['id'];
        if ($id === (int)$_SESSION['user_id']) {
            flash_set('error', 'You cannot delete your own account.');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            flash_set('success', 'Account deactivated. Historical records are preserved.');
        }
    }
    redirect('employees.php');
}

$users = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll();

$pageTitle = 'Employees';
$pageSubtitle = 'Manage user accounts and roles';
include __DIR__ . '/includes/header.php';
?>

<div class="grid two">
  <div class="card">
    <h2>Add Employee</h2>
    <form method="post" action="employees.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="add" value="1">

      <label for="full_name">Full Name *</label>
      <input type="text" id="full_name" name="full_name" required>

      <label for="username">Username *</label>
      <input type="text" id="username" name="username" required>

      <div class="form-row">
        <div>
          <label for="password">Password *</label>
          <input type="password" id="password" name="password" required minlength="8">
        </div>
        <div>
          <label for="role">Role</label>
          <select id="role" name="role">
            <option value="staff">Staff / Cashier</option>
            <option value="admin">Administrator</option>
          </select>
        </div>
      </div>

      <label for="email">Email</label>
      <input type="email" id="email" name="email">

      <label for="phone">Phone</label>
      <input type="text" id="phone" name="phone">

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create Account</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Accounts</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['full_name']) ?><?= $u['id'] == $_SESSION['user_id'] ? ' <span class="muted">(you)</span>' : '' ?></td>
            <td class="muted"><?= e($u['username']) ?></td>
            <td><span class="badge badge-<?= e($u['role']) ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Staff' ?></span></td>
            <td><span class="badge badge-<?= $u['is_active'] ? 'active' : 'inactive' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="right" style="white-space:nowrap;">
              <?php if ($u['id'] != $_SESSION['user_id']): ?>
                <form method="post" action="employees.php" style="display:inline;" data-confirm="<?= $u['is_active'] ? 'Deactivate this staff account?' : 'Activate this staff account?' ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="toggle" value="1">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn <?= $u['is_active'] ? 'btn-danger' : 'btn-outline' ?> btn-sm" type="submit"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <button class="btn btn-outline btn-sm" data-modal-open="resetModal-<?= (int)$u['id'] ?>">Reset Password</button>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Reset password modal -->
          <div class="modal-overlay" id="resetModal-<?= (int)$u['id'] ?>">
            <div class="modal">
              <div class="modal-head">
                <h2>Reset Password</h2>
                <button class="modal-close" data-modal-close>&times;</button>
              </div>
              <p class="muted">Set a new password for <strong><?= e($u['full_name']) ?></strong>.</p>
              <form method="post" action="employees.php">
                <?= csrf_field() ?>
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <label for="new_password_<?= (int)$u['id'] ?>">New Password</label>
                <input type="password" id="new_password_<?= (int)$u['id'] ?>" name="new_password" required minlength="8">
                <div class="form-actions">
                  <button type="submit" class="btn btn-primary">Reset Password</button>
                  <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                </div>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
