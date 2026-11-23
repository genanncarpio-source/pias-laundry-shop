<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$driver = api_current_driver();
$data = api_input();
$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
$amountInput = trim((string)($data['amount'] ?? ''));
$reference = trim(api_text($data, 'reference_number'));
if (!$orderId || $orderId < 1 || !preg_match('/^\d+(?:\.\d{1,2})?$/', $amountInput) || strlen($reference) < 6 || strlen($reference) > 120) {
    api_json(422, ['success' => false, 'error' => 'Choose a delivery, enter a valid amount, and provide the GCash reference number.']);
}
$amount = round((float)$amountInput, 2);
if (!is_finite($amount) || $amount <= 0 || $amount > 99999999.99) api_json(422, ['success' => false, 'error' => 'Enter a valid GCash amount.']);
$pdo = db();
$pdo->beginTransaction();
try {
    $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND assigned_driver_id = ? AND order_type = 'delivery_request' AND status IN ('ready_for_pickup','out_for_delivery') FOR UPDATE");
    $orderStmt->execute([(int)$orderId, (int)$driver['id']]);
    $order = $orderStmt->fetch();
    if (!$order) throw new InvalidArgumentException('Choose one of your active delivery orders.');
    $groupOrderWhere = !empty($order['booking_group_id']) ? 'booking_group_id = ?' : 'id = ?';
    $groupOrderKey = !empty($order['booking_group_id']) ? $order['booking_group_id'] : (int)$orderId;
    $groupTotal = (float)($order['booking_group_total'] ?? $order['total']);
    $duplicateStmt = $pdo->prepare("SELECT id FROM payments WHERE payment_method = 'gcash' AND reference_number = ? LIMIT 1");
    $duplicateStmt->execute([$reference]);
    if ($duplicateStmt->fetchColumn()) throw new InvalidArgumentException('That GCash reference has already been recorded.');
    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'paid'");
    $paidStmt->execute([(int)$orderId]);
    $paid = round((float)$paidStmt->fetchColumn(), 2);
    $balance = max(0, round($groupTotal - $paid, 2));
    if ($balance <= 0.009) throw new InvalidArgumentException('This delivery has no remaining balance.');
    if ($amount > $balance) throw new InvalidArgumentException('The GCash amount cannot be more than the remaining balance of ₱' . number_format($balance, 2) . '.');
    $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, reference_number, received_by, received_by_driver_id) VALUES (?, ?, 'gcash', ?, NULL, ?)")->execute([(int)$orderId, $amount, $reference, (int)$driver['id']]);
    $newPaid = round($paid + $amount, 2);
    $remainingToAllocate = $newPaid;
    $ticketsStmt = $pdo->prepare("SELECT id, total FROM orders WHERE $groupOrderWhere ORDER BY booking_group_primary DESC, id FOR UPDATE");
    $ticketsStmt->execute([$groupOrderKey]);
    $allocateStmt = $pdo->prepare('UPDATE orders SET amount_paid = ?, change_due = 0 WHERE id = ?');
    foreach ($ticketsStmt->fetchAll() as $ticket) {
        $allocated = min((float)$ticket['total'], $remainingToAllocate);
        $remainingToAllocate = max(0, $remainingToAllocate - $allocated);
        $allocateStmt->execute([$allocated, (int)$ticket['id']]);
    }
    add_order_history((int)$orderId, (string)$order['status'], 'Driver recorded a GCash payment (' . $reference . ')', null);
    if (!empty($order['customer_id'])) add_notification('customer', (int)$order['customer_id'], (int)$orderId, 'GCash payment recorded', 'Your driver recorded a GCash payment of ₱' . number_format($amount, 2) . ' for ' . $order['order_number'] . '.');
    $pdo->commit();
    api_json(201, ['success' => true, 'message' => 'GCash payment recorded for ' . $order['order_number'] . '.']);
} catch (InvalidArgumentException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(409, ['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not record this GCash payment.']);
}