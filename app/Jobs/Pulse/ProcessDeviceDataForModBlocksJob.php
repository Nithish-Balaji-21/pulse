<?php

namespace App\Jobs\Pulse;

use App\Models\Pulse\PithBlocksProduction;
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

class ProcessDeviceDataForModBlocksJob implements ShouldQueue
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
        // Clear all previously marked duplicates
        $this->deleteAllDuplicateEntries();

        // Mark duplicates using SQL window function
        $this->markAllDuplicateEntries();

        $anchor = Shift::anchorForTeam($this->teamId);

        // Process production for all discovered dates
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
     * Rebuild the pith-block production for the given shift dates and nothing else.
     *
     * Skips the whole-history duplicate sweep `handle()` opens with, which a backfill would
     * otherwise repeat once per day of its range.
     *
     * @param  list<string>  $dates
     */
    public function recompute(array $dates): void
    {
        $anchor = Shift::anchorForTeam($this->teamId);

        // One row per production day (anchor → next-day anchor)
        foreach ($dates as $date) {
            $this->processDayProduction($date, $anchor);
        }
    }

    protected function processDayProduction(string $shiftDate, string $anchor): void
    {
        $existing = PithBlocksProduction::where('team_id', $this->teamId)
            ->where('shift_date', $shiftDate)
            ->first();

        // Respect manual edits if a record already exists
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
                    SELECT blocks_counter AS first_counter
                    FROM pulse_device_data_pith_blocks
                    WHERE device_id = ? 
                      AND block_datetime >= ? 
                      AND block_datetime <= ? 
                      AND is_duplicate = 0
                    ORDER BY block_datetime ASC
                    LIMIT 1
                ) AS f
            JOIN
                (
                    SELECT blocks_counter AS last_counter
                    FROM pulse_device_data_pith_blocks
                    WHERE device_id = ? 
                      AND block_datetime >= ? 
                      AND block_datetime <= ? 
                      AND is_duplicate = 0
                    ORDER BY block_datetime DESC
                    LIMIT 1
                ) AS l ON 1=1
        ', [
            $this->deviceId, $startTime, $endTime,
            $this->deviceId, $startTime, $endTime,
        ]);

        if (! $entry || $entry->first_counter === null) {
            Log::info("No data available on {$shiftDate} for device {$this->deviceId}");

            return;
        }

        // Where the machine ran on across the window's opening boundary with no reading to
        // show for it, the window starts from the reading before it rather than from its own
        // first one — otherwise everything made in between counts as the single unit that
        // `+1` stands for. A silence longer than a shift day is an outage {@see CounterSeed}
        // declines to carry, and there the window opens on its own first reading as before.
        $seed = CounterSeed::before(
            'pulse_device_data_pith_blocks',
            'block_datetime',
            ['blocks' => 'blocks_counter'],
            [$this->deviceId],
            Carbon::parse($startTime),
            $anchor,
            'mod blocks',
        )[$this->deviceId] ?? CounterSeed::none();

        $opening = $seed->for('blocks');
        $total = $entry->last_counter - ($opening ?? $entry->first_counter);

        if ($total < 0) {
            $readings = DB::table('pulse_device_data_pith_blocks')
                ->select('blocks_counter as counter')
                ->where('device_id', $this->deviceId)
                ->whereBetween('block_datetime', [$startTime, $endTime])
                ->where('is_duplicate', 0)
                ->orderBy('block_datetime')
                ->get();

            $prev = $opening;
            $total = 0;

            foreach ($readings as $reading) {
                $curr = (int) $reading->counter;
                $total += $this->calculateCounter($prev, $curr);
                $prev = ProductionCounter::carryForward($prev, $curr);
            }
        } elseif ($opening === null) {
            $total = $total + 1;
        }

        $this->createOrUpdateProduction($shiftDate, $startTime, $endTime, $total, $existing);
    }

    protected function createOrUpdateProduction($shiftDate, $startTime, $endTime, $total, $existing = null)
    {
        if ($existing) {
            $existing->update(['blocks_expected' => $total]);
            Log::info("Updated blocks production record: Team {$this->teamId}, Date: {$shiftDate}, Blocks Expected: {$total}");
        } else {
            PithBlocksProduction::create([
                'team_id' => $this->teamId,
                'device_id' => $this->deviceId,
                'shift_date' => $shiftDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'blocks_expected' => $total,
            ]);
            Log::info("Created blocks production record: Team {$this->teamId}, Date: {$shiftDate}, Blocks Expected: {$total}");
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

        $deviceData = DB::table('pulse_device_data_pith_blocks')
            ->select(DB::raw("
                DISTINCT CASE
                    WHEN TIME(block_datetime) < '{$anchor}'
                    THEN DATE_SUB(DATE(block_datetime), INTERVAL 1 DAY)
                    ELSE DATE(block_datetime)
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
                    blocks_counter,
                    block_datetime,
                    LAG(blocks_counter) OVER (
                        PARTITION BY device_id
                        ORDER BY block_datetime
                    ) AS prev_counter
                FROM pulse_device_data_pith_blocks
                WHERE device_id = ?
                  AND block_datetime < NOW()
            )
            UPDATE pulse_device_data_pith_blocks r
            JOIN ordered o ON r.id = o.id
            SET r.is_duplicate = 1
            WHERE o.prev_counter = o.blocks_counter
        ', [$this->deviceId]);

        Log::info("Marked duplicate entries using window function for device {$this->deviceId}");
    }

    private function deleteAllDuplicateEntries()
    {
        $deleted = DB::table('pulse_device_data_pith_blocks')
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
