<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$email = strtolower(api_text($data, 'email'));
$code = api_text($data, 'code');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
    api_json(422, ['success' => false, 'error' => 'Enter the 6-digit code sent to your email.']);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $pending = $pdo->prepare('SELECT *, (expires_at < NOW()) AS is_expired FROM customer_registration_otps WHERE email = ? LIMIT 1 FOR UPDATE');
    $pending->execute([$email]);
    $registration = $pending->fetch();
    if (!$registration) {
        $pdo->rollBack();
        api_json(404, ['success' => false, 'error' => 'No pending verification was found. Create your account again.']);
    }
    if ((int)$registration['attempts'] >= 5) {
        $pdo->rollBack();
        api_json(429, ['success' => false, 'error' => 'Too many incorrect attempts. Request a new verification code.']);
    }
    if ((int)$registration['is_expired'] === 1) {
        $pdo->rollBack();
        api_json(410, ['success' => false, 'error' => 'The verification code expired. Request a new code.']);
    }
    if (!password_verify($code, (string)$registration['code_hash'])) {
        $pdo->prepare('UPDATE customer_registration_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$registration['id']]);
        $pdo->commit();
        api_json(422, ['success' => false, 'error' => 'The verification code is incorrect.']);
    }

    $existing = $pdo->prepare('SELECT c.id FROM customers c LEFT JOIN customer_accounts a ON a.customer_id = c.id WHERE LOWER(c.email) = ? OR LOWER(a.email) = ? LIMIT 1');
    $existing->execute([$email, $email]);
    if ($existing->fetch()) {
        $pdo->prepare('DELETE FROM customer_registration_otps WHERE id = ?')->execute([$registration['id']]);
        $pdo->commit();
        api_json(409, ['success' => false, 'code' => 'already_registered', 'error' => 'Already have an account. Sign in instead.']);
    }

    $customer = $pdo->prepare('INSERT INTO customers (name, phone, email, address, location_latitude, location_longitude, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    $customer->execute([$registration['name'], $registration['phone'], $email, $registration['address'], $registration['latitude'], $registration['longitude']]);
    $customerId = (int)$pdo->lastInsertId();
    $account = $pdo->prepare('INSERT INTO customer_accounts (customer_id, email, password_hash, email_verified_at) VALUES (?, ?, ?, NOW())');
    $account->execute([$customerId, $email, $registration['password_hash']]);
    $pdo->prepare('DELETE FROM customer_registration_otps WHERE id = ?')->execute([$registration['id']]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') api_json(409, ['success' => false, 'code' => 'already_registered', 'error' => 'Already have an account. Sign in instead.']);
    api_json(500, ['success' => false, 'error' => 'Could not verify and create the account. Please try again.']);
}

api_json(201, ['success' => true, 'message' => 'Email verified and account created. Please sign in.']);
