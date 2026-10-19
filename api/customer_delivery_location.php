<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$customer = api_current_customer();
$data = api_input();
$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
$action = api_text($data, 'action');
if (!$orderId || $orderId < 1) api_json(422, ['success' => false, 'error' => 'Enter a valid delivery ticket.']);
if (!in_array($action, ['share', 'revoke'], true)) api_json(422, ['success' => false, 'error' => 'Choose whether to share or stop sharing your location.']);
$stmt = db()->prepare(
    "SELECT o.status, o.order_type, dt.is_active
       FROM orders o
       LEFT JOIN delivery_tracking dt ON dt.order_id = o.id
      WHERE o.id = ? AND o.customer_id = ?
      LIMIT 1"
);
$stmt->execute([(int)$orderId, (int)$customer['id']]);
$order = $stmt->fetch();
if (!$order) api_json(404, ['success' => false, 'error' => 'Delivery ticket not found.']);
if ($order['order_type'] !== 'delivery_request' || $order['status'] !== 'out_for_delivery' || (int)($order['is_active'] ?? 0) !== 1) {
    api_json(409, ['success' => false, 'error' => 'Location sharing is available only while this delivery is out for delivery.']);
}
if ($action === 'revoke') {
    db()->prepare(
        'UPDATE delivery_tracking SET customer_location_shared = 0, customer_latitude = NULL,
         customer_longitude = NULL, customer_accuracy = NULL, customer_location_updated_at = NULL,
         customer_shared_at = NULL WHERE order_id = ?'
    )->execute([(int)$orderId]);
    api_json(200, ['success' => true, 'shared' => false]);
}
$latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false || !is_finite((float)$latitude) || !is_finite((float)$longitude)
    || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    api_json(422, ['success' => false, 'error' => 'The phone did not provide a valid location.']);
}
$accuracy = isset($data['accuracy']) && is_numeric($data['accuracy']) && (float)$data['accuracy'] >= 0 && (float)$data['accuracy'] <= 10000
    ? (float)$data['accuracy'] : null;
db()->prepare(
    'UPDATE delivery_tracking SET customer_location_shared = 1, customer_latitude = ?,
     customer_longitude = ?, customer_accuracy = ?, customer_location_updated_at = NOW(),
     customer_shared_at = COALESCE(customer_shared_at, NOW()) WHERE order_id = ?'
)->execute([$latitude, $longitude, $accuracy, (int)$orderId]);
api_json(200, ['success' => true, 'shared' => true, 'message' => 'Your current location was shared with the assigned delivery driver.']);
