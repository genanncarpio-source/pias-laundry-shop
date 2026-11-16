<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$customer = api_current_customer();
$data = api_input();
$token = trim(api_text($data, 'token'));
$action = api_text($data, 'action') ?: 'register';
if (!in_array($action, ['register', 'unregister'], true)) api_json(422, ['success' => false, 'error' => 'Choose a valid push token action.']);
if (!preg_match('/^(?:Expo|Exponent)PushToken\[[A-Za-z0-9_-]{8,220}\]$/', $token)) api_json(422, ['success' => false, 'error' => 'Enter a valid Expo push token.']);
$pdo = db();
if ($action === 'unregister') {
    $stmt = $pdo->prepare('DELETE FROM customer_push_tokens WHERE customer_id = ? AND expo_push_token = ?');
    $stmt->execute([(int)$customer['id'], $token]);
} else {
    $stmt = $pdo->prepare('INSERT INTO customer_push_tokens (customer_id, expo_push_token) VALUES (?, ?) ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), updated_at = NOW()');
    $stmt->execute([(int)$customer['id'], $token]);
}
api_json(200, ['success' => true]);
