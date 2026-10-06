<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$customer = api_current_customer();
$data = api_input();
$items = $data['services'] ?? null;

if (!is_array($items) || count($items) < 1 || count($items) > 30) {
    api_json(422, ['success' => false, 'error' => 'Choose between 1 and 30 services.']);
}

$quantities = [];
foreach ($items as $item) {
    if (!is_array($item)) api_json(422, ['success' => false, 'error' => 'Each service must include a service ID and quantity.']);
    $serviceId = is_scalar($item['service_id'] ?? null) ? filter_var($item['service_id'], FILTER_VALIDATE_INT) : false;
    $quantity = is_scalar($item['quantity'] ?? null) ? filter_var($item['quantity'], FILTER_VALIDATE_FLOAT) : false;
    if (!$serviceId || $serviceId < 1 || $quantity === false || $quantity <= 0 || $quantity > 500) {
        api_json(422, ['success' => false, 'error' => 'Enter a valid service and quantity.']);
    }
    $quantities[$serviceId] = ($quantities[$serviceId] ?? 0) + (float)$quantity;
}

$ids = array_keys($quantities);
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("SELECT id, name, unit, price FROM services WHERE is_active = 1 AND id IN ($marks)");
$stmt->execute($ids);
$serviceMap = [];
foreach ($stmt->fetchAll() as $service) $serviceMap[(int)$service['id']] = $service;
if (count($serviceMap) !== count($ids)) api_json(422, ['success' => false, 'error' => 'One or more selected services are unavailable. Refresh the service list and try again.']);

$lines = [];
$subtotal = 0.0;
foreach ($quantities as $serviceId => $quantity) {
    $service = $serviceMap[$serviceId];
    if (in_array($service['unit'], ['piece', 'load'], true) && floor($quantity) !== $quantity) {
        api_json(422, ['success' => false, 'error' => 'Piece- and load-based services require whole-number quantities.']);
    }
    $lineTotal = round((float)$service['price'] * $quantity, 2);
    $subtotal += $lineTotal;
    $lines[] = [
        'service_id' => (int)$service['id'],
        'service_name' => $service['name'],
        'unit' => $service['unit'],
        'quantity' => $quantity,
        'unit_price' => (float)$service['price'],
        'subtotal' => $lineTotal,
    ];
}
$subtotal = round($subtotal, 2);
if ($subtotal > 99999999.99) api_json(422, ['success' => false, 'error' => 'The selected services exceed the maximum ticket amount.']);
$notes = api_text($data, 'notes');
if (strlen($notes) > 255) api_json(422, ['success' => false, 'error' => 'Notes must be 255 characters or fewer.']);

$expectedPickup = null;
if (!empty($data['expected_pickup'])) {
    if (!is_string($data['expected_pickup']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/', $data['expected_pickup'])) {
        api_json(422, ['success' => false, 'error' => 'Expected pickup must be a valid date and time.']);
    }
    try {
        $pickup = new DateTime((string)$data['expected_pickup']);
        if ($pickup < new DateTime('now')) api_json(422, ['success' => false, 'error' => 'Expected pickup must be in the future.']);
        $expectedPickup = $pickup->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        api_json(422, ['success' => false, 'error' => 'Expected pickup must be a valid date and time.']);
    }
}

$pdo = db();
$ticketNumber = 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        "INSERT INTO orders
            (order_number, customer_id, customer_name, order_type, status, subtotal, discount, total,
             amount_paid, change_due, payment_method, expected_pickup, notes, created_by)
         VALUES (?, ?, ?, 'drop_off', 'pending', ?, 0, ?, 0, 0, 'unpaid', ?, ?, NULL)"
    );
    $insert->execute([$ticketNumber, (int)$customer['id'], $customer['name'], $subtotal, $subtotal, $expectedPickup, $notes ?: null]);
    $ticketId = (int)$pdo->lastInsertId();
    $insertLine = $pdo->prepare('INSERT INTO order_items (order_id, service_id, service_name, unit, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($lines as $line) {
        $insertLine->execute([$ticketId, $line['service_id'], $line['service_name'], $line['unit'], $line['quantity'], $line['unit_price'], $line['subtotal']]);
    }
    $history = $pdo->prepare("INSERT INTO order_history (order_id, status, note, changed_by) VALUES (?, 'pending', 'Service request submitted through the mobile app', NULL)");
    $history->execute([$ticketId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not submit your service request. Please try again.']);
}

api_json(201, [
    'success' => true,
    'message' => 'Your laundry service request was submitted. The shop will confirm it.',
    'ticket' => ['id' => $ticketId, 'ticket_number' => $ticketNumber, 'status' => 'pending', 'estimated_total' => $subtotal],
]);
