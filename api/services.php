<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');

$rows = db()->query('SELECT id, name, category, unit, price, description FROM services WHERE is_active = 1 ORDER BY category, name')->fetchAll();
$rows = array_values(array_filter($rows, static fn ($service) => stripos((string)$service['category'], 'add') !== 0 && !preg_match('/detergent|fabcon|fabric conditioner|downy|surf/i', (string)$service['name'])));
$rows[] = ['id' => -1, 'name' => 'Downy', 'category' => 'Add-ons', 'unit' => 'load', 'price' => 21.00, 'description' => 'Downy fabric conditioner, PHP 21 per laundry load.'];
$rows[] = ['id' => -2, 'name' => 'Surf', 'category' => 'Add-ons', 'unit' => 'load', 'price' => 21.00, 'description' => 'Surf detergent, PHP 21 per laundry load.'];
api_json(200, ['success' => true, 'services' => $rows]);
