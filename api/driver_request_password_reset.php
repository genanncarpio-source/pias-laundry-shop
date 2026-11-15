<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/email.php';
api_require_method('POST');
$data = api_input();
$rawEmail = is_string($data['email'] ?? null) ? $data['email'] : '';
$email = strtolower(trim($rawEmail));
if (preg_match('/\s/u', $rawEmail) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) api_json(422, ['success' => false, 'error' => 'Enter the recovery email registered by shop staff.']);
$message = 'If an active driver account uses this email, a 6-digit reset code has been sent. The code expires in 10 minutes.';
$pdo = db();
$query = $pdo->prepare('SELECT id, full_name, email FROM delivery_drivers WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1');
$query->execute([$email]);
$driver = $query->fetch();
if (!$driver) api_json(200, ['success' => true, 'message' => $message]);
$pendingQuery = $pdo->prepare('SELECT resend_count, TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_sent, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_since_created FROM delivery_driver_password_reset_otps WHERE driver_id = ? LIMIT 1');
$pendingQuery->execute([(int)$driver['id']]);
$pending = $pendingQuery->fetch();
if ($pending && (int)$pending['seconds_since_sent'] < 60) api_json(200, ['success' => true, 'message' => $message]);
$sameHour = $pending && (int)$pending['seconds_since_created'] < 3600;
$resendCount = $sameHour ? (int)$pending['resend_count'] + 1 : 0;
if ($resendCount > 5) api_json(200, ['success' => true, 'message' => $message]);
$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
try {
    $pdo->beginTransaction();
    if ($pending) {
        $save = $pdo->prepare('UPDATE delivery_driver_password_reset_otps SET email = ?, code_hash = ?, attempts = 0, resend_count = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), last_sent_at = NOW(), created_at = IF(?, created_at, NOW()) WHERE driver_id = ?');
        $save->execute([$email, $codeHash, $resendCount, $sameHour ? 1 : 0, (int)$driver['id']]);
    } else {
        $save = $pdo->prepare('INSERT INTO delivery_driver_password_reset_otps (driver_id, email, code_hash, expires_at, last_sent_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())');
        $save->execute([(int)$driver['id'], $email, $codeHash]);
    }
    send_driver_password_reset_otp($email, (string)$driver['full_name'], $code);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Driver password reset email failed: ' . $exception->getMessage());
}
api_json(200, ['success' => true, 'message' => $message]);