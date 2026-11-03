<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/feature_schema.php';
ensure_review_schema();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $reviews = db()->query("SELECT r.id, r.rating, r.comment, r.created_at, c.name AS customer_name
        FROM reviews r JOIN customers c ON c.id = r.customer_id
        WHERE r.rating = 5 ORDER BY r.id DESC LIMIT 100")->fetchAll();
    api_json(200, ['success' => true, 'reviews' => $reviews]);
}
if ($method !== 'POST') {
    header('Allow: GET, POST, OPTIONS');
    api_json(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$customer = api_current_customer();
$data = api_input();
$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
$rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);
$comment = is_string($data['comment'] ?? null) ? trim($data['comment']) : '';
$commentLength = function_exists('mb_strlen') ? mb_strlen($comment, 'UTF-8') : strlen($comment);
if (!$orderId || $orderId < 1) api_json(422, ['success' => false, 'error' => 'Choose a valid booking to review.']);
if ($rating !== 5) api_json(422, ['success' => false, 'error' => 'Only five-star reviews can be submitted.']);
if ($commentLength > 1000) api_json(422, ['success' => false, 'error' => 'Keep your optional comment within 1,000 characters.']);

$pdo = db();
$order = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND customer_id = ? LIMIT 1');
$order->execute([$orderId, (int)$customer['id']]);
if (!$order->fetchColumn()) api_json(404, ['success' => false, 'error' => 'That booking could not be found in your account.']);
$exists = $pdo->prepare('SELECT id FROM reviews WHERE order_id = ? LIMIT 1');
$exists->execute([$orderId]);
if ($exists->fetchColumn()) api_json(409, ['success' => false, 'error' => 'A review has already been submitted for this booking.']);

try {
    $insert = $pdo->prepare('INSERT INTO reviews (order_id, customer_id, rating, comment) VALUES (?, ?, 5, ?)');
    $insert->execute([$orderId, (int)$customer['id'], $comment !== '' ? $comment : null]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') api_json(409, ['success' => false, 'error' => 'A review has already been submitted for this booking.']);
    api_json(500, ['success' => false, 'error' => 'Could not save your review. Please try again.']);
}
api_json(201, ['success' => true, 'message' => 'Thank you for your five-star review.']);