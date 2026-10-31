<?php

namespace App\Support\Pulse;

use App\Models\Pulse\Shift;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What a counter walk starts from.
 *
 * {@see ProductionCounter} says what one step of a counter is worth; this says where the
 * walk begins. A shift day that opens cold scores its first reading as a single unit however
 * far the counter moved since the previous day's last reading — so a machine that ran
 * overnight while its uploads failed lost everything but that one unit. The walk is
 * therefore seeded with the last reading before the day started.
 *
 * **The seed is bounded to the immediately preceding shift day.** A longer silence is a
 * device outage, not one night's production: the counter may have travelled across several
 * days, and crediting all of it to the day the readings resumed overstates that day as badly
 * as losing it understates every day in between. Those are left uncarried and logged, for a
 * human to judge.
 *
 * The bound is stated in shift days rather than in elapsed hours because that is the rule:
 * the silence must not have spanned a whole shift day. Where the walk starts at the anchor
 * the two say the same thing, but the mod modules seed from a window whose start an operator
 * may have set by hand, and there a fixed 24 hours would mean something different each time.
 */
final class CounterSeed
{
    /**
     * @param  array<string, int|float|null>  $counters  alias => the counter on the seed reading
     */
    private function __construct(
        private readonly array $counters,
        private readonly bool $carries,
    ) {}

    /**
     * A seed that carries nothing — the walk opens cold, exactly as it did before seeding.
     */
    public static function none(): self
    {
        return new self([], false);
    }

    /**
     * The seed for each device: the last reading before $from, where it is recent enough to
     * carry.
     *
     * The lookback query itself is deliberately unbounded, with the bound applied afterwards
     * in {@see carriesFrom()}. A bounded query would return nothing on a long outage, leaving
     * nothing to name in the warning — this way the counter movement and the length of the
     * silence come back from the same single indexed seek, and the rule lives in one method
     * rather than in a WHERE clause that could drift away from its SQL twin.
     *
     * @param  array<string, string>  $counters  alias => column, e.g. ['bales' => 'bales_counter']
     * @param  iterable<int, int>  $deviceIds
     * @return array<int, self> keyed by device id
     */
    public static function before(
        string $table,
        string $timeColumn,
        array $counters,
        iterable $deviceIds,
        CarbonInterface $from,
        string $anchor,
        string $context = '',
    ): array {
        $seeds = [];

        foreach ($deviceIds as $deviceId) {
            $seeds[$deviceId] = self::seed($table, $timeColumn, $counters, $deviceId, $from, $anchor, $context);
        }

        return $seeds;
    }

    /**
     * The same seeds for a counter that belongs not to the device as a whole but to each of
     * the things reporting through it — a growbag machine's punch counter, where one device
     * carries a separate counter per `field_id` and differencing one machine's reading
     * against another's measures nothing.
     *
     * @param  array<string, string>  $counters  alias => column
     * @param  iterable<int, int>  $deviceIds
     * @param  iterable<int, string>  $partitionValues  the values of $partitionColumn to seed
     * @return array<string, self> keyed "deviceId|partitionValue"
     */
    public static function beforePerPartition(
        string $table,
        string $timeColumn,
        array $counters,
        iterable $deviceIds,
        string $partitionColumn,
        iterable $partitionValues,
        CarbonInterface $from,
        string $anchor,
        string $context = '',
    ): array {
        $seeds = [];

        foreach ($deviceIds as $deviceId) {
            foreach ($partitionValues as $value) {
                $seeds[$deviceId.'|'.$value] = self::seed(
                    $table,
                    $timeColumn,
                    $counters,
                    $deviceId,
                    $from,
                    $anchor,
                    $context,
                    [$partitionColumn => $value],
                );
            }
        }

        return $seeds;
    }

