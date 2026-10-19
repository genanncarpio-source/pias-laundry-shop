<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');
api_current_customer();

$name = trim(get_setting('gcash_account_name'));
$number = trim(get_setting('gcash_number'));
$qrPath = trim(get_setting('gcash_qr_path'));
if ($qrPath !== '' && !preg_match('#^uploads/gcash/[A-Za-z0-9._-]+\.(?:png|jpe?g|webp)$#i', $qrPath)) {
    $qrPath = '';
}
api_json(200, [
    'success' => true,
    'payment' => [
        'configured' => $name !== '' && $number !== '',
        'account_name' => $name,
        'number' => $number,
        'qr_path' => $qrPath,
    ],
]);