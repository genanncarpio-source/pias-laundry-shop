<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');
$token = api_driver_token_value();
db()->prepare('DELETE FROM delivery_driver_sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
api_json(200, ['success' => true]);
