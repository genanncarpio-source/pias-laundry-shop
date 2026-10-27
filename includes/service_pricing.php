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

/** Number of loads when each load can contain up to 8 kg. */
function service_weight_load_count(float $weightKg): int
{
    return $weightKg > 0 ? (int)ceil($weightKg / 8) : 0;
}

/** Each load costs its base price up to 7 kg, plus $29 when it holds over 7 kg (maximum 8 kg). */
function service_weight_load_price(float $weightKg, float $basePrice): float
{
    $remaining = max(0, round($weightKg, 2));
    $total = 0.0;
    while ($remaining > 0.0001) {
        $loadWeight = min(8, $remaining);
        $total += $basePrice + ($loadWeight > 7 ? 29 : 0);
        $remaining = max(0, round($remaining - $loadWeight, 2));
    }
    return round($total, 2);
}

/** Price weight-based core services in 8 kg maximum loads. */
function service_order_price(string $category, string $unit, float $quantity, float $storedPrice): float
{
    if ($unit !== 'kg') return round($storedPrice * $quantity, 2);
    return service_weight_load_price($quantity, service_load_price($category) ?? $storedPrice);
}

