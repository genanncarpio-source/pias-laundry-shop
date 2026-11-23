<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/mobile_booking.php';
require_once __DIR__ . '/../includes/service_pricing.php';
api_require_method('POST');
ensure_service_load_capacity_schema();
ensure_service_addons_schema();
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
$weights = [];
$addonQuantities = [];
foreach ($items as $item) {
    if (!is_array($item)) mobile_booking_json(422, ['success' => false, 'error' => 'Each service must include a service ID and quantity.']);
    $serviceId = is_scalar($item['service_id'] ?? null) ? filter_var($item['service_id'], FILTER_VALIDATE_INT) : false;
    $quantity = is_scalar($item['quantity'] ?? null) ? filter_var($item['quantity'], FILTER_VALIDATE_FLOAT) : false;
    if ($serviceId === false || $serviceId === 0 || $quantity === false || $quantity <= 0 || $quantity > 500) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Enter a valid service and quantity.']);
    }
    if (array_key_exists('parent_service_id', $item)) {
        if (array_key_exists('weight_kg', $item)) mobile_booking_json(422, ['success' => false, 'error' => 'Add-ons cannot have a laundry weight.']);
        $parentServiceId = is_scalar($item['parent_service_id']) ? filter_var($item['parent_service_id'], FILTER_VALIDATE_INT) : false;
        if ($parentServiceId === false || $parentServiceId < 1) mobile_booking_json(422, ['success' => false, 'error' => 'Choose the main service for each add-on.']);
        $addonQuantities[$serviceId][$parentServiceId] = ($addonQuantities[$serviceId][$parentServiceId] ?? 0) + (float)$quantity;
    } else {
        $quantities[$serviceId] = ($quantities[$serviceId] ?? 0) + (float)$quantity;
    }
    if (array_key_exists('weight_kg', $item)) {
        $weight = is_scalar($item['weight_kg']) ? filter_var($item['weight_kg'], FILTER_VALIDATE_FLOAT) : false;
        if ($weight === false || !is_finite((float)$weight) || round((float)$weight, 2) <= 0 || $weight > 15) {
            mobile_booking_json(422, ['success' => false, 'error' => 'Enter a laundry weight between 0.01 and 15 kg for each service.']);
        }
        $weights[$serviceId] = ($weights[$serviceId] ?? 0) + (float)$weight;
        if ($weights[$serviceId] > 15) mobile_booking_json(422, ['success' => false, 'error' => 'The maximum laundry weight per service is 15 kg.']);
    }
}

$ids = array_values(array_unique(array_merge(array_keys($quantities), array_keys($addonQuantities))));
$dbIds = array_values(array_filter($ids, static fn ($id) => (int)$id > 0));
$serviceMap = [];
if ($dbIds) {
    $marks = implode(',', array_fill(0, count($dbIds), '?'));
    $stmt = db()->prepare("SELECT id, name, category, unit, kg_per_load, price FROM services WHERE is_active = 1 AND id IN ($marks)");
    $stmt->execute($dbIds);
    foreach ($stmt->fetchAll() as $service) {
        $serviceMap[(int)$service['id']] = $service;
    }
}
// Older app builds still submit the historical negative add-on IDs.
$legacyAddons = [-1 => 'Fabcon (Downy)', -2 => 'Detergent (Surf)'];
foreach ($ids as $id) if ((int)$id < 0 && isset($legacyAddons[(int)$id])) $serviceMap[(int)$id] = ['id' => (int)$id, 'name' => $legacyAddons[(int)$id], 'category' => 'Add-ons', 'unit' => 'load', 'price' => 10.00];
if (count($serviceMap) !== count($ids)) mobile_booking_json(422, ['success' => false, 'error' => 'One or more selected services are unavailable. Refresh the service list and try again.']);

foreach ($quantities as $serviceId => $quantity) {
    $service = $serviceMap[$serviceId];
    if (stripos((string)$service['category'], 'add') === 0 || (int)$serviceId < 0) {
        if (array_key_exists($serviceId, $weights)) mobile_booking_json(422, ['success' => false, 'error' => 'Add-ons cannot have a laundry weight.']);
        $addonQuantities[$serviceId][0] = ($addonQuantities[$serviceId][0] ?? 0) + $quantity;
        unset($quantities[$serviceId]);
    }
}

