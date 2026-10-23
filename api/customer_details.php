<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$customer = api_current_customer();
$data = api_input();
$fields = [];
$values = [];

if (array_key_exists('name', $data)) {
    $name = api_text($data, 'name');
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($name === '' || $nameLength > 100) api_json(422, ['success' => false, 'error' => 'Enter your full name (up to 100 characters).']);
    $fields[] = 'name = ?';
    $values[] = $name;
}

if (array_key_exists('phone', $data)) {
    $phone = api_text($data, 'phone');
    if (!preg_match('/^09\d{9}$/', $phone)) api_json(422, ['success' => false, 'error' => 'Enter an 11-digit mobile number starting with 09.']);
    $fields[] = 'phone = ?';
    $values[] = $phone;
}

if (array_key_exists('profile_photo', $data)) {
    $photo = $data['profile_photo'];
    if (!is_string($photo) || strlen($photo) > 7000000 || !preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $photo, $matches)) {
        api_json(422, ['success' => false, 'error' => 'Choose a valid JPEG, PNG, or WebP profile picture up to 5 MB.']);
    }
    $bytes = base64_decode($matches[2], true);
    if ($bytes === false || strlen($bytes) > 5000000) api_json(422, ['success' => false, 'error' => 'Profile pictures must be 5 MB or smaller.']);
    $image = @getimagesizefromstring($bytes);
    $allowedTypes = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    if (!$image || !isset($allowedTypes[$image[2]]) || $allowedTypes[$image[2]] !== 'image/' . $matches[1]) {
        api_json(422, ['success' => false, 'error' => 'The selected file is not a supported image.']);
    }
    $fields[] = 'profile_photo = ?';
    $values[] = $photo;
}

if (!$fields) api_json(422, ['success' => false, 'error' => 'There are no profile changes to save.']);
$values[] = (int)$customer['id'];
$update = db()->prepare('UPDATE customers SET ' . implode(', ', $fields) . ' WHERE id = ?');
$update->execute($values);

$read = db()->prepare('SELECT id, name, email, phone, address, location_latitude, location_longitude, profile_photo FROM customers WHERE id = ? LIMIT 1');
$read->execute([(int)$customer['id']]);
$updatedCustomer = $read->fetch();
if (!$updatedCustomer) api_json(404, ['success' => false, 'error' => 'Customer account not found.']);
api_json(200, ['success' => true, 'customer' => $updatedCustomer]);