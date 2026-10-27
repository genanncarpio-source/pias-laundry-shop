<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$driver = api_current_driver();
$pdo = db();
$stmt = $pdo->query(
    "SELECT o.id, o.order_number, o.customer_name, o.pickup_address, o.delivery_address, o.delivery_latitude, o.delivery_longitude, o.expected_pickup, o.status, o.handoff_type, o.payment_method, o.total,
            o.assigned_driver_id, c.name AS account_name, c.phone AS customer_phone,
            dt.latitude AS driver_latitude, dt.longitude AS driver_longitude, dt.updated_at AS driver_updated_at, dt.stopped_at,
            dt.customer_location_shared, dt.customer_latitude, dt.customer_longitude, dt.customer_accuracy, dt.customer_location_updated_at
       FROM orders o
       LEFT JOIN customers c ON c.id = o.customer_id
       LEFT JOIN delivery_tracking dt ON dt.order_id = o.id
      WHERE o.order_type = 'delivery_request'
        AND ((o.handoff_type = 'pickup_delivery' AND o.status = 'confirmed') OR o.status = 'out_for_delivery')
      ORDER BY o.expected_pickup IS NULL, o.expected_pickup, o.id"
);
$deliveries = [];
foreach ($stmt->fetchAll() as $row) {
    $assignedToMe = (int)$row['assigned_driver_id'] === (int)$driver['id'];
    $customerLocation = null;
    if ($assignedToMe && (int)$row['customer_location_shared'] === 1 && $row['customer_latitude'] !== null && $row['customer_longitude'] !== null) {
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
    if ($assignedToMe && $row['driver_latitude'] !== null && $row['driver_longitude'] !== null && empty($row['stopped_at'])) {
        $driverLocation = ['latitude' => (float)$row['driver_latitude'], 'longitude' => (float)$row['driver_longitude'], 'updated_at' => $row['driver_updated_at']];
    }
    $deliveries[] = [
        'id' => (int)$row['id'],
        'ticket_number' => $row['order_number'],
        'assigned_to_me' => $assignedToMe,
        'customer_name' => $assignedToMe ? ($row['account_name'] ?: ($row['customer_name'] ?: 'Customer')) : null,
        'customer_phone' => $assignedToMe ? $row['customer_phone'] : null,
        'pickup_address' => $assignedToMe ? $row['pickup_address'] : null,
        'delivery_address' => $assignedToMe ? $row['delivery_address'] : null,
        'delivery_latitude' => $assignedToMe && $row['delivery_latitude'] !== null ? (float)$row['delivery_latitude'] : null,
        'delivery_longitude' => $assignedToMe && $row['delivery_longitude'] !== null ? (float)$row['delivery_longitude'] : null,
        'status' => $row['status'],
        'handoff_type' => $row['handoff_type'],
        'payment_method' => $assignedToMe ? $row['payment_method'] : null,
        'total' => $assignedToMe ? (float)$row['total'] : null,
        'expected_delivery' => $row['expected_pickup'],
        'customer_location' => $customerLocation,
        'driver_location' => $driverLocation,
    ];
}
$historyStmt = $pdo->query(
    "SELECT o.id, o.order_number, o.status, o.handoff_type, o.expected_pickup, o.updated_at,
            o.assigned_driver_id, o.total, o.payment_method, o.customer_name,
            c.name AS account_name, c.phone AS customer_phone, o.delivery_address
       FROM orders o
       LEFT JOIN customers c ON c.id = o.customer_id
      WHERE o.order_type = 'delivery_request'
        AND o.status NOT IN ('confirmed', 'out_for_delivery')
      ORDER BY o.updated_at DESC, o.id DESC LIMIT 200"
);
$history = [];
foreach ($historyStmt->fetchAll() as $row) {
    $assignedToMe = (int)$row['assigned_driver_id'] === (int)$driver['id'];
    $history[] = [
        'id' => (int)$row['id'],
        'ticket_number' => $row['order_number'],
        'status' => $row['status'],
        'handoff_type' => $row['handoff_type'],
        'expected_delivery' => $row['expected_pickup'],
        'updated_at' => $row['updated_at'],
        'assigned_to_me' => $assignedToMe,
        'customer_name' => $assignedToMe ? ($row['account_name'] ?: ($row['customer_name'] ?: 'Customer')) : null,
        'customer_phone' => $assignedToMe ? $row['customer_phone'] : null,
        'delivery_address' => $assignedToMe ? $row['delivery_address'] : null,
        'payment_method' => $assignedToMe ? $row['payment_method'] : null,
        'total' => $assignedToMe ? (float)$row['total'] : null,
    ];
}
api_json(200, ['success' => true, 'deliveries' => $deliveries, 'history' => $history]);