$coreServices = [];
$coreLoadCounts = [];
foreach ($quantities as $serviceId => $quantity) {
    $service = $serviceMap[$serviceId];
    $category = strtolower(trim((string)($service['category'] ?? '')));
    $name = strtolower(trim((string)($service['name'] ?? '')));
    $isMainService = service_uses_weighted_load($service)
        || in_array($category, ['wash', 'dry', 'full service', 'wash & fold'], true)
        || in_array($name, ['wash', 'dry', 'full service'], true);
    if ($isMainService) {
        $coreServices[(int)$serviceId] = $service;
        if (array_key_exists($serviceId, $weights)) {
            $coreLoadCounts[(int)$serviceId] = service_weight_load_count((float)$weights[$serviceId], (string)$service['category'], $service['kg_per_load'] !== null ? (float)$service['kg_per_load'] : null);
        } elseif (strtolower((string)$service['unit']) === 'load') {
            $coreLoadCounts[(int)$serviceId] = (int)$quantity;
        } elseif (strtolower((string)$service['unit']) === 'kg') {
            $coreLoadCounts[(int)$serviceId] = service_weight_load_count((float)$quantity, (string)$service['category'], $service['kg_per_load'] !== null ? (float)$service['kg_per_load'] : null);
        }
    }
}
$hasAddons = count($addonQuantities) > 0;
if ($hasAddons && count($coreServices) < 1) mobile_booking_json(422, ['success' => false, 'error' => 'Choose a main laundry service before adding add-ons.']);
foreach ($addonQuantities as $addonId => $parents) {
    $addon = $serviceMap[$addonId];
    if (stripos((string)$addon['category'], 'add') !== 0 && (int)$addonId > 0) {
        mobile_booking_json(422, ['success' => false, 'error' => 'That service is not an add-on. Refresh the offers and try again.']);
    }
    foreach ($parents as $parentId => $addonQuantity) {
        $parentId = (int)$parentId;
        if ($parentId < 1) {
            if (count($coreServices) !== 1) mobile_booking_json(422, ['success' => false, 'error' => 'Choose the main service for each add-on.']);
            $parentId = (int)array_key_first($coreServices);
        }
        $coreService = $coreServices[$parentId] ?? null;
        if (!$coreService || ((int)$addonId > 0 && !in_array((int)$addonId, service_assigned_addon_ids($parentId), true))) {
            mobile_booking_json(422, ['success' => false, 'error' => 'That add-on is not available for the selected service. Refresh the offers and try again.']);
        }
        if (array_key_exists($parentId, $weights)) {
            $capacity = service_load_capacity((string)$coreService['category'], $coreService['kg_per_load'] !== null ? (float)$coreService['kg_per_load'] : null);
            if ((float)$weights[$parentId] + 0.0001 < $capacity) mobile_booking_json(422, ['success' => false, 'error' => 'Enter at least one full load of laundry before adding add-ons.']);
        }
        if (($coreLoadCounts[$parentId] ?? 0) < 1 || (float)$addonQuantity !== (float)$coreLoadCounts[$parentId]) {
            mobile_booking_json(422, ['success' => false, 'error' => 'Add-on quantities must match the number of loads for their main service. Refresh the estimate and try again.']);
        }
    }
}
$lines = [];
$subtotal = 0.0;
foreach ($quantities as $serviceId => $quantity) {
    $service = $serviceMap[$serviceId];
    $category = strtolower(trim((string)($service['category'] ?? '')));
    $name = strtolower(trim((string)($service['name'] ?? '')));
    $isMainService = service_uses_weighted_load($service)
        || in_array($category, ['wash', 'dry', 'full service', 'wash & fold'], true)
        || in_array($name, ['wash', 'dry', 'full service'], true);
    $hasEstimatedWeight = array_key_exists($serviceId, $weights);
    $isWeightLoadService = service_uses_weighted_load($service);
    if ($hasEstimatedWeight && !$isWeightLoadService) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Laundry weight can only be entered for services charged by the load.']);
    }
    if ($hasEstimatedWeight) $quantity = service_weight_load_count((float)$weights[$serviceId], (string)$service['category'], $service['kg_per_load'] !== null ? (float)$service['kg_per_load'] : null);
    if (in_array($service['unit'], ['piece', 'load'], true) && floor((float)$quantity) !== (float)$quantity) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Piece- and load-based services require whole-number quantities.']);
    }
    $lineTotal = $hasEstimatedWeight
        ? service_weight_load_price((float)$weights[$serviceId], (float)$service['price'], (string)$service['category'], $service['kg_per_load'] !== null ? (float)$service['kg_per_load'] : null)
        : round((float)$service['price'] * $quantity, 2);
    $subtotal += $lineTotal;
    $lines[] = [
        'service_id' => (int)$service['id'],
        'service_name' => $service['name'],
        'unit' => $service['unit'],
        'quantity' => $quantity,
        'unit_price' => (float)$service['price'],
        'subtotal' => $lineTotal,
        'estimated_weight_kg' => $hasEstimatedWeight ? (float)$weights[$serviceId] : null,
    ];
}

