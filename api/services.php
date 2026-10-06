<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('GET');

$rows = db()->query('SELECT id, name, category, unit, price, description FROM services WHERE is_active = 1 ORDER BY category, name')->fetchAll();
api_json(200, ['success' => true, 'services' => $rows]);
