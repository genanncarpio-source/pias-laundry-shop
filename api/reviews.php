<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/feature_schema.php';
ensure_review_schema();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$customer = api_current_customer();
if ($method === 'GET') {
    $customerId = (int)$customer['id'];
    $review = db()->prepare('SELECT id, rating, created_at FROM reviews WHERE customer_id = ? ORDER BY id DESC LIMIT 1');
    $review->execute([$customerId]);
    $existingReview = $review->fetch();
    $eligibleOrders = db()->prepare("SELECT o.id, o.order_number, o.status FROM orders o WHERE o.customer_id = ? AND o.status IN ('completed','picked_up') AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_id = o.id) ORDER BY COALESCE(o.completed_at, o.updated_at) DESC, o.id DESC LIMIT 20");
    $eligibleOrders->execute([$customerId]);
    $eligible = $eligibleOrders->fetchAll();
    api_json(200, ['success' => true, 'has_reviewed' => (bool)$existingReview, 'review' => $existingReview ?: null, 'eligible_orders' => $eligible, 'eligible_order' => $eligible[0] ?? null]);
}
if ($method !== 'POST') {
    header('Allow: GET, POST, OPTIONS');
    api_json(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$data = api_input();
$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
$rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);
$comment = is_string($data['comment'] ?? null) ? trim($data['comment']) : '';
$commentLength = function_exists('mb_strlen') ? mb_strlen($comment, 'UTF-8') : strlen($comment);
if (!$orderId || $orderId < 1) api_json(422, ['success' => false, 'error' => 'Choose a valid booking to review.']);
if ($rating === false || $rating < 1 || $rating > 5) api_json(422, ['success' => false, 'error' => 'Choose a rating from 1 to 5 stars.']);
if ($commentLength > 1000) api_json(422, ['success' => false, 'error' => 'Keep your optional comment within 1,000 characters.']);

$pdo = db();
try {
    $pdo->beginTransaction();
    $customerLock = $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
    $customerLock->execute([(int)$customer['id']]);
    $order = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND customer_id = ? AND status IN ('completed','picked_up') LIMIT 1 FOR UPDATE");
    $order->execute([$orderId, (int)$customer['id']]);
    if (!$order->fetchColumn()) {
        $pdo->rollBack();
        api_json(409, ['success' => false, 'error' => 'You can rate this booking after the shop marks it complete.']);
    }
    $alreadyRated = $pdo->prepare('SELECT id FROM reviews WHERE order_id = ? LIMIT 1');
    $alreadyRated->execute([$orderId]);
    if ($alreadyRated->fetchColumn()) {
        $pdo->rollBack();
        api_json(409, ['success' => false, 'error' => 'You have already rated this booking. Thank you.']);
    }
    $insert = $pdo->prepare('INSERT INTO reviews (order_id, customer_id, rating, comment) VALUES (?, ?, ?, ?)');
    $insert->execute([$orderId, (int)$customer['id'], $rating, $comment !== '' ? $comment : null]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') api_json(409, ['success' => false, 'error' => 'A rating has already been submitted for this booking.']);
    api_json(500, ['success' => false, 'error' => 'Could not save your rating. Please try again.']);
}
api_json(201, ['success' => true, 'message' => 'Thank you for your rating.']);