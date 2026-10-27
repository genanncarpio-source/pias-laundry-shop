<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/mobile_booking.php';
api_require_method('POST');
$customer = api_current_customer();
$data = api_input();

$pdo = db();
try {
    $requestId = mobile_booking_begin($pdo, (int)$customer['id'], $data);
} catch (Throwable $e) {
    mobile_booking_json(503, ['success' => false, 'error' => 'The shop cannot safely accept bookings right now. Please retry later.']);
}
$items = $data['services'] ?? null;

if (!is_array($items) || count($items) < 1 || count($items) > 30) {
    mobile_booking_json(422, ['success' => false, 'error' => 'Choose between 1 and 30 services.']);
}

$quantities = [];
foreach ($items as $item) {
    if (!is_array($item)) mobile_booking_json(422, ['success' => false, 'error' => 'Each service must include a service ID and quantity.']);
    $serviceId = is_scalar($item['service_id'] ?? null) ? filter_var($item['service_id'], FILTER_VALIDATE_INT) : false;
    $quantity = is_scalar($item['quantity'] ?? null) ? filter_var($item['quantity'], FILTER_VALIDATE_FLOAT) : false;
    if (!$serviceId || $serviceId < 1 || $quantity === false || $quantity <= 0 || $quantity > 500) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Enter a valid service and quantity.']);
    }
    $quantities[$serviceId] = ($quantities[$serviceId] ?? 0) + (float)$quantity;
}

$ids = array_keys($quantities);
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("SELECT id, name, category, unit, price FROM services WHERE is_active = 1 AND id IN ($marks)");
$stmt->execute($ids);
$serviceMap = [];
foreach ($stmt->fetchAll() as $service) $serviceMap[(int)$service['id']] = $service;
if (count($serviceMap) !== count($ids)) mobile_booking_json(422, ['success' => false, 'error' => 'One or more selected services are unavailable. Refresh the service list and try again.']);

$coreServices = [];
foreach ($quantities as $serviceId => $quantity) {
    $service = $serviceMap[$serviceId];
    $category = strtolower(trim((string)($service['category'] ?? '')));
    $name = strtolower(trim((string)($service['name'] ?? '')));
    $isMainService = in_array($category, ['wash', 'dry', 'full service', 'wash & fold'], true)
        || in_array($name, ['wash', 'dry', 'full service'], true);
    if ($isMainService) $coreServices[] = $service['name'];
}
if (count($coreServices) > 1) {
    mobile_booking_json(422, ['success' => false, 'error' => 'Choose only one main laundry service: Wash, Dry, or Full Service. Add-ons may still be included.']);
}

