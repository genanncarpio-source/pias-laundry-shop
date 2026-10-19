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

/** Charge one minimum 7 kg load, then prorate additional weight per kg. */
function service_order_price(string $category, string $unit, float $quantity, float $storedPrice): float
{
    if ($unit !== 'kg') return round($storedPrice * $quantity, 2);

    $loadPrice = service_load_price($category) ?? $storedPrice;
    return round($loadPrice * max(7.0, $quantity) / 7, 2);
}

