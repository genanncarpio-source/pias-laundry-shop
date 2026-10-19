<?php
require __DIR__ . '/request_registration.php';
exit;

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
if ($address === '' || strlen($address) > 255) api_json(422, ['success' => false, 'error' => 'Enter your pickup address (up to 255 characters).']);
if ($latitude === false || $longitude === false || !is_finite((float)$latitude) || !is_finite((float)$longitude) || (float)$latitude < -90 || (float)$latitude > 90 || (float)$longitude < -180 || (float)$longitude > 180) api_json(422, ['success' => false, 'error' => 'Pin a valid location to check delivery coverage.']);
if (strlen($password) < 10) api_json(422, ['success' => false, 'error' => 'Use a password with at least 10 characters.']);

$pdo = db();
$check = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
$check->execute([$email]);
if ($check->fetch()) api_json(409, ['success' => false, 'error' => 'This email is already registered. Sign in or contact the shop for help.']);

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO customers (name, phone, email, address, location_latitude, location_longitude, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    $insert->execute([$name, $phone, $email, $address, $latitude, $longitude]);
    $customerId = (int)$pdo->lastInsertId();
    $account = $pdo->prepare('INSERT INTO customer_accounts (customer_id, email, password_hash) VALUES (?, ?, ?)');
    $account->execute([$customerId, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $token = api_issue_customer_token($customerId);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') api_json(409, ['success' => false, 'error' => 'This email is already registered. Sign in or contact the shop for help.']);
    api_json(500, ['success' => false, 'error' => 'Could not create the customer account. Please try again.']);
}

api_json(201, [
    'success' => true,
    'token' => $token,
    'token_type' => 'Bearer',
    'expires_in' => 2592000,
    'customer' => ['id' => $customerId, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'location_latitude' => (float)$latitude, 'location_longitude' => (float)$longitude],
]);
