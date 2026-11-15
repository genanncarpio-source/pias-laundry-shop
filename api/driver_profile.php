<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$driver = api_current_driver();
$data = api_input();
$fields = [];
$values = [];
if (array_key_exists('full_name', $data) || array_key_exists('name', $data)) {
    $name = api_text($data, array_key_exists('full_name', $data) ? 'full_name' : 'name');
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($name === '' || $length > 100) api_json(422, ['success' => false, 'error' => 'Enter your name (up to 100 characters).']);
    $fields[] = 'full_name = ?'; $values[] = $name;
}
if (array_key_exists('new_password', $data)) {
    $password = is_string($data['new_password']) ? $data['new_password'] : '';
    if (preg_match('/\s/u', $password) || strlen($password) < 10) api_json(422, ['success' => false, 'error' => 'Use a password with at least 10 characters and no spaces.']);
    $fields[] = 'password_hash = ?'; $values[] = password_hash($password, PASSWORD_DEFAULT);
}
if (array_key_exists('profile_photo', $data)) {
    $photo = $data['profile_photo'];
    if ($photo !== null && (!is_string($photo) || strlen($photo) > 7000000 || !preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $photo, $matches))) api_json(422, ['success' => false, 'error' => 'Choose a valid JPEG, PNG, or WebP picture up to 5 MB.']);
    if ($photo !== null) {
        $bytes = base64_decode($matches[2], true);
        $image = $bytes === false ? false : @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$image || !isset($types[$image[2]]) || $types[$image[2]] !== $matches[1] || strlen($bytes) > 5000000) api_json(422, ['success' => false, 'error' => 'The selected picture is invalid or too large.']);
    }
    $fields[] = 'profile_photo = ?'; $values[] = $photo;
}
if (!$fields) api_json(422, ['success' => false, 'error' => 'Choose a profile change to save.']);
$values[] = (int)$driver['id'];
db()->prepare('UPDATE delivery_drivers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
$read = db()->prepare('SELECT id, full_name, full_name AS name, username, email, phone, profile_photo FROM delivery_drivers WHERE id = ? LIMIT 1');
$read->execute([(int)$driver['id']]);
api_json(200, ['success' => true, 'driver' => $read->fetch()]);