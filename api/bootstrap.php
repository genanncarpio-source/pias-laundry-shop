<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/feature_schema.php';

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
ensure_customer_auth_schema();

function api_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_input(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

function api_text(array $data, string $key): string
{
    return is_string($data[$key] ?? null) ? trim($data[$key]) : '';
}

function api_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method . ', OPTIONS');
        api_json(405, ['success' => false, 'error' => 'Method not allowed.']);
    }
}

function api_issue_customer_token(int $customerId): string
{
    db()->exec('DELETE FROM customer_sessions WHERE expires_at <= NOW()');
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('INSERT INTO customer_sessions (customer_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))');
    $stmt->execute([$customerId, $hash]);
    return $token;
}

function api_current_customer(): array
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches)) {
        api_json(401, ['success' => false, 'error' => 'Please sign in to continue.']);
    }

    $stmt = db()->prepare(
        'SELECT c.id, c.name, c.email, c.phone
           FROM customer_sessions s
           JOIN customers c ON c.id = s.customer_id
          WHERE s.token_hash = ? AND s.expires_at > NOW() AND c.is_active = 1
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $matches[1])]);
    $customer = $stmt->fetch();
    if (!$customer) api_json(401, ['success' => false, 'error' => 'Your session expired. Please sign in again.']);
    return $customer;
}
