<?php

namespace App\Services\Pulse;

class WaterTankLevelCalculator
{
    /**
     * Convert sensor available-height (cm from top) to current litres.
     * Returns null when tank geometry/capacity is invalid.
     */
    public function litres(object $tank, float $availableHeight): ?float
    {
        $capacity = (float) ($tank->capacity_litres ?? 0);
        $height = (float) ($tank->cyl_height ?? 0);
        $diameter = (float) ($tank->cyl_diameter ?? 0);

        if ($capacity <= 0 || $height <= 0 || $diameter <= 0) {
            return null;
        }

        // Sensor reports empty distance from the top of the tank.
        $waterHeight = max(0, $height - $availableHeight);
        $radius = $diameter / 2;
        $volumeLitres = (M_PI * ($radius ** 2) * $waterHeight) / 1000;

        return round(min(max(0, $volumeLitres), $capacity), 1);
    }

    /**
     * Fill percentage of tank capacity (0–100). Null when geometry is invalid.
     */
    public function percentage(object $tank, float $availableHeight): ?float
    {
        $capacity = (float) ($tank->capacity_litres ?? 0);
        $litres = $this->litres($tank, $availableHeight);

        if ($litres === null || $capacity <= 0) {
            return null;
        }

        return round(($litres / $capacity) * 100, 2);
    }
}
