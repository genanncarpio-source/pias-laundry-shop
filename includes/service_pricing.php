<?php

/** Add the optional per-service load capacity to older databases. */
function ensure_service_load_capacity_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $pdo = db();
    $column = $pdo->query("SHOW COLUMNS FROM services LIKE 'kg_per_load'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        try {
            $pdo->exec('ALTER TABLE services ADD COLUMN kg_per_load DECIMAL(5,2) DEFAULT NULL AFTER unit');
        } catch (PDOException $error) {
            $column = $pdo->query("SHOW COLUMNS FROM services LIKE 'kg_per_load'")->fetch(PDO::FETCH_ASSOC);
            if (!$column) throw $error;
        }
    }
    $ready = true;
}

function service_default_load_capacity(string $category): float
{
    return in_array(strtolower(trim($category)), ['full service', 'wash & fold'], true) ? 8.0 : 7.0;
}

function service_load_capacity(string $category, ?float $configuredCapacity = null): float
{
    return $configuredCapacity !== null && is_finite($configuredCapacity) && $configuredCapacity > 0
        ? $configuredCapacity
        : service_default_load_capacity($category);
}

function service_uses_weighted_load(array $service): bool
{
    return strtolower(trim((string)($service['unit'] ?? ''))) === 'load'
        && stripos((string)($service['category'] ?? ''), 'add') !== 0;
}

/** Category-based overrides are intentionally disabled; each service row owns its price. */
function service_load_price(string $category): ?float
{
    return null;
}

/** Count billable loads using the service capacity or its historical category default. */
function service_weight_load_count(float $weightKg, string $category = 'Wash', ?float $kgPerLoad = null): int
{
    $capacity = service_load_capacity($category, $kgPerLoad);
    return $weightKg > 0 ? (int)ceil($weightKg / $capacity) : 0;
}

/** Price by the configured capacity while preserving the existing Full Service 8 kg surcharge. */
function service_weight_load_price(float $weightKg, float $basePrice, string $category = 'Wash', ?float $kgPerLoad = null): float
{
    $remaining = max(0, round($weightKg, 2));
    $isFullService = in_array(strtolower(trim($category)), ['full service', 'wash & fold'], true);
    $capacity = service_load_capacity($category, $kgPerLoad);
    if (!$isFullService || abs($capacity - 8.0) > 0.001) {
        return round(service_weight_load_count($remaining, $category, $capacity) * $basePrice, 2);
    }
    $total = 0.0;
    if ($remaining > $capacity) return round(service_weight_load_count($remaining, $category, $capacity) * $basePrice, 2);
    while ($remaining > 0.0001) {
        $loadWeight = min($capacity, $remaining);
        $total += $basePrice + ($isFullService && $loadWeight > 7 ? 39 : 0);
        $remaining = max(0, round($remaining - $loadWeight, 2));
    }
    return round($total, 2);
}

/** Price weight-based core services using their category-specific load limits. */
function service_order_price(string $category, string $unit, float $quantity, float $storedPrice, ?float $kgPerLoad = null): float
{
    if ($unit === 'load') return service_weight_load_price($quantity, $storedPrice, $category, $kgPerLoad);
    return round($storedPrice * $quantity, 2);
}
