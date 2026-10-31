<?php

namespace App\Jobs\Pulse;

use App\Models\Pulse\GrowbagMachine;
use App\Models\Pulse\Shift;
use App\Services\Pulse\GrowbagShiftDayTotals;
use App\Services\Pulse\ProductionSummaryService;
use App\Traits\AttributesReadingsToShifts;
use App\Traits\RecordsJobCompletion;
use App\Traits\RetriesWithJitteredBackoff;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDeviceDataForGrowBagsJob implements ShouldQueue
{
    use AttributesReadingsToShifts, Dispatchable, InteractsWithQueue, Queueable, RecordsJobCompletion, RetriesWithJitteredBackoff, SerializesModels;

    protected int $teamId;

    protected int $deviceId;

    protected array $dates;

    public function __construct(int $teamId, int $deviceId, array $dates = [])
    {
        $this->teamId = $teamId;
        $this->deviceId = $deviceId;
        $this->dates = $dates;
    }

    public function tags(): array
    {
        return ['team:'.$this->teamId];
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        $anchor = Shift::anchorForTeam($this->teamId);
        $shifts = Shift::where('team_id', $this->teamId)
            ->orderedByOffset($anchor)
            ->get();

        if ($shifts->isEmpty()) {
            Log::warning("ProcessDeviceDataForGrowBagsJob: No active shifts for team {$this->teamId}");

            return;
        }

        // Resolve dates to process — if single date or empty, get all dates from device data for that day (based on the team's shift-day anchor)
        $allDates = $this->dates;
        Log::info("Initial dates for Team {$this->teamId}: ".implode(', ', $allDates));
        if (count($this->dates) <= 1) {
            $allDates = $this->getAllDatesFromDate($this->dates, $anchor);
            Log::info("Resolved dates from device data for Team {$this->teamId}: ".implode(', ', $allDates));
        }

        $this->recompute($allDates);

        Log::info('ProcessDeviceDataForGrowBagsJob complete for team (ID: '.$this->teamId.') in '.(microtime(true) - $startTime).' seconds.');

        // Send summary notification only for yesterday
        $yesterday = Carbon::yesterday()->format('Y-m-d');
        if (count($this->dates) !== 1 || $this->dates[0] !== $yesterday) {
            Log::warning("Skipping summary notification for team {$this->teamId} on ".($this->dates[0] ?? 'unknown'));

            return;
        }

        // Prepare and send email and push notification
        $service = new ProductionSummaryService($this->teamId);
        $mainModuleSummary = $service->getGrowBagsSummaryByShift($yesterday);

        Log::info("Sending summary notification for Team {$this->teamId} on {$yesterday}: ".json_encode($mainModuleSummary));
        $subject = 'Yesterday ('.Carbon::parse($yesterday)->format(config('app.day_only_format', 'D')).')';
        $service->buildSummaryAndNotify($mainModuleSummary, $subject, $yesterday, null, 'growbags');

        Log::info("Sent summary notification for Team {$this->teamId} on {$yesterday}.");
    }

    /**
     * Rebuild the summary for the given shift dates and nothing else — see
     * {@see ProcessDeviceDataForFibreJob::recompute()} for why a backfill goes through here
     * rather than through `handle()`.
     *
     * @param  list<string>  $dates
     */
    public function recompute(array $dates): void
    {
        $anchor = Shift::anchorForTeam($this->teamId);

        $this->processGrowBagsData(
            $dates,
            Shift::where('team_id', $this->teamId)->orderedByOffset($anchor)->get(),
            $anchor
        );
    }

    protected function processGrowBagsData(array $dates, $shifts, string $anchor): void
    {
        // Get active machines for this device
        $machines = GrowbagMachine::where('team_id', $this->teamId)
            ->where('device_id', $this->deviceId)
            ->where('is_active', 1)
            ->get();

        if ($machines->isEmpty()) {
            Log::warning("ProcessDeviceDataForGrowBagsJob: No active machines for device {$this->deviceId}");

            return;
        }

        // Get EB price setting
        $ebPriceValue = DB::table('pulse_settings')
            ->where('key', 'RS_PER_EB_UNIT')
            ->where('team_id', $this->teamId)
            ->value('value') ?? 0;

        // The shift day split so that every minute of it belongs to a shift. A schedule can
        // leave an uncovered stretch between two shifts, and punches made in it are still
        // punches: they join whichever shift they sit closer to, exactly as the raw fallback
        // in GrowbagShiftDayTotals places them, so both count the same reading the same way.
        $active = $shifts->where('is_active', 1)->values();
        $segments = $this->shiftDaySegments($active->isNotEmpty() ? $active : $shifts, $anchor);

        if ($segments === []) {
            Log::warning("ProcessDeviceDataForGrowBagsJob: No shift schedule for team {$this->teamId}");

            return;
        }

        $inserts = [];

        Log::info("Processing grow bags data for Team {$this->teamId}, Device {$this->deviceId} for dates: ".implode(', ', $dates));
        foreach ($dates as $date) {
            $inserts = array_merge($inserts, $this->processDayData($date, $shifts, $segments, $anchor, $machines, $ebPriceValue));
        }

        if (! empty($inserts)) {
            DB::table('pulse_summary_growbags')->upsert(
                $inserts,
                ['team_id', 'growbag_machine_id', 'shift_date', 'shift_id'],
                ['bags_produced', 'machine_name', 'eb_units', 'eb_price', 'updated_at']
            );
        }
    }

    /**
     * One summary row per machine that produced in each shift of this shift date.
     *
     * Production is how far the device's counter travelled, each step credited to the machine
     * whose punch closed it and to the shift that punch fell in — the same measurement the
     * dashboard and the IoT Insights calendar read back, so what is written here is what they
     * print. The whole day is walked at once because the counter does not stop at a
     * changeover; see {@see GrowbagShiftDayTotals::punchesByShift()}.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @param  Collection<int, GrowbagMachine>  $machines
     * @return list<array<string, mixed>>
     */
    protected function processDayData(string $date, $shifts, array $segments, string $anchor, $machines, $ebPriceValue): array
    {
        $talliesByShift = app(GrowbagShiftDayTotals::class)
            ->punchesByShift($this->teamId, $date, $anchor, $segments, $this->deviceId);

        $this->stampShiftIds($date, $segments, $anchor);

        $dailyLimit = config('app.growbags.daily_production_limit', 9999);
        $rows = [];

        foreach ($shifts as $shift) {
            $tallies = $talliesByShift[$shift->id] ?? [];

            foreach ($machines as $machine) {
                $tally = $tallies[$machine->id] ?? null;

                if ($tally === null || $tally['punches'] <= 0) {
                    continue;
                }

                $punches = $tally['punches'];

                // Skip counter-reset days
                if ($punches > $dailyLimit) {
                    Log::warning("GrowBags counter reset detected for machine {$machine->machine_name} on {$date}", [
                        'punches' => $punches,
                        'limit' => $dailyLimit,
                    ]);

                    continue;
                }

                // Energy comes off the same walk as the punches, so a shift's units are what
                // the meter actually travelled while it held the floor.
                $ebUnits = $tally['eb'];

                $rows[] = [
                    'team_id' => $this->teamId,
                    'growbag_machine_id' => $machine->id,
                    'machine_name' => $machine->machine_name,
                    'shift_date' => $date,
                    'shift_id' => $shift->id,
                    'shift_name' => $shift->name,
                    'bags_produced' => $punches * max(1, (int) $machine->bags_per_punch),
                    'eb_units' => $ebUnits,
                    'eb_price' => round($ebUnits * $ebPriceValue, 1),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Freeze shift attribution on the raw readings, so what the summary counted is what the
     * screens see the reading belonging to. Stamped from the same segments the walk grouped
     * by, in one statement for the whole shift day.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     */
    protected function stampShiftIds(string $date, array $segments, string $anchor): void
    {
        $dayStart = Carbon::parse($date)->setTimeFromTimeString($anchor);

        DB::table('pulse_device_data_growbags')
            ->where('device_id', $this->deviceId)
            ->where('growbag_datetime', '>=', $dayStart)
            ->where('growbag_datetime', '<', $dayStart->copy()->addDay())
            ->update(['shift_id' => DB::raw($this->shiftIdCaseSql($segments, $anchor, 'growbag_datetime'))]);
    }

    protected function getAllDatesFromDate(array $dates, ?string $anchor): array
    {
        if (empty($dates)) {
            return [Carbon::yesterday()->format('Y-m-d')];
        }

        $start = Carbon::parse($dates[0])->setTimeFromTimeString($anchor);
        $end = $start->copy()->addDay()->subSecond();

        $deviceData = DB::table('pulse_device_data_growbags')
            ->select(DB::raw("
                DISTINCT CASE
                    WHEN TIME(growbag_datetime) < '{$anchor}'
                    THEN DATE_SUB(DATE(growbag_datetime), INTERVAL 1 DAY)
                    ELSE DATE(growbag_datetime)
                END AS adjusted_date
            "))
            ->where('device_id', $this->deviceId)
            ->whereBetween('created_at', [$start, $end])
            ->pluck('adjusted_date');

        $dates = $deviceData->unique()->values()->all();

        return ! empty($dates) ? $dates : [Carbon::yesterday()->format('Y-m-d')];
    }

    public function getTeamId(): int
    {
        return $this->teamId;
    }
}
