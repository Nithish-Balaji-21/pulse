<?php

namespace App\Support\Pulse;

/**
 * How a cumulative device counter is turned into a production quantity.
 *
 * Machines report a counter that only ever climbs, so what a shift produced is the
 * distance the counter travelled, not the number of rows that arrived — a lost upload
 * costs a row but never a count. The counter does occasionally reset to zero, which the
 * two rules here are what every module agrees to do about.
 */
class ProductionCounter
{
    /**
     * How far below the previous reading a counter must fall before it is a machine
     * restarting rather than a reading that arrived late.
     *
     * A restart takes the counter back to zero, so the two are nothing like each other: in
     * this app's history every real reset has gone to 0 or near it, while a straggler sits at
     * 0.9999 of the reading it follows. Halving is a wide berth between them.
     */
    public const RESET_RATIO = 0.5;

    /**
     * Whether a counter below the one before it is a machine that restarted.
     *
     * Anything else is a reading that reached us out of order — the device's uploads do not
     * always arrive in the order the bales were made — and the bale it reports was already
     * counted by the reading that overtook it.
     */
    public static function isReset(?int $previous, int $current): bool
    {
        return $previous !== null && $current < $previous && $current <= $previous * self::RESET_RATIO;
    }

    /**
     * What one reading adds, given the reading before it in the same period.
     *
     * The first reading of a period is worth 1 — the bale that made the counter move — a
     * counter that has restarted is worth 1 rather than a negative jump, and a straggler that
     * arrived after the reading that overtook it is worth nothing, having already been
     * counted. Scoring a straggler as a reset, as this did before, charged it a bale and then
     * charged the whole drop again when the counter caught back up.
     */
    public static function increment(?int $previous, int $current): int
    {
        if ($previous === null) {
            return 1;
        }

        if ($current >= $previous) {
            return $current - $previous;
        }

        return self::isReset($previous, $current) ? 1 : 0;
    }

    /**
     * What the walk carries forward past this reading: the highest the counter has reached,
     * so a straggler does not drag the baseline back and have the climb charged twice — or
     * the reading itself, where the machine restarted and a new climb begins from it.
     */
    public static function carryForward(?int $previous, int $current): int
    {
        if ($previous === null || self::isReset($previous, $current)) {
            return $current;
        }

        return max($previous, $current);
    }

    /**
     * The same three rules for a counter that is not counted in whole units — an energy
     * meter, where truncating every step to an integer would quietly lose a day's fractions.
     */
    public static function incrementFloat(?float $previous, float $current): float
    {
        if ($previous === null) {
            return 1.0;
        }

        if ($current >= $previous) {
            return $current - $previous;
        }

        return $current <= $previous * self::RESET_RATIO ? 1.0 : 0.0;
    }

    /**
     * {@see carryForward()}, for a counter that is not counted in whole units.
     */
    public static function carryForwardFloat(?float $previous, float $current): float
    {
        if ($previous === null || $current <= $previous * self::RESET_RATIO) {
            return $current;
        }

        return max($previous, $current);
    }

    /**
     * SQL for what one reading adds, given the counter on the reading before it in the same
     * period — NULL at the period's first reading. The same two rules as {@see increment()},
     * for the callers that have to walk the readings in the database.
     */
    public static function incrementSql(string $current, string $previous): string
    {
        $ratio = self::RESET_RATIO;

        return "CASE WHEN {$previous} IS NULL THEN 1
                     WHEN {$current} >= {$previous} THEN {$current} - {$previous}
                     WHEN {$current} <= {$previous} * {$ratio} THEN 1
                     ELSE 0 END";
    }

    /**
     * A whole period's production from the span of its counter, inclusive of the first
     * reading. When the counter never moved but readings did arrive, that is one unit.
     */
    public static function span(int|float|null $maxMinusMin, int $count): int
    {
        $diff = (int) ($maxMinusMin ?? 0);

        if ($diff > 0) {
            return $diff + 1;
        }

        return $count > 0 ? 1 : 0;
    }

    /**
     * As {@see bucketed()}, for readings from more than one device.
     *
     * Two devices' counters are unrelated sequences, so each is walked on its own and seeded
     * on its own; differencing one against the other — which a single walk over interleaved
     * readings does — produces a number that means nothing. The buckets are shared, so what
     * comes back is still the team's production per bucket.
     *
     * @param  iterable<int, object>  $readings  ordered by time, ascending
     * @param  callable(object): int  $deviceOf  the device a reading came from
     * @param  callable(object): string  $bucketKey  the bucket a reading falls in
     * @param  callable(object): int  $counter  the reading's counter value
     * @param  array<int, int|null>  $previousByDevice  each device's seed, keyed by device id
     * @return array<string, int>
     */
    public static function bucketedPerDevice(
        iterable $readings,
        callable $deviceOf,
        callable $bucketKey,
        callable $counter,
        array $previousByDevice = [],
    ): array {
        $buckets = [];

        foreach (collect($readings)->groupBy($deviceOf) as $deviceId => $deviceReadings) {
            foreach (self::bucketed($deviceReadings, $bucketKey, $counter, $previousByDevice[(int) $deviceId] ?? null) as $key => $value) {
                $buckets[$key] = ($buckets[$key] ?? 0) + $value;
            }
        }

        return $buckets;
    }

    /**
     * Per-bucket production for one period's readings, ordered by time.
     *
     * Walking the readings rather than taking each bucket's own span is what keeps the
     * buckets summing to the period's span: the gap between two buckets belongs to the
     * later one, and no bucket claims a first-reading unit of its own.
     *
     * A `$previous` seed carries the walk on from the reading before the period started, so
     * the counter travel since then is credited rather than scored as a bare first reading.
     * See {@see CounterSeed} for where a seed comes from and when it
     * is allowed to carry.
     *
     * @param  iterable<int, object>  $readings  ordered by time, ascending
     * @param  callable(object): string  $bucketKey  the bucket a reading falls in
     * @param  callable(object): int  $counter  the reading's counter value
     * @param  int|null  $previous  the counter before the first reading, or null to open cold
     * @return array<string, int>
     */
    public static function bucketed(iterable $readings, callable $bucketKey, callable $counter, ?int $previous = null): array
    {
        $buckets = [];

        foreach ($readings as $reading) {
            $current = $counter($reading);
            $key = $bucketKey($reading);

            $buckets[$key] = ($buckets[$key] ?? 0) + self::increment($previous, $current);
            $previous = self::carryForward($previous, $current);
        }

        return $buckets;
    }
}
