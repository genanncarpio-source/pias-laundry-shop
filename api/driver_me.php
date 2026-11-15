<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
$driver = api_current_driver();
api_json(200, ['success' => true, 'driver' => [
    'id' => (int)$driver['id'], 'name' => $driver['full_name'], 'full_name' => $driver['full_name'],
    'username' => $driver['username'], 'email' => $driver['email'], 'phone' => $driver['phone'], 'profile_photo' => $driver['profile_photo'],
]]);