$lines = [];
$subtotal = 0.0;
foreach ($quantities as $serviceId => $quantity) {
    $service = $serviceMap[$serviceId];
    if (in_array($service['unit'], ['piece', 'load'], true) && floor($quantity) !== $quantity) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Piece- and load-based services require whole-number quantities.']);
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
if ($subtotal > 99999999.99) mobile_booking_json(422, ['success' => false, 'error' => 'The selected services exceed the maximum ticket amount.']);

if (array_key_exists('quoted_total', $data)) {
    $quote = is_scalar($data['quoted_total']) ? filter_var($data['quoted_total'], FILTER_VALIDATE_FLOAT) : false;
    if ($quote === false || !is_finite((float)$quote) || $quote < 0 || (int)round($quote * 100) !== (int)round($subtotal * 100)) {
        mobile_booking_json(409, ['success' => false, 'code' => 'price_changed',
            'error' => 'Service prices changed since this booking was saved. Review the current prices and book again.']);
    }
}
$paymentMethod = api_text($data, 'payment_method') ?: 'cash_on_handoff';
$paymentReference = api_text($data, 'payment_reference');
if (!in_array($paymentMethod, ['cash_on_handoff', 'gcash'], true)) mobile_booking_json(422, ['success' => false, 'error' => 'Choose payment during handoff or GCash.']);
if ($paymentMethod === 'gcash') {
    if (trim(get_setting('gcash_account_name')) === '' || trim(get_setting('gcash_number')) === '') mobile_booking_json(409, ['success' => false, 'error' => 'The shop has not set up GCash payments yet. Choose payment during handoff or contact the shop.']);
    if (strlen($paymentReference) < 6 || strlen($paymentReference) > 120) mobile_booking_json(422, ['success' => false, 'error' => 'Enter the GCash reference number from your completed transfer.']);
    $reportedAmount = filter_var($data['payment_amount'] ?? null, FILTER_VALIDATE_FLOAT);

}
$orderPaymentMethod = $paymentMethod === 'gcash' ? 'gcash_pending' : 'cash_on_handoff';
$notes = api_text($data, 'notes');
if (strlen($notes) > 255) mobile_booking_json(422, ['success' => false, 'error' => 'Notes must be 255 characters or fewer.']);
$fulfillment = api_text($data, 'fulfillment_type') ?: 'pickup_delivery';
if (!in_array($fulfillment, ['pickup_delivery', 'dropoff_delivery', 'dropoff_pickup'], true)) mobile_booking_json(422, ['success' => false, 'error' => 'Choose a valid service handoff option.']);
$customerLatitude = isset($customer['location_latitude']) ? (float)$customer['location_latitude'] : 0.0;
$customerLongitude = isset($customer['location_longitude']) ? (float)$customer['location_longitude'] : 0.0;
$hasDelivery = in_array($fulfillment, ['pickup_delivery', 'dropoff_delivery'], true);
if ($hasDelivery && ($customerLatitude < -90 || $customerLatitude > 90 || $customerLongitude < -180 || $customerLongitude > 180 || ($customerLatitude === 0.0 && $customerLongitude === 0.0))) {
    mobile_booking_json(422, ['success' => false, 'error' => 'Pin your delivery location in your account profile first.']);
}
if ($hasDelivery && !api_is_delivery_area($customerLatitude, $customerLongitude)) {
    mobile_booking_json(422, ['success' => false, 'error' => api_delivery_area_error()]);
}
$deliveryFee = 0.0;
$grandTotal = round($subtotal + $deliveryFee, 2);
if ($paymentMethod === 'gcash') {
    if ($reportedAmount === false || round((float)$reportedAmount, 2) !== $grandTotal) mobile_booking_json(422, ['success' => false, 'error' => 'The payment amount must match the service subtotal and delivery fee.']);
}
$deliveryAddress = $hasDelivery ? trim((string)$customer['address']) : api_text($data, 'delivery_address');
if ($hasDelivery && (trim($deliveryAddress) === '' || strlen($deliveryAddress) > 255)) {
    mobile_booking_json(422, ['success' => false, 'error' => 'Add an address to your profile before booking delivery.']);
}
$orderType = $fulfillment === 'dropoff_pickup' ? 'drop_off' : 'delivery_request';
$pickupAddress = $fulfillment === 'pickup_delivery' ? ($customer['address'] ?: $deliveryAddress) : null;
$deliveryAddressValue = $fulfillment === 'dropoff_pickup' ? null : $deliveryAddress;
$expectedPickup = null;
if (!empty($data['expected_pickup'])) {
    if (!is_string($data['expected_pickup']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/', $data['expected_pickup'])) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Expected pickup must be a valid date and time.']);
    }
    try {
        $pickup = new DateTime((string)$data['expected_pickup']);
        if ($pickup < new DateTime('now')) mobile_booking_json(422, ['success' => false, 'error' => 'The selected schedule is already past.']);
        $manilaPickup = (clone $pickup)->setTimezone(new DateTimeZone('Asia/Manila'));
        $hour = (int)$manilaPickup->format('G');
        if ($hour < 8 || $hour >= 18) mobile_booking_json(422, ['success' => false, 'error' => 'Choose a schedule between 8:00 AM and 6:00 PM.']);
        $expectedPickup = $pickup->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Expected pickup must be a valid date and time.']);
    }
}

