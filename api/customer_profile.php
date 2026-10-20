<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$customer = api_current_customer();
$data = api_input();
$address = api_text($data, 'address');
$latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

if (trim($address) === '' || strlen($address) > 255) api_json(422, ['success' => false, 'error' => 'Enter an address up to 255 characters.']);
if ($latitude === false || $longitude === false || !is_finite((float)$latitude) || !is_finite((float)$longitude) || (float)$latitude < -90 || (float)$latitude > 90 || (float)$longitude < -180 || (float)$longitude > 180) {
    api_json(422, ['success' => false, 'error' => 'Pin a valid location before saving.']);
}

$update = db()->prepare('UPDATE customers SET address = ?, location_latitude = ?, location_longitude = ? WHERE id = ?');
$update->execute([$address, $latitude, $longitude, (int)$customer['id']]);
$customer['address'] = $address;
$customer['location_latitude'] = (float)$latitude;
$customer['location_longitude'] = (float)$longitude;
api_json(200, ['success' => true, 'customer' => $customer, 'delivery_available' => api_is_delivery_area((float)$latitude, (float)$longitude)]);