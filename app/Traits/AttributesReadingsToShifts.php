<?php

namespace App\Traits;

use App\Models\Pulse\Shift;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Places device readings that carry no shift_id onto a shift by their time of day.
 *
 * The nightly processing jobs stamp shift_id, so readings from the day in progress — and
 * any stretch the job never got to — arrive unattributed. Rather than pooling those under
 * an 'Others' heading, which reads as a fourth shift nobody works, they join the shift
 * their time of day falls in.
 */
trait AttributesReadingsToShifts
{
    /**
     * The shift day split into one contiguous segment per shift, so every minute of the
     * day belongs to a shift and a reading can always be placed by its time.
     *
     * Where the schedule leaves a gap between two shifts the gap splits at its midpoint,
     * giving each reading to whichever shift it sits closer to. Anything before the first
     * shift starts or after the last one ends stays with that shift rather than falling
     * into the neighbouring shift day, which is how the summary tables key it.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return list<array{id: int, name: string, until: int}> ordered; the last `until` is 1440
     */
    protected function shiftDaySegments(Collection $shifts, string $anchor): array
    {
        $ordered = $shifts
            ->map(fn (Shift $shift) => ['shift' => $shift, 'offsets' => $shift->offsetsFor($anchor)])
            ->sortBy(fn (array $entry) => $entry['offsets'][0])
            ->values();

        $segments = [];

        foreach ($ordered as $position => $entry) {
            $next = $ordered->get($position + 1);

            $segments[] = [
                'id' => $entry['shift']->id,
                'name' => $entry['shift']->name,
                'until' => $next === null
                    ? Shift::MINUTES_PER_DAY
                    : intdiv($entry['offsets'][1] + $next['offsets'][0], 2),
            ];
        }

        return $segments;
    }

    /**
     * The id of the shift a moment falls in, per the segments from `shiftDaySegments` —
     * the PHP counterpart of `shiftIdCaseSql`, for the callers that walk readings in memory
     * rather than grouping them in SQL. Both must place a reading the same way, or the
     * summary and the raw fallback disagree about which shift it belongs to.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     */
    protected function shiftIdForMoment(array $segments, string $anchor, string $timestamp): ?int
    {
        if ($segments === []) {
            return null;
        }

        $minute = Shift::clockToOffset(Carbon::parse($timestamp)->format('H:i:s'), $anchor);

        foreach (array_slice($segments, 0, -1) as $segment) {
            if ($minute < $segment['until']) {
                return $segment['id'];
            }
        }

        return end($segments)['id'];
    }

    /**
     * SQL expression resolving $column to the id of the shift its time of day falls in,
     * per the segments from `shiftDaySegments`.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     */
    protected function shiftIdCaseSql(array $segments, string $anchor, string $column): string
    {
        if ($segments === []) {
            return 'NULL';
        }

        // A single shift owns the whole day — no WHEN branches needed, and MySQL rejects
        // `CASE ELSE id END` (a CASE with no WHEN). CAST keeps the literal from being
        // treated as a GROUP BY column ordinal.
        if (count($segments) === 1) {
            return 'CAST('.((int) $segments[0]['id']).' AS UNSIGNED)';
        }

        $anchorMinutes = Shift::clockToOffset($anchor, '00:00:00');
        $minuteOfShiftDay = "MOD(TIME_TO_SEC(TIME({$column})) DIV 60 - {$anchorMinutes} + 1440, 1440)";

        $cases = '';
        foreach (array_slice($segments, 0, -1) as $segment) {
            $cases .= "WHEN {$minuteOfShiftDay} < {$segment['until']} THEN {$segment['id']} ";
        }

        return "CASE {$cases}ELSE ".end($segments)['id'].' END';
    }
}
