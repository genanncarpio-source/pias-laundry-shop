<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$driver = api_current_driver();
$stmt = db()->prepare(
    "SELECT o.id, o.order_number, o.customer_name, o.pickup_address, o.delivery_address, o.expected_pickup, o.status, o.handoff_type, o.payment_method, o.total,
            c.name AS account_name, c.phone AS customer_phone,
            dt.latitude AS driver_latitude, dt.longitude AS driver_longitude, dt.updated_at AS driver_updated_at, dt.stopped_at,
            dt.customer_location_shared, dt.customer_latitude, dt.customer_longitude, dt.customer_accuracy, dt.customer_location_updated_at
       FROM orders o
       LEFT JOIN customers c ON c.id = o.customer_id
       LEFT JOIN delivery_tracking dt ON dt.order_id = o.id
      WHERE o.assigned_driver_id = ? AND o.order_type = 'delivery_request'
        AND ((o.handoff_type = 'pickup_delivery' AND o.status = 'confirmed') OR o.status = 'out_for_delivery')
      ORDER BY o.expected_pickup IS NULL, o.expected_pickup, o.id"
);
$stmt->execute([(int)$driver['id']]);
$deliveries = [];
foreach ($stmt->fetchAll() as $row) {
    $customerLocation = null;
    if ((int)$row['customer_location_shared'] === 1 && $row['customer_latitude'] !== null && $row['customer_longitude'] !== null) {
        $updated = $row['customer_location_updated_at'] ? new DateTime($row['customer_location_updated_at']) : null;
        $customerLocation = [
            'latitude' => (float)$row['customer_latitude'],
            'longitude' => (float)$row['customer_longitude'],
            'accuracy' => $row['customer_accuracy'] === null ? null : (float)$row['customer_accuracy'],
            'updated_at' => $row['customer_location_updated_at'],
            'stale' => !$updated || $updated < new DateTime('-10 minutes'),
        ];
    }
    $driverLocation = null;
    if ($row['driver_latitude'] !== null && $row['driver_longitude'] !== null && empty($row['stopped_at'])) {
        $driverLocation = [
            'latitude' => (float)$row['driver_latitude'],
            'longitude' => (float)$row['driver_longitude'],
            'updated_at' => $row['driver_updated_at'],
        ];
    }
    $deliveries[] = [
        'id' => (int)$row['id'],
        'ticket_number' => $row['order_number'],
        'customer_name' => $row['account_name'] ?: ($row['customer_name'] ?: 'Customer'),
        'customer_phone' => $row['customer_phone'],
        'pickup_address' => $row['pickup_address'],
        'delivery_address' => $row['delivery_address'],
        'status' => $row['status'],
        'handoff_type' => $row['handoff_type'],
        'payment_method' => $row['payment_method'],
        'total' => (float)$row['total'],
        'expected_delivery' => $row['expected_pickup'],
        'customer_location' => $customerLocation,
        'driver_location' => $driverLocation,
    ];
}
api_json(200, ['success' => true, 'deliveries' => $deliveries]);
