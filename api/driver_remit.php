<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$driver = api_current_driver();
$pdo = db();
$pdo->beginTransaction();
try {
    $paymentStmt = $pdo->prepare("SELECT p.id, p.payment_method, p.amount
      FROM payments p
      WHERE p.received_by_driver_id = ?
        AND p.status = 'paid' AND p.payment_method IN ('cash', 'gcash') AND NOT EXISTS (SELECT 1 FROM driver_remittance_items ri WHERE ri.payment_id = p.id)
      ORDER BY p.id FOR UPDATE");
    $paymentStmt->execute([(int)$driver['id']]);
    $payments = $paymentStmt->fetchAll();
    if (!$payments) throw new InvalidArgumentException('There are no cash or GCash payments to remit.');
    $cash = 0.0;
    $gcash = 0.0;
    foreach ($payments as $payment) {
        if ($payment['payment_method'] === 'cash') $cash += (float)$payment['amount'];
        else $gcash += (float)$payment['amount'];
    }
    $cash = round($cash, 2);
    $gcash = round($gcash, 2);
    $pdo->prepare('INSERT INTO driver_remittances (driver_id, cash_total, gcash_total, payment_count) VALUES (?, ?, ?, ?)')
        ->execute([(int)$driver['id'], $cash, $gcash, count($payments)]);
    $remittanceId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO driver_remittance_items (remittance_id, payment_id) VALUES (?, ?)');
    foreach ($payments as $payment) $itemStmt->execute([$remittanceId, (int)$payment['id']]);
    $pdo->commit();
    api_json(201, ['success' => true, 'message' => 'Remittance recorded. Wallet totals are now cleared.', 'remittance' => ['id' => $remittanceId, 'cash_total' => $cash, 'gcash_total' => $gcash, 'payment_count' => count($payments)]]);
} catch (InvalidArgumentException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(409, ['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_json(500, ['success' => false, 'error' => 'Could not record this remittance. Try again.']);
}