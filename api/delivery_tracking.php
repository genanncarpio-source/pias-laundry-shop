<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$customer = api_current_customer();
$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) api_json(422, ['success' => false, 'error' => 'Enter a valid ticket.']);

$stmt = db()->prepare(
    'SELECT o.order_type, o.status, o.order_number, t.is_active, t.latitude, t.longitude,
            t.accuracy, t.heading, t.speed, t.updated_at, t.stopped_at,
            t.customer_location_shared, t.customer_location_updated_at
       FROM orders o
       LEFT JOIN delivery_tracking t ON t.order_id = o.id
      WHERE o.id = ? AND o.customer_id = ?
      LIMIT 1'
);
$stmt->execute([(int)$orderId, (int)$customer['id']]);
$row = $stmt->fetch();
if (!$row) api_json(404, ['success' => false, 'error' => 'Ticket not found.']);

$active = $row['order_type'] === 'delivery_request'
    && $row['status'] === 'out_for_delivery'
    && (int)($row['is_active'] ?? 0) === 1;
$location = null;
if ($active && $row['latitude'] !== null && $row['longitude'] !== null) {
    $updatedAt = $row['updated_at'] ? new DateTime($row['updated_at']) : null;
    $location = [
        'latitude' => (float)$row['latitude'],
        'longitude' => (float)$row['longitude'],
        'accuracy' => $row['accuracy'] === null ? null : (float)$row['accuracy'],
        'heading' => $row['heading'] === null ? null : (float)$row['heading'],
        'speed' => $row['speed'] === null ? null : (float)$row['speed'],
        'updated_at' => $row['updated_at'],
        'stale' => !$updatedAt || $updatedAt < new DateTime('-90 seconds'),
    ];
}
$customerLocationShared = $active && (int)($row['customer_location_shared'] ?? 0) === 1;
api_json(200, [
    'success' => true,
    'tracking' => [
        'active' => $active,
        'stopped' => !empty($row['stopped_at']),
        'ticket_number' => $row['order_number'],
        'location' => $location,
        'customer_location_shared' => $customerLocationShared,
        'customer_location_updated_at' => $customerLocationShared ? $row['customer_location_updated_at'] : null,
    ],
]);
