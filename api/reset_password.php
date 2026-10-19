<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$email = strtolower(api_text($data, 'email'));
$code = api_text($data, 'code');
$newPassword = is_string($data['new_password'] ?? null) ? $data['new_password'] : '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
    api_json(422, ['success' => false, 'error' => 'Enter your email and the 6-digit reset code.']);
}
if (strlen($newPassword) < 10) api_json(422, ['success' => false, 'error' => 'Use a new password with at least 10 characters.']);

$pdo = db();
try {
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT r.*, a.id AS account_id, (r.expires_at < NOW()) AS is_expired FROM customer_password_reset_otps r JOIN customer_accounts a ON a.customer_id = r.customer_id WHERE LOWER(r.email) = ? AND a.email_verified_at IS NOT NULL LIMIT 1 FOR UPDATE');
    $query->execute([$email]);
    $reset = $query->fetch();
    if (!$reset) {
        $pdo->rollBack();
        api_json(422, ['success' => false, 'error' => 'The reset code is invalid or no longer available.']);
    }
    if ((int)$reset['attempts'] >= 5) {
        $pdo->rollBack();
        api_json(429, ['success' => false, 'error' => 'Too many incorrect attempts. Request a new reset code.']);
    }
    if ((int)$reset['is_expired'] === 1) {
        $pdo->rollBack();
        api_json(410, ['success' => false, 'error' => 'The reset code expired. Request a new code.']);
    }
    if (!password_verify($code, (string)$reset['code_hash'])) {
        $pdo->prepare('UPDATE customer_password_reset_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$reset['id']]);
        $pdo->commit();
        api_json(422, ['success' => false, 'error' => 'The reset code is incorrect.']);
    }

    $pdo->prepare('UPDATE customer_accounts SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $reset['account_id']]);
    $pdo->prepare('DELETE FROM customer_sessions WHERE customer_id = ?')->execute([$reset['customer_id']]);
    $pdo->prepare('DELETE FROM customer_password_reset_otps WHERE id = ?')->execute([$reset['id']]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not reset the password. Please try again.']);
}

api_json(200, ['success' => true, 'message' => 'Password changed successfully. Sign in with your new password.']);