foreach ($addonQuantities as $addonId => $parents) {
    foreach ($parents as $parentId => $quantity) {
        $parentId = (int)$parentId > 0 ? (int)$parentId : (int)array_key_first($coreServices);
        $service = $serviceMap[$addonId];
        $lineTotal = round((float)$service['price'] * $quantity, 2);
        $subtotal += $lineTotal;
        $lines[] = [
            'service_id' => (int)$addonId > 0 ? (int)$addonId : null,
            'parent_service_id' => $parentId,
            'service_name' => $service['name'],
            'unit' => 'load',
            'quantity' => $quantity,
            'unit_price' => (float)$service['price'],
            'subtotal' => $lineTotal,
            'estimated_weight_kg' => null,
        ];
    }
}
$subtotal = round($subtotal, 2);
$serviceLines = [];
foreach ($lines as $line) {
    $ownerServiceId = $line['parent_service_id'] ?? $line['service_id'];
    if (!$ownerServiceId || (int)$ownerServiceId < 1) mobile_booking_json(422, ['success' => false, 'error' => 'Every add-on must belong to a main laundry service.']);
    $ownerService = $serviceMap[(int)$ownerServiceId] ?? null;
    $groupKey = 'service:' . (int)$ownerServiceId;
    $serviceLines[$groupKey]['name'] = (string)($ownerService['name'] ?? $line['service_name']);
    $serviceLines[$groupKey]['lines'][] = $line;
}
if (count($serviceLines) < 1) mobile_booking_json(422, ['success' => false, 'error' => 'Choose at least one main laundry service.']);
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
if (!api_is_delivery_area($customerLatitude, $customerLongitude)) mobile_booking_json(422, ['success' => false, 'error' => api_delivery_area_error()]);
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
        $manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $manilaPickup = (clone $pickup)->setTimezone(new DateTimeZone('Asia/Manila'));
        if ($manilaPickup <= $manilaNow) mobile_booking_json(422, ['success' => false, 'error' => 'The selected schedule is already past.']);
        if ($manilaPickup->format('Y-m') !== $manilaNow->format('Y-m')) mobile_booking_json(422, ['success' => false, 'error' => 'Choose a schedule before the end of this month.']);
        $allowedStarts = ['08:00', '10:00', '12:00', '14:00', '16:00'];
        if (!in_array($manilaPickup->format('H:i'), $allowedStarts, true) || $manilaPickup->format('s') !== '00') mobile_booking_json(422, ['success' => false, 'error' => 'Choose one of the available two-hour time slots.']);
        $expectedPickup = $manilaPickup->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        mobile_booking_json(422, ['success' => false, 'error' => 'Expected pickup must be a valid date and time.']);
    }
}

