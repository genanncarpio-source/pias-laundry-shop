<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$customer = api_current_customer();
$latitude = filter_var($customer['location_latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($customer['location_longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false) api_json(422, ['success' => false, 'error' => 'Pin your delivery location in your account profile first.']);
if (!api_is_delivery_area((float)$latitude, (float)$longitude)) api_json(422, ['success' => false, 'error' => api_delivery_area_error()]);
try {
    $quote = api_delivery_quote((float)$latitude, (float)$longitude);
} catch (RuntimeException $e) {
    api_json(503, ['success' => false, 'error' => $e->getMessage()]);
}
api_json(200, ['success' => true, 'quote' => $quote]);