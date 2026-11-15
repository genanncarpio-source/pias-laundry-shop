<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$data = api_input();
$email = strtolower(api_text($data, 'email'));
$code = api_text($data, 'code');
$password = is_string($data['new_password'] ?? null) ? $data['new_password'] : '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) api_json(422, ['success' => false, 'error' => 'Enter the recovery email and 6-digit reset code.']);
if (preg_match('/\s/u', $password) || strlen($password) < 10) api_json(422, ['success' => false, 'error' => 'Use a password with at least 10 characters and no spaces.']);
$pdo = db();
$query = $pdo->prepare('SELECT r.*, d.id AS account_id FROM delivery_driver_password_reset_otps r JOIN delivery_drivers d ON d.id = r.driver_id WHERE LOWER(r.email) = ? AND d.is_active = 1 LIMIT 1');
$query->execute([$email]);
$reset = $query->fetch();
if (!$reset) api_json(422, ['success' => false, 'error' => 'The reset code is invalid or expired. Request a new code.']);
if ((int)$reset['attempts'] >= 5) api_json(429, ['success' => false, 'error' => 'Too many incorrect attempts. Request a new code.']);
if (new DateTime($reset['expires_at']) < new DateTime()) api_json(422, ['success' => false, 'error' => 'The reset code expired. Request a new code.']);
if (!password_verify($code, (string)$reset['code_hash'])) {
    $pdo->prepare('UPDATE delivery_driver_password_reset_otps SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$reset['id']]);
    api_json(422, ['success' => false, 'error' => 'The reset code is incorrect.']);
}
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE delivery_drivers SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['account_id']]);
    $pdo->prepare('DELETE FROM delivery_driver_password_reset_otps WHERE id = ?')->execute([(int)$reset['id']]);
    $pdo->prepare('DELETE FROM delivery_driver_sessions WHERE driver_id = ?')->execute([(int)$reset['account_id']]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not reset your password. Try again.']);
}
api_json(200, ['success' => true, 'message' => 'Password changed. Sign in using your new password.']);