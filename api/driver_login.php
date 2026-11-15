<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$data = api_input();
$identifier = strtolower(trim((string)($data['identifier'] ?? $data['username'] ?? '')));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
if ($identifier === '' || $password === '' || strlen($identifier) > 120) api_json(422, ['success' => false, 'error' => 'Enter your driver username or email and password.']);
$stmt = db()->prepare('SELECT id, full_name, username, email, phone, profile_photo, password_hash FROM delivery_drivers WHERE (LOWER(username) = ? OR LOWER(email) = ?) AND is_active = 1 LIMIT 1');
$stmt->execute([$identifier, $identifier]);
$driver = $stmt->fetch();
if (!$driver || !password_verify($password, $driver['password_hash'])) api_json(401, ['success' => false, 'error' => 'Driver username/email or password is incorrect.']);
$token = api_issue_driver_token((int)$driver['id']);
api_json(200, ['success' => true, 'token' => $token, 'driver' => ['id' => (int)$driver['id'], 'name' => $driver['full_name'], 'full_name' => $driver['full_name'], 'username' => $driver['username'], 'email' => $driver['email'], 'phone' => $driver['phone'], 'profile_photo' => $driver['profile_photo']]]);