<?php

/** Base price for one 7 kg load. */
function service_load_price(string $category): ?float
{
    return match ($category) {
        'Wash', 'Dry' => 85.00,
        'Full Service' => 199.00,
        default => null,
    };
}

/** Full Service allows up to 8 kg per load; Wash and Dry allow up to 7 kg. */
function service_weight_load_count(float $weightKg, string $category = 'Wash'): int
{
    $isFullService = in_array(strtolower(trim($category)), ['full service', 'wash & fold'], true);
    $capacity = $isFullService ? 8 : 7;
    return $weightKg > 0 ? (int)ceil($weightKg / $capacity) : 0;
}

/** Wash/Dry are charged per 7 kg load; Full Service allows 8 kg with a ₱39 surcharge. */
function service_weight_load_price(float $weightKg, float $basePrice, string $category = 'Wash'): float
{
    $remaining = max(0, round($weightKg, 2));
    $isFullService = in_array(strtolower(trim($category)), ['full service', 'wash & fold'], true);
    if ($isFullService && $remaining > 8) {
        return round(service_weight_load_count($remaining, $category) * $basePrice, 2);
    }
    $total = 0.0;
    $capacity = $isFullService ? 8 : 7;
    while ($remaining > 0.0001) {
        $loadWeight = min($capacity, $remaining);
        $total += $basePrice + ($isFullService && $loadWeight > 7 ? 39 : 0);
        $remaining = max(0, round($remaining - $loadWeight, 2));
    }
    return round($total, 2);
}

/** Price weight-based core services using their category-specific load limits. */
function service_order_price(string $category, string $unit, float $quantity, float $storedPrice): float
{
    if ($unit !== 'kg') return round($storedPrice * $quantity, 2);
    return service_weight_load_price($quantity, service_load_price($category) ?? $storedPrice, $category);
}

