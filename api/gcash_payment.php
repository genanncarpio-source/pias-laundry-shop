<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$customer = api_current_customer();
$data = api_input();
$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
$reference = api_text($data, 'reference_number');
if ($orderId === false || $orderId < 1 || strlen($reference) < 6 || strlen($reference) > 120) {
    api_json(422, ['success' => false, 'error' => 'Enter a valid order and GCash reference number.']);
}
if (get_setting('gcash_account_name') === '' || get_setting('gcash_number') === '') {
    api_json(409, ['success' => false, 'error' => 'GCash payment is not configured by the shop yet.']);
}
$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id, order_number, status, total, amount_paid, change_due, payment_method FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE');
    $stmt->execute([$orderId, (int)$customer['id']]);
    $order = $stmt->fetch();
    if (!$order || $order['payment_method'] !== 'gcash_pending' || in_array($order['status'], ['cancelled', 'rejected', 'completed', 'picked_up'], true)) {
        throw new InvalidArgumentException('This order cannot accept another GCash payment submission.');
    }
    if ((float)$order['amount_paid'] > 0) throw new InvalidArgumentException('This order already has a recorded payment.');
    $latest = $pdo->prepare('SELECT status FROM gcash_payment_submissions WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $latest->execute([$orderId]);
    if ($latest->fetchColumn() !== 'rejected') throw new InvalidArgumentException('A new reference can be submitted only after the previous one is rejected.');
    $submittedAmount = filter_var($data['payment_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($submittedAmount === false || round((float)$submittedAmount, 2) !== round((float)$order['total'], 2)) {
        throw new InvalidArgumentException('The order total changed. Refresh the app and pay the updated total before resubmitting.');
    }
    $stmt = $pdo->prepare("INSERT INTO gcash_payment_submissions (order_id, amount, reference_number) VALUES (?, ?, ?)");
    $stmt->execute([$orderId, $order['total'], $reference]);
    $staffIds = $pdo->query('SELECT id FROM users WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staffIds as $staffId) {
        add_notification('staff', (int)$staffId, (int)$orderId, 'GCash payment needs verification', 'A new GCash reference was submitted for ' . $order['order_number'] . '.');
    }
    $pdo->commit();
    api_json(201, ['success' => true, 'message' => 'Your new GCash reference was submitted for shop verification.']);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') api_json(409, ['success' => false, 'error' => 'That GCash reference number has already been submitted.']);
    api_json(500, ['success' => false, 'error' => 'Could not submit the GCash reference. Please try again.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not submit the GCash reference. Please try again.']);
}