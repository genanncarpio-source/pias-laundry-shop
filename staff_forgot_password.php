<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';

if (current_user()) redirect('dashboard.php');

$error = '';
$notice = '';
$email = (string)($_SESSION['staff_reset_email'] ?? '');
$verifiedUserId = (int)($_SESSION['staff_reset_verified_user_id'] ?? 0);
$verifiedUntil = (int)($_SESSION['staff_reset_verified_until'] ?? 0);
if ($verifiedUntil < time()) {
    unset($_SESSION['staff_reset_verified_user_id'], $_SESSION['staff_reset_verified_until']);
    $verifiedUserId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'This form has expired. Refresh the page and try again.';
    } elseif (isset($_POST['request_code'])) {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            $error = 'Enter a valid email address.';
        } else {
            $query = db()->prepare("SELECT id, full_name, email FROM users WHERE LOWER(email) = ? AND email IS NOT NULL AND email <> '' AND is_active = 1 LIMIT 1");
            $query->execute([$email]);
            $user = $query->fetch();
            $_SESSION['staff_reset_email'] = $email;
            if ($user) {
                $pendingQuery = db()->prepare('SELECT id, resend_count, TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_sent, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_since_created FROM staff_password_reset_otps WHERE user_id = ? LIMIT 1');
                $pendingQuery->execute([$user['id']]);
                $pending = $pendingQuery->fetch();
                if ($pending && (int)$pending['seconds_since_sent'] < 60) {
                    $error = 'Please wait 60 seconds before requesting another code.';
                } else {
                    $sameHour = $pending && (int)$pending['seconds_since_created'] < 3600;
                    $resendCount = $sameHour ? (int)$pending['resend_count'] + 1 : 0;
                    if ($resendCount > 5) {
                        $error = 'Too many codes requested. Try again in one hour.';
                    } else {
                        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $codeHash = password_hash($code, PASSWORD_DEFAULT);
                        try {
                            db()->beginTransaction();
                            if ($pending) {
                                $save = db()->prepare('UPDATE staff_password_reset_otps SET email = ?, code_hash = ?, attempts = 0, resend_count = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), last_sent_at = NOW(), created_at = IF(?, created_at, NOW()) WHERE user_id = ?');
                                $save->execute([$email, $codeHash, $resendCount, $sameHour ? 1 : 0, $user['id']]);
                            } else {
                                $save = db()->prepare('INSERT INTO staff_password_reset_otps (user_id, email, code_hash, expires_at, last_sent_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())');
                                $save->execute([$user['id'], $email, $codeHash]);
                            }
                            send_staff_password_reset_otp($email, (string)$user['full_name'], $code);
                            db()->commit();
                            $notice = 'If an active staff/admin account uses this email, a 6-digit code has been sent. Check your inbox; the code expires in 10 minutes.';
                        } catch (Throwable $exception) {
                            if (db()->inTransaction()) db()->rollBack();
                            error_log('Staff password reset email failed: ' . $exception->getMessage());
                            $error = 'Could not send the reset code. Please check the shop email configuration or try again later.';
                        }
                    }
                }
            } else {
                // Keep the response generic to avoid revealing account emails.
                $notice = 'If an active staff/admin account uses this email, a 6-digit code has been sent. Check your inbox; the code expires in 10 minutes.';
            }
        }
    } elseif (isset($_POST['verify_code'])) {
        $email = (string)($_SESSION['staff_reset_email'] ?? '');
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code) || $email === '') {
            $error = 'Enter the 6-digit code sent to your email.';
        } else {
            $query = db()->prepare('SELECT r.*, u.id AS account_id, (r.expires_at < NOW()) AS is_expired FROM staff_password_reset_otps r JOIN users u ON u.id = r.user_id WHERE LOWER(r.email) = ? AND u.is_active = 1 LIMIT 1');
            $query->execute([$email]);
            $reset = $query->fetch();
            if (!$reset) {
                $error = 'The code is invalid or expired. Request a new code.';
            } elseif ((int)$reset['attempts'] >= 5) {
                $error = 'Too many incorrect attempts. Request a new code.';
            } elseif ((int)$reset['is_expired'] === 1) {
                $error = 'The code expired. Request a new code.';
            } elseif (!password_verify($code, (string)$reset['code_hash'])) {
                db()->prepare('UPDATE staff_password_reset_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$reset['id']]);
                $error = 'The code is incorrect.';
            } else {
                // Consume the OTP as soon as it is verified so it cannot be replayed.
                db()->prepare('DELETE FROM staff_password_reset_otps WHERE id = ?')->execute([$reset['id']]);
                $_SESSION['staff_reset_verified_user_id'] = (int)$reset['account_id'];
                $_SESSION['staff_reset_verified_until'] = time() + 600;
                $verifiedUserId = (int)$reset['account_id'];
                $verifiedUntil = time() + 600;
                $notice = 'Email verified. Choose a new password below.';
            }
        }
    } elseif (isset($_POST['set_password'])) {
        $verifiedUserId = (int)($_SESSION['staff_reset_verified_user_id'] ?? 0);
        $verifiedUntil = (int)($_SESSION['staff_reset_verified_until'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($verifiedUserId < 1 || $verifiedUntil < time()) {
            unset($_SESSION['staff_reset_verified_user_id'], $_SESSION['staff_reset_verified_until']);
            $verifiedUserId = 0;
            $error = 'Verify a fresh email code before changing the password.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Use a password with at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The passwords do not match.';
        } else {
            db()->beginTransaction();
            try {
                $update = db()->prepare('UPDATE users SET password = ? WHERE id = ? AND is_active = 1');
                $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $verifiedUserId]);
                db()->prepare('DELETE FROM staff_password_reset_otps WHERE user_id = ?')->execute([$verifiedUserId]);
                db()->commit();
                unset($_SESSION['staff_reset_email'], $_SESSION['staff_reset_verified_user_id'], $_SESSION['staff_reset_verified_until']);
                flash_set('success', 'Password changed. Sign in with your new password.');
                redirect('staff-login.php');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                error_log('Staff password reset failed: ' . $exception->getMessage());
                $error = 'Could not update the password. Please try again.';
            }
        }
    }
}

