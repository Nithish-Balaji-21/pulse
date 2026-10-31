<?php

namespace App\Services\Pulse;

class DefibreStateClassifier
{
    public const STATE_SHUTDOWN = 'shutdown';

    public const STATE_OFF = 'off';

    public const STATE_ON = 'on';

    public static function voltageSum(float|int|string|null $d4, float|int|string|null $d5, float|int|string|null $d6): float
    {
        return (float) $d4 + (float) $d5 + (float) $d6;
    }

    public static function isShutdown(float|int $current, float $voltage): bool
    {
        return $current == 0 && $voltage == 0;
    }

    /**
     * Classify defibre telemetry for real-time monitoring (on / off / shutdown).
     * Mirrors the shutdown vs off rules in ProcessDeviceDataForStates.
     */
    public static function classifyForMonitoring(
        float|int|string|null $d7,
        float|int|string|null $d4,
        float|int|string|null $d5,
        float|int|string|null $d6,
        int $offThreshold = 10
    ): string {
        $current = (float) $d7;
        $voltage = self::voltageSum($d4, $d5, $d6);

        if (self::isShutdown($current, $voltage)) {
            return self::STATE_SHUTDOWN;
        }

        if ($current < $offThreshold) {
            return self::STATE_OFF;
        }

        return self::STATE_ON;
    }
}
