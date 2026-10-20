<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$name = api_text($data, 'name');
$email = strtolower(api_text($data, 'email'));
$phone = api_text($data, 'phone');
$address = api_text($data, 'address');
$latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$password = is_string($data['password'] ?? null) ? $data['password'] : '';

if ($name === '' || strlen($name) > 100) api_json(422, ['success' => false, 'error' => 'Enter your name (up to 100 characters).']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) api_json(422, ['success' => false, 'error' => 'Enter a valid email address.']);
if ($phone === '' || strlen($phone) > 30) api_json(422, ['success' => false, 'error' => 'Enter a phone number for service updates.']);
if (!api_is_complete_address($address) || strlen($address) > 255) api_json(422, ['success' => false, 'error' => 'Select a full address from the search results. You can add house, unit, or landmark details if needed.']);
if ($latitude === false || $longitude === false || !is_finite((float)$latitude) || !is_finite((float)$longitude) || (float)$latitude < -90 || (float)$latitude > 90 || (float)$longitude < -180 || (float)$longitude > 180) api_json(422, ['success' => false, 'error' => 'Pin a valid location to check delivery coverage.']);
if (strlen($password) < 10) api_json(422, ['success' => false, 'error' => 'Use a password with at least 10 characters.']);

$pdo = db();
$existing = $pdo->prepare('SELECT c.id FROM customers c LEFT JOIN customer_accounts a ON a.customer_id = c.id WHERE LOWER(c.email) = ? OR LOWER(a.email) = ? LIMIT 1');
$existing->execute([$email, $email]);
if ($existing->fetch()) api_json(409, ['success' => false, 'code' => 'already_registered', 'error' => 'Already have an account. Sign in instead.']);

$pending = $pdo->prepare('SELECT id, resend_count, TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_sent, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_since_created FROM customer_registration_otps WHERE email = ? LIMIT 1');
$pending->execute([$email]);
$current = $pending->fetch();
if ($current && max(0, (int)$current['seconds_since_sent']) < 60) {
    api_json(429, ['success' => false, 'error' => 'Please wait 60 seconds before requesting another code.']);
}
$sameHour = $current && max(0, (int)$current['seconds_since_created']) < 3600;
$resendCount = $sameHour ? (int)$current['resend_count'] + 1 : 0;
if ($resendCount > 5) api_json(429, ['success' => false, 'error' => 'Too many verification codes requested. Try again in one hour.']);

$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();
    if ($current) {
        $update = $pdo->prepare('UPDATE customer_registration_otps SET name = ?, phone = ?, address = ?, latitude = ?, longitude = ?, password_hash = ?, code_hash = ?, attempts = 0, resend_count = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), last_sent_at = NOW(), created_at = IF(?, created_at, NOW()) WHERE email = ?');
        $update->execute([$name, $phone, $address, $latitude, $longitude, $passwordHash, $codeHash, $resendCount, $sameHour ? 1 : 0, $email]);
    } else {
        $insert = $pdo->prepare('INSERT INTO customer_registration_otps (email, name, phone, address, latitude, longitude, password_hash, code_hash, expires_at, last_sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())');
        $insert->execute([$email, $name, $phone, $address, $latitude, $longitude, $passwordHash, $codeHash]);
    }
    api_send_registration_otp($email, $name, $code);
    $pdo->commit();
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(503, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not prepare email verification. Please try again.']);
}

api_json(202, [
    'success' => true,
    'requires_verification' => true,
    'email' => $email,
    'message' => 'A 6-digit verification code was sent to your email. It expires in 10 minutes.',
]);
