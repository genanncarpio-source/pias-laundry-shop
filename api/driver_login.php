<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$data = api_input();
$username = strtolower(trim((string)($data['username'] ?? '')));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $username) || $password === '') {
    api_json(422, ['success' => false, 'error' => 'Enter your driver username and password.']);
}
$stmt = db()->prepare('SELECT id, full_name, username, phone, password_hash FROM delivery_drivers WHERE username = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$username]);
$driver = $stmt->fetch();
if (!$driver || !password_verify($password, $driver['password_hash'])) {
    api_json(401, ['success' => false, 'error' => 'Driver username or password is incorrect.']);
}
$token = api_issue_driver_token((int)$driver['id']);
api_json(200, [
    'success' => true,
    'token' => $token,
    'driver' => ['id' => (int)$driver['id'], 'name' => $driver['full_name'], 'username' => $driver['username']],
]);
