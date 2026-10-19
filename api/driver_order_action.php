<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$driver = api_current_driver();
$data = api_input();
$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
$action = api_text($data, 'action');
if (!$orderId || !in_array($action, ['collect', 'deliver'], true)) api_json(422, ['success' => false, 'error' => 'Choose a valid delivery action.']);
$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND assigned_driver_id = ? AND order_type = ? FOR UPDATE');
    $stmt->execute([$orderId, (int)$driver['id'], 'delivery_request']);
    $order = $stmt->fetch();
    if (!$order) throw new InvalidArgumentException('This order is not assigned to your account.');
    if ($action === 'collect') {
        if (($order['handoff_type'] ?? '') !== 'pickup_delivery' || $order['status'] !== 'confirmed') throw new InvalidArgumentException('This laundry is not ready for customer pickup.');
        $pdo->prepare("UPDATE orders SET status = 'picked_up_for_service' WHERE id = ?")->execute([$orderId]);
        if ($order['payment_method'] === 'cash_on_handoff' && (float)$order['amount_paid'] <= 0) {
            $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, reference_number, received_by) VALUES (?, ?, 'cash', ?, NULL)")
                ->execute([$orderId, (float)$order['total'], 'Collected by driver ' . $driver['full_name']]);
            $pdo->prepare('UPDATE orders SET amount_paid = total, change_due = 0 WHERE id = ?')->execute([$orderId]);
        }
        add_order_history($orderId, 'picked_up_for_service', 'Driver collected the laundry from the customer', null);
        if ($order['customer_id']) add_notification('customer', (int)$order['customer_id'], $orderId, 'Laundry picked up', 'Your driver collected ' . $order['order_number'] . ' and is bringing it to the shop.');
        $message = 'Laundry marked as picked up from the customer.';
    } else {
        if ($order['status'] !== 'out_for_delivery') throw new InvalidArgumentException('This laundry is not currently out for delivery.');
        $pdo->prepare("UPDATE orders SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->prepare('UPDATE delivery_tracking SET is_active = 0, stopped_at = NOW(), customer_location_shared = 0 WHERE order_id = ?')->execute([$orderId]);
        add_order_history($orderId, 'completed', 'Driver confirmed delivery to the customer', null);
        if ($order['customer_id']) add_notification('customer', (int)$order['customer_id'], $orderId, 'Laundry delivered', 'Your laundry order ' . $order['order_number'] . ' was delivered.');
        $message = 'Laundry marked as delivered.';
    }
    $pdo->commit();
    api_json(200, ['success' => true, 'message' => $message]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(409, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not update the delivery status.']);
}