    /**
     * The last reading before $from on one device — narrowed to one partition of it where
     * $where says so — and whether it is recent enough to carry.
     *
     * @param  array<string, string>  $counters  alias => column
     * @param  array<string, string>  $where  extra column => value the seed reading must match
     */
    private static function seed(
        string $table,
        string $timeColumn,
        array $counters,
        int $deviceId,
        CarbonInterface $from,
        string $anchor,
        string $context,
        array $where = [],
    ): self {
        $row = DB::table($table)
            ->where('device_id', $deviceId)
            ->where('is_duplicate', 0)
            ->where($timeColumn, '<', $from)
            ->where($where)
            // The walks order by (time, id), so a tie at the boundary must seed from the
            // last of them, not whichever the engine happens to return.
            ->orderByDesc($timeColumn)
            ->orderByDesc('id')
            ->first(array_merge([$timeColumn], array_values($counters)));

        if ($row === null) {
            return self::none();
        }

        $values = [];
        foreach ($counters as $alias => $column) {
            $values[$alias] = $row->{$column};
        }

        $carries = self::carriesFrom($row->{$timeColumn}, $from->toDateTimeString(), $anchor);

        if (! $carries) {
            self::warnOfOutage($table, $timeColumn, $counters, $deviceId, $row, $from, $context, $where);
        }

        return new self($values, $carries);
    }

    /**
     * Whether a reading at $previousAt is recent enough to seed a walk starting at $at:
     * it must fall in the same shift day or the one immediately before it.
     */
    public static function carriesFrom(?string $previousAt, string $at, string $anchor): bool
    {
        if ($previousAt === null) {
            return false;
        }

        $previousDay = Carbon::parse(Shift::shiftDateFor($previousAt, $anchor));
        $day = Carbon::parse(Shift::shiftDateFor($at, $anchor));

        return $previousDay->diffInDays($day) <= 1;
    }

    /**
     * The same bound as {@see carriesFrom()} for callers that resolve it in the database.
     * The two are held to the same answers by test.
     */
    public static function carriesSql(string $atColumn, string $previousAtColumn, string $anchor): string
    {
        $day = Shift::shiftDateSqlFor($atColumn, $anchor);
        $previousDay = Shift::shiftDateSqlFor($previousAtColumn, $anchor);

        return "({$previousAtColumn} IS NOT NULL AND DATEDIFF({$day}, {$previousDay}) <= 1)";
    }

    /**
     * The counter this walk starts from, or null where nothing carries — which is what
     * {@see ProductionCounter::bucketed()} takes to mean "open cold".
     */
    public function for(string $alias): ?int
    {
        if (! $this->carries) {
            return null;
        }

        $value = $this->counters[$alias] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * As {@see for()}, for a counter that is not measured in whole units.
     */
    public function floatFor(string $alias): ?float
    {
        if (! $this->carries) {
            return null;
        }

        $value = $this->counters[$alias] ?? null;

        return $value === null ? null : (float) $value;
    }

    public function carries(): bool
    {
        return $this->carries;
    }

    /**
     * Name what the bound declined to carry, so an outage that swallowed real production is
     * something an operator can find and settle by hand rather than a silent shortfall.
     *
     * The resumption is looked for in the same partition the seed came from. A growbag
     * machine's counter differenced against the next machine to punch measures the distance
     * between two counters that were never the same counter, and the difference it reports —
     * hundreds of thousands of punches, sometimes negative — names nothing an operator could
     * act on.
     *
     * @param  array<string, string>  $counters
     * @param  array<string, string>  $where  the partition the seed reading was taken from
     */
    private static function warnOfOutage(
        string $table,
        string $timeColumn,
        array $counters,
        int $deviceId,
        object $row,
        CarbonInterface $from,
        string $context,
        array $where = [],
    ): void {
        $movement = DB::table($table)
            ->where('device_id', $deviceId)
            ->where('is_duplicate', 0)
            ->where($timeColumn, '>=', $from)
            ->where($where)
            ->orderBy($timeColumn)
            ->orderBy('id')
            ->first(array_merge([$timeColumn], array_values($counters)));

        if ($movement === null) {
            return;
        }

        $seenAt = Carbon::parse($row->{$timeColumn});
        $resumedAt = Carbon::parse($movement->{$timeColumn});
        $travelled = [];

        foreach ($counters as $alias => $column) {
            $travelled[$alias] = (float) $movement->{$column} - (float) $row->{$column};
        }

        Log::warning('Counter carry-over declined: the device was silent for more than a shift day, so its counter travel is not credited to any one day.', [
            'context' => $context,
            'device_id' => $deviceId,
            ...$where,
            'last_seen_at' => $seenAt->toDateTimeString(),
            'resumed_at' => $resumedAt->toDateTimeString(),
            'silent_hours' => round($seenAt->diffInMinutes($resumedAt) / 60, 1),
            'counter_travel' => $travelled,
        ]);
    }
}
