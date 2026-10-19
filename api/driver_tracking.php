<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$driver = api_current_driver();
$data = api_input();
$action = api_text($data, 'action');
if ($action === 'stop') {
    $stmt = db()->prepare(
        "UPDATE delivery_tracking dt
         JOIN orders o ON o.id = dt.order_id
            SET dt.latitude = NULL, dt.longitude = NULL, dt.accuracy = NULL, dt.heading = NULL,
                dt.speed = NULL, dt.stopped_at = NOW()
          WHERE o.assigned_driver_id = ? AND o.order_type = 'delivery_request'
            AND o.status = 'out_for_delivery' AND dt.is_active = 1"
    );
    $stmt->execute([(int)$driver['id']]);
    api_json(200, ['success' => true, 'message' => 'Driver location sharing stopped.']);
}
if ($action !== 'update') api_json(422, ['success' => false, 'error' => 'Unsupported tracking action.']);
$latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false || !is_finite((float)$latitude) || !is_finite((float)$longitude)
    || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    api_json(422, ['success' => false, 'error' => 'The phone did not provide a valid location.']);
}
$optionalNumber = static function (string $key, float $minimum, float $maximum) use ($data): ?float {
    if (!isset($data[$key]) || !is_numeric($data[$key])) return null;
    $value = (float)$data[$key];
    return is_finite($value) && $value >= $minimum && $value <= $maximum ? $value : null;
};
$accuracy = $optionalNumber('accuracy', 0, 10000);
$heading = $optionalNumber('heading', 0, 360);
$speed = $optionalNumber('speed', 0, 500);
$stmt = db()->prepare(
    "UPDATE delivery_tracking dt
     JOIN orders o ON o.id = dt.order_id
        SET dt.latitude = ?, dt.longitude = ?, dt.accuracy = ?, dt.heading = ?, dt.speed = ?,
            dt.updated_at = NOW(), dt.stopped_at = NULL
      WHERE o.assigned_driver_id = ? AND o.order_type = 'delivery_request'
        AND o.status = 'out_for_delivery' AND dt.is_active = 1"
);
$stmt->execute([$latitude, $longitude, $accuracy, $heading, $speed, (int)$driver['id']]);
if ($stmt->rowCount() < 1) api_json(409, ['success' => false, 'error' => 'No active deliveries are assigned to this driver.']);
api_json(200, ['success' => true, 'message' => 'Location shared.', 'updated_at' => date('Y-m-d H:i:s')]);
