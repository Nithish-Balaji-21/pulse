<?php

namespace App\Support\Pulse;

use Carbon\Carbon;

class IotLiveDay
{
    public static function today(): string
    {
        return Carbon::today()->toDateString();
    }

    /**
     * The shift day currently in progress for a team whose production day starts at $anchor.
     *
     * Before the anchor time the previous calendar date is still the live shift day — a
     * night shift running past midnight belongs to the day it started on, which is how the
     * summary tables key it.
     */
    public static function forAnchor(string $anchor): string
    {
        $now = Carbon::now();

        return $now->lt($now->copy()->setTimeFromTimeString($anchor))
            ? $now->copy()->subDay()->toDateString()
            : $now->toDateString();
    }

    /**
     * Inclusive counter span used across IoT live overlays.
     * When max == min and there is at least one row, count as 1.
     */
    public static function counterSpan(int|float|null $maxMinusMin, int $count): int
    {
        return ProductionCounter::span($maxMinusMin, $count);
    }

    public static function isInRange(string $day, string $startDate, string $endDate): bool
    {
        return $startDate <= $day && $day <= $endDate;
    }
}
