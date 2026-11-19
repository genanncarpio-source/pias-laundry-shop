<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/service_pricing.php';
api_require_method('GET');
ensure_service_load_capacity_schema();
ensure_service_addons_schema();

$rows = db()->query('SELECT id, name, category, unit, kg_per_load, price, description FROM services WHERE is_active = 1 ORDER BY category, name')->fetchAll();
$rows = array_values(array_filter($rows, static fn ($service) => stripos((string)$service['category'], 'add') === 0 || !preg_match('/detergent|fabcon|fabric conditioner|downy|surf/i', (string)$service['name'])));
foreach ($rows as &$service) {
    $service['kg_per_load'] = service_uses_weighted_load($service)
        ? service_load_capacity((string)$service['category'], $service['kg_per_load'] !== null ? (float)$service['kg_per_load'] : null)
        : null;
}
unset($service);
foreach ($rows as &$service) {
    if (stripos((string)$service['category'], 'add') !== 0) $service['addon_ids'] = service_assigned_addon_ids((int)$service['id']);
}
unset($service);
api_json(200, ['success' => true, 'services' => $rows]);
