<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$customer = api_current_customer();
api_json(200, ['success' => true, 'customer' => $customer, 'offline_booking_version' => 1]);
