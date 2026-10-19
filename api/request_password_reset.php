<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$email = strtolower(api_text($data, 'email'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
    api_json(422, ['success' => false, 'error' => 'Enter a valid email address.']);
}

$genericResponse = [
    'success' => true,
    'message' => 'If a verified account uses this email, a 6-digit reset code was sent. It expires in 10 minutes.',
];
$pdo = db();
$account = $pdo->prepare('SELECT c.id, c.name, a.email FROM customer_accounts a JOIN customers c ON c.id = a.customer_id WHERE LOWER(a.email) = ? AND a.email_verified_at IS NOT NULL AND c.is_active = 1 LIMIT 1');
$account->execute([$email]);
$customer = $account->fetch();
if (!$customer) api_json(202, $genericResponse);

$pending = $pdo->prepare('SELECT id, resend_count, TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_sent, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_since_created FROM customer_password_reset_otps WHERE customer_id = ? LIMIT 1');
$pending->execute([$customer['id']]);
$current = $pending->fetch();
if ($current && max(0, (int)$current['seconds_since_sent']) < 60) {
    api_json(429, ['success' => false, 'error' => 'Please wait 60 seconds before requesting another reset code.']);
}
$sameHour = $current && max(0, (int)$current['seconds_since_created']) < 3600;
$resendCount = $sameHour ? (int)$current['resend_count'] + 1 : 0;
if ($resendCount > 5) api_json(429, ['success' => false, 'error' => 'Too many reset codes requested. Try again in one hour.']);

$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
try {
    $pdo->beginTransaction();
    if ($current) {
        $update = $pdo->prepare('UPDATE customer_password_reset_otps SET email = ?, code_hash = ?, attempts = 0, resend_count = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), last_sent_at = NOW(), created_at = IF(?, created_at, NOW()) WHERE customer_id = ?');
        $update->execute([$email, $codeHash, $resendCount, $sameHour ? 1 : 0, $customer['id']]);
    } else {
        $insert = $pdo->prepare('INSERT INTO customer_password_reset_otps (customer_id, email, code_hash, expires_at, last_sent_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())');
        $insert->execute([$customer['id'], $email, $codeHash]);
    }
    api_send_password_reset_otp($email, (string)$customer['name'], $code);
    $pdo->commit();
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(503, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not prepare the password reset. Please try again.']);
}

api_json(202, $genericResponse);
