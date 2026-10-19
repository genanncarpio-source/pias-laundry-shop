<?php
/** One request ID represents one immutable booking for one authenticated customer. */
function mobile_booking_canonical($value) {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    return array_map('mobile_booking_canonical', $value);
}

function mobile_booking_json(int $status, array $payload): void {
    // Includes all validation exits after reserving the request ID.
    if (db()->inTransaction()) db()->rollBack();
    api_json($status, $payload);
}

function mobile_booking_begin(PDO $pdo, int $customerId, array $data): ?string {
    if (!array_key_exists('client_request_id', $data)) return null; // Older clients remain supported.
    $id = strtolower(api_text($data, 'client_request_id'));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
        mobile_booking_json(422, ['success' => false, 'error' => 'The booking request ID is invalid.']);
    }
    $data['client_request_id'] = $id;
    $hash = hash('sha256', json_encode(mobile_booking_canonical($data), JSON_THROW_ON_ERROR));
    // DDL must happen before the booking transaction (MySQL implicitly commits DDL).
    $pdo->exec(file_get_contents(__DIR__ . '/../database/upgrade_offline_bookings.sql'));
    $pdo->beginTransaction();
    $reserve = $pdo->prepare('INSERT INTO mobile_booking_requests (customer_id, client_request_id, request_hash)
        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE client_request_id = VALUES(client_request_id)');
    $reserve->execute([$customerId, $id, $hash]);
    // Concurrent retries block on the same primary key until the first request commits/rolls back.
    $query = $pdo->prepare('SELECT request_hash, response_json FROM mobile_booking_requests
        WHERE customer_id = ? AND client_request_id = ? FOR UPDATE');
    $query->execute([$customerId, $id]);
    $existing = $query->fetch();
    if (!$existing || !hash_equals($existing['request_hash'], $hash)) {
        mobile_booking_json(409, ['success' => false, 'code' => 'client_request_conflict',
            'error' => 'This request ID already belongs to another booking. Contact the shop before booking again.']);
    }
    if ($existing['response_json'] !== null) {
        $response = json_decode($existing['response_json'], true, 512, JSON_THROW_ON_ERROR);
        mobile_booking_json(201, $response); // Original receipt, even if prices/schedule have since changed.
    }
    return $id;
}
