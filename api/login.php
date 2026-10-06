<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$email = strtolower(api_text($data, 'email'));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
if ($email === '' || $password === '') api_json(422, ['success' => false, 'error' => 'Enter your email and password.']);

$stmt = db()->prepare('SELECT id, name, email, phone, password_hash FROM customers WHERE email = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$email]);
$customer = $stmt->fetch();
if (!$customer || empty($customer['password_hash']) || !password_verify($password, $customer['password_hash'])) {
    api_json(401, ['success' => false, 'error' => 'Email or password is incorrect.']);
}

$token = api_issue_customer_token((int)$customer['id']);
unset($customer['password_hash']);
api_json(200, ['success' => true, 'token' => $token, 'token_type' => 'Bearer', 'expires_in' => 2592000, 'customer' => $customer]);
