<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$email = strtolower(api_text($data, 'email'));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
if ($email === '' || $password === '') api_json(422, ['success' => false, 'error' => 'Enter your email and password.']);

$stmt = db()->prepare(
    'SELECT c.id, c.name, c.email, c.phone, c.address, c.location_latitude, c.location_longitude, a.password_hash, a.email_verified_at
       FROM customer_accounts a
       JOIN customers c ON c.id = a.customer_id
      WHERE a.email = ? AND c.is_active = 1
      LIMIT 1'
);
$stmt->execute([$email]);
$customer = $stmt->fetch();
if (!$customer || empty($customer['password_hash']) || !password_verify($password, $customer['password_hash'])) {
    api_json(401, ['success' => false, 'error' => 'Email or password is incorrect.']);
}
if (empty($customer['email_verified_at'])) {
    api_json(403, ['success' => false, 'code' => 'email_not_verified', 'error' => 'Verify your email before signing in.']);
}

$token = api_issue_customer_token((int)$customer['id']);
unset($customer['password_hash']);
unset($customer['email_verified_at']);
api_json(200, ['success' => true, 'token' => $token, 'token_type' => 'Bearer', 'expires_in' => 2592000, 'customer' => $customer]);