$pdo = db();
$bookingGroupId = bin2hex(random_bytes(16));
$ticketIds = [];
$ticketSummaries = [];
$primaryTicketId = 0;
if (!$pdo->inTransaction()) $pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        "INSERT INTO orders
            (order_number, booking_group_id, booking_group_primary, booking_group_total, customer_id, customer_name, pickup_address, delivery_address, delivery_latitude, delivery_longitude,
             order_type, handoff_type, status, subtotal, delivery_fee, discount, total, amount_paid, change_due, payment_method,
             expected_pickup, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 0, 0, ?, 0, 0, ?, ?, ?, NULL)"
    );
    $insertLine = $pdo->prepare('INSERT INTO order_items (order_id, service_id, service_name, unit, quantity, unit_price, subtotal, estimated_weight_kg) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $history = $pdo->prepare("INSERT INTO order_history (order_id, status, note, changed_by) VALUES (?, 'pending', ?, NULL)");
    $primary = true;
    foreach ($serviceLines as $serviceGroup) {
        $ticketLines = $serviceGroup['lines'];
        $ticketSubtotal = round(array_sum(array_column($ticketLines, 'subtotal')), 2);
        $ticketNumber = 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $ticketTotal = $ticketSubtotal;
        $insert->execute([$ticketNumber, $bookingGroupId, $primary ? 1 : 0, $grandTotal, (int)$customer['id'], $customer['name'], $pickupAddress, $deliveryAddressValue,
            $hasDelivery ? $customerLatitude : null, $hasDelivery ? $customerLongitude : null, $orderType, $fulfillment, $ticketSubtotal, $ticketTotal,
            $orderPaymentMethod, $expectedPickup, $notes ?: null]);
        $ticketId = (int)$pdo->lastInsertId();
        if ($primary) $primaryTicketId = $ticketId;
        $ticketIds[] = $ticketId;
        foreach ($ticketLines as $line) {
            $insertLine->execute([$ticketId, $line['service_id'], $line['service_name'], $line['unit'], $line['quantity'], $line['unit_price'], $line['subtotal'], $line['estimated_weight_kg']]);
        }
        $historyNote = $paymentMethod === 'gcash'
            ? 'Mobile app service ticket submitted in booking group ' . $bookingGroupId . '; shared GCash payment is awaiting shop verification'
            : 'Service ticket submitted through the mobile app in booking group ' . $bookingGroupId;
        $history->execute([$ticketId, $historyNote]);
        $ticketSummaries[] = ['id' => $ticketId, 'ticket_number' => $ticketNumber, 'service_name' => $serviceGroup['name'], 'status' => 'pending', 'estimated_total' => $ticketTotal];
        $primary = false;
    }
    if ($paymentMethod === 'gcash') {
        $pdo->prepare('INSERT INTO gcash_payment_submissions (order_id, amount, reference_number) VALUES (?, ?, ?)')->execute([$primaryTicketId, $grandTotal, $paymentReference]);
    }
    $staffIds = $pdo->query('SELECT id FROM users WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staffIds as $staffId) {
        $requestLabel = $fulfillment === 'pickup_delivery' ? 'pickup and delivery' : ($fulfillment === 'dropoff_delivery' ? 'drop-off and delivery' : 'drop-off and pickup');
        $title = $paymentMethod === 'gcash' ? 'GCash payment needs verification' : 'New ' . $requestLabel . ' request';
        $body = $paymentMethod === 'gcash'
            ? 'GCash reference ' . $paymentReference . ' was submitted for booking ' . $bookingGroupId . ' (' . money($grandTotal) . ').'
            : 'Mobile app ' . $requestLabel . ' booking ' . $bookingGroupId . ' from ' . $customer['name'] . ' (' . count($ticketIds) . ' service tickets).';
        add_notification('staff', (int)$staffId, $primaryTicketId, $title, $body);
    }
$response = [
    'success' => true,
    'message' => $paymentMethod === 'gcash' ? 'Your booking and GCash reference were submitted. The shop will verify the transfer before processing.' : 'Your laundry service request was submitted. The shop will confirm it.',
    'booking_group_id' => $bookingGroupId,
    'tickets' => $ticketSummaries,
    'ticket' => ['id' => $primaryTicketId, 'ticket_number' => $ticketSummaries[0]['ticket_number'], 'status' => 'pending', 'estimated_total' => $grandTotal, 'service_subtotal' => $subtotal, 'delivery_fee' => $deliveryFee, 'delivery_distance_km' => 0, 'payment_status' => $paymentMethod === 'gcash' ? 'pending_verification' : 'pay_during_handoff'],
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
