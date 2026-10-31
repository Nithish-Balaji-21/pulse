<?php

namespace App\Jobs\Pulse;

use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\Shift;
use App\Support\Pulse\CounterSeed;
use App\Support\Pulse\ProductionCounter;
use App\Traits\RecordsJobCompletion;
use App\Traits\RetriesWithJitteredBackoff;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDeviceDataForModBalesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsJobCompletion, RetriesWithJitteredBackoff, SerializesModels;

    protected int $teamId;

    protected int $deviceId;

    protected array $dates;

    public function __construct(int $teamId, int $deviceId, array $dates)
    {
        $this->teamId = $teamId;
        $this->deviceId = $deviceId;
        $this->dates = $dates;
    }

    public function tags(): array
    {
        return ['team:'.$this->teamId];
    }

    public function handle()
    {
        $this->deleteAllDuplicateEntries();
        $this->markAllDuplicateEntries();

        $anchor = Shift::anchorForTeam($this->teamId);

        $discoveredDates = [];

        foreach ($this->dates as $date) {
            $discoveredDates = array_merge(
                $discoveredDates,
                $this->getAllDatesFromDate($date, $anchor)
            );
        }

        $targetDates = array_values(array_unique($discoveredDates));

        $this->recompute($targetDates);
    }

    /**
     * Rebuild the mini-bale production for the given shift dates and nothing else.
     *
     * Skips the whole-history duplicate sweep `handle()` opens with, which a backfill would
     * otherwise repeat once per day of its range.
     *
     * @param  list<string>  $dates
     */
    public function recompute(array $dates): void
    {
        $anchor = Shift::anchorForTeam($this->teamId);

        foreach ($dates as $date) {
            $this->stampReadingsForDay($date, $anchor);
            $this->processDayProduction($date, $anchor);
        }
    }

    protected function stampReadingsForDay(string $shiftDate, string $anchor): void
    {
        $shifts = Shift::where('team_id', $this->teamId)
            ->orderedByOffset($anchor)
            ->get();

        foreach ($shifts as $shift) {
            [$start, $end] = $shift->windowOn($shiftDate);

            DB::table('pulse_device_data_fibre_bales')
                ->where('device_id', $this->deviceId)
                ->where('bale_datetime', '>=', $start)
                ->where('bale_datetime', '<', $end)
                ->update(['shift_id' => $shift->id]);
        }
    }

    protected function processDayProduction(string $shiftDate, string $anchor): void
    {
        $existing = MiniBaleProduction::where('team_id', $this->teamId)
            ->where('shift_date', $shiftDate)
            ->first();

        if ($existing && $existing->start_time && $existing->end_time) {
            $startTime = $existing->start_time;
            $endTime = $existing->end_time;
        } else {
            [$startTime, $endTime] = $this->calculateTotalShiftWindow($shiftDate, $anchor);
        }

        $entry = DB::selectOne('
            SELECT
                f.first_counter,
                l.last_counter
            FROM
                (
                    SELECT bales_counter AS first_counter
                    FROM pulse_device_data_fibre_bales
                    WHERE device_id = ? 
                      AND bale_datetime >= ? 
                      AND bale_datetime <= ? 
                      AND is_duplicate = 0
                    ORDER BY bale_datetime ASC
                    LIMIT 1
                ) AS f
            JOIN
                (
                    SELECT bales_counter AS last_counter
                    FROM pulse_device_data_fibre_bales
                    WHERE device_id = ? 
                      AND bale_datetime >= ? 
                      AND bale_datetime <= ? 
                      AND is_duplicate = 0
                    ORDER BY bale_datetime DESC
                    LIMIT 1
                ) AS l ON 1=1
        ', [
            $this->deviceId, $startTime, $endTime,
            $this->deviceId, $startTime, $endTime,
        ]);

        if (! $entry || $entry->first_counter === null) {
            Log::info("No bale data available on {$shiftDate} (Device {$this->deviceId})");

            return;
        }

        // Where the machine ran on across the window's opening boundary with no reading to
        // show for it, the window starts from the reading before it rather than from its own
        // first one — otherwise everything made in between counts as the single unit that
        // `+1` stands for. A silence longer than a shift day is an outage {@see CounterSeed}
        // declines to carry, and there the window opens on its own first reading as before.
        $seed = CounterSeed::before(
            'pulse_device_data_fibre_bales',
            'bale_datetime',
            ['bales' => 'bales_counter'],
            [$this->deviceId],
            Carbon::parse($startTime),
            $anchor,
            'mod bales',
        )[$this->deviceId] ?? CounterSeed::none();

        $opening = $seed->for('bales');
        $expected = $entry->last_counter - ($opening ?? $entry->first_counter);

        if ($expected < 0) {
            $readings = DB::table('pulse_device_data_fibre_bales')
                ->select('bales_counter as counter')
                ->where('device_id', $this->deviceId)
                ->whereBetween('bale_datetime', [$startTime, $endTime])
                ->where('is_duplicate', 0)
                ->orderBy('bale_datetime')
                ->get();

            $prev = $opening;
            $expected = 0;

            foreach ($readings as $reading) {
                $curr = (int) $reading->counter;
                $expected += $this->calculateCounter($prev, $curr);
                $prev = ProductionCounter::carryForward($prev, $curr);
            }
        } elseif ($opening === null) {
            $expected = $expected + 1;
        }

        $this->createOrUpdateProduction($shiftDate, $startTime, $endTime, $expected, $existing);
    }

    protected function createOrUpdateProduction($shiftDate, $startTime, $endTime, $expected, $existing = null)
    {
        if ($existing) {
            $existing->update([
                'bales_expected' => $expected,
            ]);

            Log::info("Updated bales production record: Team {$this->teamId}, Date: {$shiftDate}, Bales Expected: {$expected}");
        } else {
            MiniBaleProduction::create([
                'team_id' => $this->teamId,
                'device_id' => $this->deviceId,
                'shift_date' => $shiftDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'bales_expected' => $expected,
            ]);

            Log::info("Created mini-bale production record: Team {$this->teamId}, Date: {$shiftDate}, Bales Expected: {$expected}");
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function calculateTotalShiftWindow(string $date, string $anchor): array
    {
        $start = Carbon::parse($date)->setTimeFromTimeString($anchor);
        $end = $start->copy()->addDay();

        return [$start->toDateTimeString(), $end->toDateTimeString()];
    }

    protected function calculateCounter(?int $previous, int $current): int
    {
        if (is_null($previous)) {
            return 1;
        }

        if ($current >= $previous) {
            return $current - $previous;
        }

        return 1;
    }

    protected function getAllDatesFromDate($date, $anchor)
    {
        if (empty($date)) {
            return [Carbon::yesterday()->format('Y-m-d')];
        }

        $start = Carbon::parse($date)->setTimeFromTimeString($anchor);
        $end = $start->copy()->addDay()->subSecond();

        $deviceData = DB::table('pulse_device_data_fibre_bales')
            ->select(DB::raw("
                DISTINCT CASE
                    WHEN TIME(bale_datetime) < '{$anchor}'
                    THEN DATE_SUB(DATE(bale_datetime), INTERVAL 1 DAY)
                    ELSE DATE(bale_datetime)
                END AS adjusted_date
            "))
            ->where('device_id', $this->deviceId)
            ->whereBetween('created_at', [$start, $end])
            ->pluck('adjusted_date');

        $dates = $deviceData->unique()->values()->all();
        if (! empty($dates)) {
            return $dates;
        }

        return [];
    }

    private function markAllDuplicateEntries()
    {
        DB::statement('
            WITH ordered AS (
                SELECT
                    id,
                    device_id,
                    bales_counter,
                    bale_datetime,
                    LAG(bales_counter) OVER (
                        PARTITION BY device_id
                        ORDER BY bale_datetime
                    ) AS prev_counter
                FROM pulse_device_data_fibre_bales
                WHERE device_id = ?
                  AND bale_datetime < NOW()
            )
            UPDATE pulse_device_data_fibre_bales r
            JOIN ordered o ON r.id = o.id
            SET r.is_duplicate = 1
            WHERE o.prev_counter = o.bales_counter
        ', [$this->deviceId]);

        Log::info("Marked duplicate entries using window function for device {$this->deviceId}");
    }

    private function deleteAllDuplicateEntries()
    {
        $deleted = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $this->deviceId)
            ->where('is_duplicate', 1)
            ->delete();

        if ($deleted > 0) {
            Log::info("Deleted {$deleted} previously marked duplicate entries for device {$this->deviceId}");
        }
    }

    public function getTeamId(): int
    {
        return $this->teamId;
    }
}
