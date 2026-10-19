<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$customer = api_current_customer();

$stmt = db()->prepare('SELECT o.id, o.order_number, o.order_type, o.handoff_type, o.pickup_address, o.delivery_address, o.status, o.subtotal, o.total, o.payment_method,
    GREATEST(o.amount_paid - o.change_due, 0) AS amount_paid,
    GREATEST(o.total - GREATEST(o.amount_paid - o.change_due, 0), 0) AS balance_due,
    o.expected_pickup, o.notes, o.created_at,
    (SELECT g.status FROM gcash_payment_submissions g WHERE g.order_id = o.id ORDER BY g.id DESC LIMIT 1) AS gcash_payment_status,
    (SELECT g.reference_number FROM gcash_payment_submissions g WHERE g.order_id = o.id ORDER BY g.id DESC LIMIT 1) AS gcash_reference_number,
    (SELECT g.review_note FROM gcash_payment_submissions g WHERE g.order_id = o.id ORDER BY g.id DESC LIMIT 1) AS gcash_review_note
    FROM orders o WHERE o.customer_id = ? ORDER BY o.id DESC LIMIT 50');
$stmt->execute([(int)$customer['id']]);
$tickets = $stmt->fetchAll();
$itemsQuery = db()->prepare('SELECT service_name, unit, quantity, subtotal FROM order_items WHERE order_id = ? ORDER BY id');
foreach ($tickets as &$ticket) {
    $itemsQuery->execute([(int)$ticket['id']]);
    $ticket['services'] = $itemsQuery->fetchAll();
    $ticket['history'] = order_history((int)$ticket['id']);
}
unset($ticket);
api_json(200, ['success' => true, 'tickets' => $tickets]);
