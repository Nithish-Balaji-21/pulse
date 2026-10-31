<?php

namespace App\Support\Pulse;

use App\Models\Team;

/**
 * Reads the factory geofencing add-on flag and stored max-boundaries cap.
 */
final class GeofencingModule
{
    public static function defaultMaxBoundaries(): int
    {
        return max(1, (int) config('geofencing.default_max_boundaries', 5));
    }

    public static function absoluteMaxBoundaries(): int
    {
        return max(self::defaultMaxBoundaries(), (int) config('geofencing.absolute_max_boundaries', 20));
    }

    public static function isActive(Team $team): bool
    {
        return (bool) ($team->pulse?->modules['pulse']['modules']['geofencing']['is_active'] ?? false);
    }

    public static function maxBoundaries(Team $team): int
    {
        $stored = $team->pulse?->modules['pulse']['modules']['geofencing']['max_boundaries'] ?? null;
        $value = $stored === null ? self::defaultMaxBoundaries() : (int) $stored;

        return max(1, min($value, self::absoluteMaxBoundaries()));
    }

    /**
     * @return array{is_active: bool, max_boundaries: int}
     */
    public static function payload(bool $isActive, ?int $maxBoundaries = null): array
    {
        $max = $maxBoundaries ?? self::defaultMaxBoundaries();

        return [
            'is_active' => $isActive,
            'max_boundaries' => max(1, min($max, self::absoluteMaxBoundaries())),
        ];
    }
}
