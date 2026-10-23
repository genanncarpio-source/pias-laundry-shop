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
ensure_customer_schema();
ensure_workflow_schema();

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

function api_is_delivery_area(float $latitude, float $longitude): bool
{
    // Free delivery corridor covering San Jose, San Manuel, and Balingcanaway, Tarlac.
    return $latitude >= 15.4400 && $latitude <= 15.5350
        && $longitude >= 120.6000 && $longitude <= 120.7300;
}
function api_delivery_quote(float $latitude, float $longitude): array
{
    if (api_is_delivery_area($latitude, $longitude)) {
        return ['free_delivery' => true, 'distance_km' => 0, 'delivery_fee' => 0.0];
    }
    $originLat = filter_var(get_setting('shop_latitude'), FILTER_VALIDATE_FLOAT);
    $originLon = filter_var(get_setting('shop_longitude'), FILTER_VALIDATE_FLOAT);
    if ($originLat === false || $originLon === false || $originLat < -90 || $originLat > 90 || $originLon < -180 || $originLon > 180) {
        throw new RuntimeException('The shop delivery origin is not configured. Ask the shop to add its map coordinates in Settings.');
    }
    $apiKey = trim((string)getenv('GEOAPIFY_API_KEY'));
    if ($apiKey === '') throw new RuntimeException('Distance-based delivery pricing is not configured yet.');
    $query = http_build_query([
        'waypoints' => $originLat . ',' . $originLon . '|' . $latitude . ',' . $longitude,
        'mode' => 'drive', 'format' => 'json', 'apiKey' => $apiKey,
    ]);
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => "Accept: application/json\r\nUser-Agent: PiasLaundryShop/1.0\r\n",
        'timeout' => 8, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents('https://api.geoapify.com/v1/routing?' . $query, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) $status = (int)$match[1];
    $payload = is_string($response) ? json_decode($response, true) : null;
    $meters = $payload['results'][0]['distance'] ?? null;
    if ($status < 200 || $status >= 300 || !is_numeric($meters) || (float)$meters <= 0) {
        error_log('Geoapify delivery route failed with HTTP ' . $status);
        throw new RuntimeException('Could not calculate the driving distance right now. Please try again.');
    }
    $billableKm = (int)ceil(((float)$meters / 1000) - 0.0000001);
    $fee = 30 + max(0, $billableKm - 1) * 10;
    return ['free_delivery' => false, 'distance_km' => round((float)$meters / 1000, 2), 'billable_km' => $billableKm, 'delivery_fee' => (float)$fee];
}
function api_is_complete_address(string $address): bool
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $address)), static fn ($part) => $part !== ''));
    return count($parts) >= 3;
}
function api_is_san_manuel_tarlac_address(string $address): bool
{
    return api_is_complete_address($address) && stripos($address, 'tarlac') !== false;
}
function api_delivery_area_error(): string { return 'Free delivery covers San Jose, San Manuel, and Balingcanaway, Tarlac. Other locations may have a distance-based fee.'; }
function api_send_transactional_email(string $email, string $subject, string $text): void
{
    $apiKey = trim((string)getenv('BREVO_API_KEY'));
    $from = trim((string)getenv('EMAIL_FROM'));
    $fromName = trim((string)(getenv('EMAIL_FROM_NAME') ?: "Pia's Laundry Shop"));

    if ($apiKey === '' || $from === '') {
        throw new RuntimeException('Email verification is not configured. Add BREVO_API_KEY and EMAIL_FROM in Railway.');
    }

    $payload = json_encode([
        'sender' => ['name' => $fromName, 'email' => $from],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'textContent' => $text,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'api-key: ' . $apiKey . chr(13) . chr(10)
            . 'Accept: application/json' . chr(13) . chr(10)
            . 'Content-Type: application/json' . chr(13) . chr(10),
        'content' => $payload,
        'timeout' => 7,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) $status = (int)$matches[1];
    }
    if ($status >= 200 && $status < 300) return;

    $provider = is_string($response) ? json_decode($response, true) : null;
    $providerMessage = strtolower((string)($provider['message'] ?? ''));
    $providerCode = strtolower((string)($provider['code'] ?? ''));
    error_log('Brevo email request failed with HTTP ' . $status . ' and code ' . $providerCode . '.');

    if ($status === 401 || $status === 403 || str_contains($providerMessage, 'api key')) {
        throw new RuntimeException('Brevo rejected the API key. Check BREVO_API_KEY in Railway.');
    }
    if (str_contains($providerMessage, 'sender') || str_contains($providerMessage, 'verified')) {
        throw new RuntimeException('Brevo has not verified EMAIL_FROM. Verify this sender email in Brevo, then try again.');
    }
    if ($status === 429) {
        throw new RuntimeException('Brevo email sending is temporarily rate limited. Please wait and try again.');
    }
    if ($status === 0) {
        throw new RuntimeException('Could not connect to the Brevo email service. Please try again.');
    }
    throw new RuntimeException('Brevo could not send the verification email. Check the Railway email settings.');
}
function api_send_registration_otp(string $email, string $name, string $code): void
{
    api_send_transactional_email(
        $email,
        "Your Pia's Laundry Shop verification code",
        "Hello {$name},\n\nYour Pia's Laundry Shop verification code is: {$code}\n\nThis code expires in 10 minutes. Do not share it with anyone."
    );
}
function api_send_password_reset_otp(string $email, string $name, string $code): void
{
    api_send_transactional_email(
        $email,
        "Reset your Pia's Laundry Shop password",
        "Hello {$name},\n\nYour password reset code is: {$code}\n\nThis code expires in 10 minutes. If you did not request a password reset, you can ignore this email."
    );
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
        'SELECT c.id, c.name, c.email, c.phone, c.address, c.location_latitude, c.location_longitude, c.profile_photo
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


function api_issue_driver_token(int $driverId): string
{
    $pdo = db();
    $pdo->exec('DELETE FROM delivery_driver_sessions WHERE expires_at <= NOW()');
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO delivery_driver_sessions (driver_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))');
    $stmt->execute([$driverId, hash('sha256', $token)]);
    return $token;
}

function api_driver_token_value(): string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\\s+([a-f0-9]{64})$/i', trim($authorization), $matches)) {
        api_json(401, ['success' => false, 'error' => 'Please sign in as a delivery driver.']);
    }
    return strtolower($matches[1]);
}

function api_current_driver(): array
{
    $token = api_driver_token_value();
    $stmt = db()->prepare(
        'SELECT d.id, d.full_name, d.username, d.phone
           FROM delivery_driver_sessions s
           JOIN delivery_drivers d ON d.id = s.driver_id
          WHERE s.token_hash = ? AND s.expires_at > NOW() AND d.is_active = 1
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $driver = $stmt->fetch();
    if (!$driver) api_json(401, ['success' => false, 'error' => 'Your driver session expired. Sign in again.']);
    return $driver;
}