$shopName = get_setting('shop_name', "Pia's Laundry Shop");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset staff password · <?= e($shopName) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=8">
<meta name="theme-color" content="#f5e8ff">
<style>
.reset-page .login-wrap{min-height:100vh;padding:30px 18px;background:#f5e8ff url('assets/img/login-lavender.svg') center/cover no-repeat;display:grid;place-items:center}
.reset-page .login-card{width:min(100%,430px);background:rgba(255,250,255,.95);border:1px solid #dfc5ef;border-top:4px solid #7b31a8;border-radius:18px;padding:30px;box-shadow:0 22px 65px rgba(61,24,82,.2)}
.reset-page h1{margin:0;text-align:center;color:#321a3d}.reset-page .intro{text-align:center;color:#78617f;margin:8px 0 24px}.reset-page label{display:block;margin:14px 0 5px;color:#4b3154;font-weight:600}.reset-page input{width:100%;padding:12px;border:1px solid #d9c5e2;border-radius:9px;background:#fffaff}.reset-page button{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;background:#58265f;color:white;font-weight:700;cursor:pointer}.reset-page .msg{padding:11px 13px;border-radius:9px;margin:12px 0;background:#fce8e8;color:#8b2424}.reset-page .notice{background:#eaf6ed;color:#205b2e}.reset-page a{color:#704478}
</style>
</head>
<body class="reset-page login-page">
<div class="login-wrap"><main class="login-card">
<h1><?= e($shopName) ?></h1><p class="intro">Staff &amp; admin password recovery</p>
<?php if ($error): ?><div class="msg" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="msg notice" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($verifiedUserId > 0 && $verifiedUntil >= time()): ?>
<form method="post" autocomplete="off">
<?= csrf_field() ?><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
<label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
<button type="submit" name="set_password" value="1">Reset password</button>
</form>
<?php elseif (!empty($_SESSION['staff_reset_email'])): ?>
<form method="post" autocomplete="off">
<?= csrf_field() ?><label for="code">6-digit email code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
<button type="submit" name="verify_code" value="1">Verify code</button>
</form>
<form method="post" autocomplete="off" style="margin-top:20px">
<?= csrf_field() ?><input type="hidden" name="email" value="<?= e($_SESSION['staff_reset_email']) ?>">
<button type="submit" name="request_code" value="1">Send another code</button>
</form>
<?php else: ?>
<form method="post" autocomplete="off">
<?= csrf_field() ?><label for="email">Registered staff/admin email</label><input id="email" name="email" type="email" maxlength="120" autocomplete="email" required>
<button type="submit" name="request_code" value="1">Send reset code</button>
</form>
<?php endif; ?>
<p class="intro" style="margin:20px 0 0"><a href="staff-login.php">Return to staff login</a></p>
</main></div>
</body>
</html>
