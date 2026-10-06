<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$customer = api_current_customer();
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches);
$stmt = db()->prepare('DELETE FROM customer_sessions WHERE customer_id = ? AND token_hash = ?');
$stmt->execute([(int)$customer['id'], hash('sha256', $matches[1])]);
api_json(200, ['success' => true, 'message' => 'You have signed out.']);
