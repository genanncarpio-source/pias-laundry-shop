<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$customer = api_current_customer();

$stmt = db()->prepare('SELECT id, order_number, status, subtotal, total, GREATEST(amount_paid - change_due, 0) AS amount_paid, GREATEST(total - GREATEST(amount_paid - change_due, 0), 0) AS balance_due, expected_pickup, notes, created_at FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 50');
$stmt->execute([(int)$customer['id']]);
$tickets = $stmt->fetchAll();
$itemsQuery = db()->prepare('SELECT service_name, unit, quantity, subtotal FROM order_items WHERE order_id = ? ORDER BY id');
foreach ($tickets as &$ticket) {
    $itemsQuery->execute([(int)$ticket['id']]);
    $ticket['services'] = $itemsQuery->fetchAll();
}
unset($ticket);
api_json(200, ['success' => true, 'tickets' => $tickets]);
