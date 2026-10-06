<?php
/**
 * One-time password reset / self-diagnostic utility.
 *
 * HOW TO USE:
 *   1. Copy this file into your Laragon project folder (same folder as index.php).
 *   2. Open it in your browser:  http://localhost/laundry-pos/reset_password.php
 *   3. It resets the default accounts and reports what it finds.
 *   4. DELETE THIS FILE when you are done (it can reset passwords!).
 *
 * Default accounts it resets:
 *   admin -> admin123
 *   staff -> staff123
 *
 * To reset a different account, append ?username=someone&password=newpass
 */

require_once __DIR__ . '/includes/auth.php';

$pdo = db();
$out = [];

/* ---------- Figure out what to reset ---------- */
$username = trim($_GET['username'] ?? '');
$newpass  = (string)($_GET['password'] ?? '');

if ($username !== '') {
    $targets = [[$username, $newpass !== '' ? $newpass : 'admin123']];
} else {
    $targets = [
        ['admin', 'admin123'],
        ['staff', 'staff123'],
    ];
}

/* ---------- Diagnostics: what's actually in the DB? ---------- */
try {
    $all = $pdo->query("SELECT id, username, LEFT(password,7) AS hash_prefix, CHAR_LENGTH(password) AS hash_len, role, is_active FROM users ORDER BY id")->fetchAll();
    $out['users_in_db'] = $all;
} catch (Exception $e) {
    $out['users_in_db'] = 'ERROR reading users table: ' . $e->getMessage();
}

/* ---------- Reset each target ---------- */
$results = [];
foreach ($targets as [$uname, $pw]) {
    $row = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $row->execute([$uname]);
    $existing = $row->fetch();

    $hash = password_hash($pw, PASSWORD_DEFAULT);

    if ($existing) {
        $upd = $pdo->prepare("UPDATE users SET password = ?, is_active = 1 WHERE id = ?");
        $upd->execute([$hash, $existing['id']]);
        $results[] = "OK — reset <b>$uname</b> (id {$existing['id']}) to <code>$pw</code>";
    } else {
        $ins = $pdo->prepare("INSERT INTO users (full_name, username, password, role, is_active) VALUES (?, ?, ?, 'admin', 1)");
        $ins->execute([ucfirst($uname), $uname, $hash]);
        $results[] = "OK — created missing user <b>$uname</b> with password <code>$pw</code>";
    }

    /* Verify the write actually took. */
    $chk = $pdo->prepare("SELECT password FROM users WHERE username = ?");
    $chk->execute([$uname]);
    $stored = $chk->fetchColumn();
    if (!password_verify($pw, $stored)) {
        $results[] = "!! FAILED to verify <b>$uname</b> after write — check DB permissions.";
    }
}

$out['results'] = $results;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Password Reset Tool</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 40px auto; max-width: 720px; padding: 0 20px; color: #0f172a; }
  h1 { font-size: 22px; }
  .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin: 12px 0; }
  table { border-collapse: collapse; width: 100%; }
  td, th { border-bottom: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 14px; }
  .ok { color: #16a34a; font-weight: 600; }
  .warn { color: #dc2626; font-weight: 600; }
  code { background: #eef2ff; padding: 1px 5px; border-radius: 4px; }
  .danger { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 12px; border-radius: 8px; margin-top: 16px; }
</style>
</head>
<body>
<h1>🔑 Password Reset Tool</h1>

<div class="box">
  <?php foreach ($out['results'] as $r): ?>
    <div class="ok"><?= $r ?></div>
  <?php endforeach; ?>
</div>

<h2>Current users in database</h2>
<div class="box">
  <?php if (is_array($out['users_in_db'])): ?>
    <table>
      <tr><th>ID</th><th>Username</th><th>Hash prefix</th><th>Hash len</th><th>Role</th><th>Active</th></tr>
      <?php foreach ($out['users_in_db'] as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= htmlspecialchars($u['hash_prefix'] ?? '?') ?></td>
          <td><?= (int)($u['hash_len'] ?? 0) ?></td>
          <td><?= htmlspecialchars($u['role']) ?></td>
          <td><?= $u['is_active'] ? 'yes' : 'no' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p style="font-size:13px;color:#64748b;">A healthy password hash starts with <code>$2y$</code> and is exactly 60 characters long.</p>
  <?php else: ?>
    <div class="warn"><?= htmlspecialchars((string)$out['users_in_db']) ?></div>
  <?php endif; ?>
</div>

<div class="danger">
  ⚠️ <strong>Delete this file</strong> (<code>reset_password.php</code>) right after use —
  anyone who can open it can reset passwords.
</div>
</body>
</html>