$pdo = db();
$ticketNumber = 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
if (!$pdo->inTransaction()) $pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        "INSERT INTO orders
            (order_number, customer_id, customer_name, pickup_address, delivery_address, delivery_latitude, delivery_longitude,
             order_type, handoff_type, status, subtotal, delivery_fee, discount, total, amount_paid, change_due, payment_method,
             expected_pickup, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, 0, ?, 0, 0, ?, ?, ?, NULL)"
    );
    $insert->execute([$ticketNumber, (int)$customer['id'], $customer['name'], $pickupAddress, $deliveryAddressValue,
        $hasDelivery ? $customerLatitude : null, $hasDelivery ? $customerLongitude : null,
        $orderType, $fulfillment, $subtotal, $deliveryFee, $grandTotal, $orderPaymentMethod, $expectedPickup, $notes ?: null]);
    $ticketId = (int)$pdo->lastInsertId();
    if ($paymentMethod === 'gcash') {
        $pdo->prepare('INSERT INTO gcash_payment_submissions (order_id, amount, reference_number) VALUES (?, ?, ?)')->execute([$ticketId, $grandTotal, $paymentReference]);
    }
    $insertLine = $pdo->prepare('INSERT INTO order_items (order_id, service_id, service_name, unit, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($lines as $line) {
        $insertLine->execute([$ticketId, $line['service_id'], $line['service_name'], $line['unit'], $line['quantity'], $line['unit_price'], $line['subtotal']]);
    }
    $historyNote = $paymentMethod === 'gcash'
        ? 'Mobile app request submitted; GCash payment is awaiting shop verification'
        : 'Service request submitted through the mobile app';
    $history = $pdo->prepare("INSERT INTO order_history (order_id, status, note, changed_by) VALUES (?, 'pending', ?, NULL)");
    $history->execute([$ticketId, $historyNote]);
    $staffIds = $pdo->query('SELECT id FROM users WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staffIds as $staffId) {
        $requestLabel = $fulfillment === 'pickup_delivery' ? 'pickup and delivery' : ($fulfillment === 'dropoff_delivery' ? 'drop-off and delivery' : 'drop-off and pickup');
        if ($paymentMethod === 'gcash') {
            add_notification('staff', (int)$staffId, $ticketId, 'GCash payment needs verification', 'GCash reference ' . $paymentReference . ' was submitted for ' . $ticketNumber . ' (' . money($grandTotal) . ').');
        } else {
            add_notification('staff', (int)$staffId, $ticketId, 'New ' . $requestLabel . ' request', 'Mobile app ' . $requestLabel . ' request ' . $ticketNumber . ' from ' . $customer['name'] . '.');
        }
    }
$response = [
    'success' => true,
    'message' => $paymentMethod === 'gcash' ? 'Your booking and GCash reference were submitted. The shop will verify the transfer before processing.' : 'Your laundry service request was submitted. The shop will confirm it.',
    'ticket' => ['id' => $ticketId, 'ticket_number' => $ticketNumber, 'status' => 'pending', 'estimated_total' => $grandTotal, 'service_subtotal' => $subtotal, 'delivery_fee' => $deliveryFee, 'delivery_distance_km' => 0, 'payment_status' => $paymentMethod === 'gcash' ? 'pending_verification' : 'pay_during_handoff'],
];

    if ($requestId !== null) {
        $pdo->prepare('UPDATE mobile_booking_requests SET response_json = ? WHERE customer_id = ? AND client_request_id = ?')
            ->execute([json_encode($response, JSON_THROW_ON_ERROR), (int)$customer['id'], $requestId]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') mobile_booking_json(409, ['success' => false, 'error' => 'That GCash reference number has already been submitted. Check the reference or contact the shop.']);
    mobile_booking_json(500, ['success' => false, 'error' => 'Could not submit your service request. Please try again.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mobile_booking_json(500, ['success' => false, 'error' => 'Could not submit your service request. Please try again.']);
}


mobile_booking_json(201, $response);
