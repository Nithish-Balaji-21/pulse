<?php

namespace App\Jobs\Pulse;

use App\Models\Pulse\Shift;
use App\Services\Pulse\ProductionSummaryService;
use App\Support\Pulse\CounterSeed;
use App\Support\Pulse\ProductionCounter;
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

class ProcessDeviceDataForFibreJob implements ShouldQueue
{
    use AttributesReadingsToShifts, Dispatchable, InteractsWithQueue, Queueable, RecordsJobCompletion, RetriesWithJitteredBackoff, SerializesModels;

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
        $startTime = microtime(true);

        // Fetch the shifts for the team, ordered within the shift day
        $anchor = Shift::anchorForTeam($this->teamId);
        $shifts = Shift::where('team_id', $this->teamId)
            ->orderedByOffset($anchor)
            ->get();

        // If single day, then get all the respective dates from the given date
        $allDates = $this->dates;
        if (count($this->dates) <= 1) {
            $allDates = $this->getAllDatesFromDate($this->dates, $anchor);
        }

        $this->recompute($allDates);

        Log::info('Fibre 120 kg device data shift processing complete for team (ID: '.$this->teamId.') in '.(microtime(true) - $startTime).' seconds.');

        $yesterday = Carbon::yesterday()->format('Y-m-d');
        if (count($this->dates) !== 1 || $this->dates[0] !== $yesterday) {
            Log::warning("Skipping summary notification for {$this->teamId} on ".($this->dates[0] ?? 'no date').' as it is not yesterday.');

            return;
        }

        // Prepare and send email and push notification
        $service = new ProductionSummaryService($this->teamId);
        $mainModuleSummary = $service->getFibreBalesSummaryByShift($yesterday);

        $subject = 'Yesterday ('.Carbon::parse($yesterday)->format(config('app.day_only_format', 'D')).')';
        $service->buildSummaryAndNotify($mainModuleSummary, $subject, $yesterday, null, 'fibre');

        Log::info("Sent summary notification for Team {$this->teamId} on {$yesterday}.");
    }

    /**
     * Rebuild the summary for the given shift dates and nothing else.
     *
     * The whole of what the nightly run computes, without the date discovery it does from
     * `created_at` or the summary notification it sends afterwards — so a backfill recomputes
     * exactly what a night would have, for the dates it was asked for, without mailing the
     * factory a second copy of a week-old day.
     *
     * @param  list<string>  $dates
     */
    public function recompute(array $dates): void
    {
        $anchor = Shift::anchorForTeam($this->teamId);

        $this->process120KgBaleData(
            $dates,
            Shift::where('team_id', $this->teamId)->orderedByOffset($anchor)->get(),
            $anchor
        );
    }

    protected function process120KgBaleData($dates, $shifts, string $anchor)
    {
        // Fetch the EB price value from settings
        $ebPriceValue = DB::table('pulse_settings')
            ->where('key', 'RS_PER_EB_UNIT')
            ->where('team_id', $this->teamId)
            ->value('value');

        // The shift day split so that every minute of it belongs to a shift. A schedule can
        // leave an uncovered stretch between two shifts, and bales made in it are still
        // bales: they join whichever shift they sit closer to, exactly as the raw fallback
        // in FibreShiftDayTotals places them, so both count the same reading the same way.
        $active = $shifts->where('is_active', 1)->values();
        $segments = $this->shiftDaySegments($active->isNotEmpty() ? $active : $shifts, $anchor);

        if ($segments === []) {
            return;
        }

        $inserts = [];
        foreach ($dates as $date) {
            $inserts = array_merge($inserts, $this->processDayData($date, $shifts, $segments, $anchor, $ebPriceValue));
        }

        // upsert on the unique key, updating the metrics if the row already exists
        if (! empty($inserts)) {
            DB::table('pulse_summary_fibre_mega_bales')
                ->upsert(
                    $inserts,
                    ['team_id', 'device_id', 'shift_date', 'shift_id'],   // uniqueBy
                    ['bales_produced', 'bales_weighed', 'weight', 'eb_units', 'eb_price'] // update columns
                );
        }
    }

    /**
     * One summary row per shift that produced or weighed anything on this shift date.
     *
     * The whole shift day is walked in one pass rather than each shift being measured on its
     * own, because the counter does not stop between shifts. Measuring a shift as the span of
     * its own readings — max minus min, plus one for the bale that opened it — credits it a
     * single bale for everything made since the previous shift's last reading, so a machine
     * that ran through a changeover, or an upload that went missing across one, lost every
     * bale but one from the summary. Walking the day gives that stretch to the shift it ended
     * in, and the shifts still add up to the day.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @return list<array<string, mixed>>
     */
    protected function processDayData(string $date, Collection $shifts, array $segments, string $anchor, $ebPriceValue): array
    {
        $dayStart = Carbon::parse($date.' '.$anchor);
        $dayEnd = $dayStart->copy()->addDay();

        $produced = $this->balesProducedByShift($dayStart, $dayEnd, $segments, $anchor);
        $weighed = $this->balesWeighedByShift($dayStart, $dayEnd, $segments, $anchor);

        $this->stampShiftIds('pulse_device_data_fibre_bales', 'bale_datetime', $dayStart, $dayEnd, $segments, $anchor);
        $this->stampShiftIds('pulse_device_data_fibre_mega_bales_weight', 'bale_weight_datetime', $dayStart, $dayEnd, $segments, $anchor);

        $rows = [];

        foreach ($shifts as $shift) {
            $production = $produced[$shift->id] ?? null;
            $weighing = $weighed[$shift->id] ?? null;

            // Nothing reached this shift — leave any existing row alone rather than
            // overwriting it with zeroes.
            if ($production === null && $weighing === null) {
                continue;
            }

            $totalEbUnits = $production['eb_units'] ?? 0;

            $rows[] = [
                'team_id' => $this->teamId,
                'device_id' => $this->deviceId,
                'shift_date' => $date,
                'shift_id' => $shift->id,
                'shift_name' => $shift->name,
                'bales_produced' => $production['bales_produced'] ?? 0,
                'bales_weighed' => $weighing['bales_weighed'] ?? 0,
                'weight' => $weighing['weight'] ?? 0,
                'eb_units' => $totalEbUnits,
                'eb_price' => round($totalEbUnits * $ebPriceValue, 1),
            ];
        }

        return $rows;
    }

    /**
     * Bales and EB units per shift for one shift day, as the distance the machine's counters
     * travelled while each shift held the floor. A counter that has gone backwards has reset,
     * which {@see ProductionCounter::increment()} scores as one bale rather than a negative
     * jump — the reset handling that used to need a separate slow path.
     *
     * The walk is seeded from the last reading before the day, so a machine that ran through
     * the small hours while its uploads failed keeps those bales instead of being credited a
     * single one for all of them. {@see CounterSeed} decides when a seed is too old to carry.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @return array<int, array{bales_produced: int, eb_units: int}>
     */
    protected function balesProducedByShift(Carbon $dayStart, Carbon $dayEnd, array $segments, string $anchor): array
    {
        $readings = DB::table('pulse_device_data_fibre_bales')
            ->select('bale_datetime', 'bales_counter', 'eb_units_counter')
            ->where('device_id', $this->deviceId)
            ->where('is_duplicate', 0)
            ->where('bale_datetime', '>=', $dayStart)
            ->where('bale_datetime', '<', $dayEnd)
            ->orderBy('bale_datetime')
            ->orderBy('id')
            ->get();

        if ($readings->isEmpty()) {
            return [];
        }

        $shiftOf = fn (object $reading): ?int => $this->shiftIdForMoment($segments, $anchor, $reading->bale_datetime);

        // Both counters seed off the same reading, in one lookup: seeded separately they
        // could land on different rows and disagree about where the day began.
        $seed = CounterSeed::before(
            'pulse_device_data_fibre_bales',
            'bale_datetime',
            ['bales' => 'bales_counter', 'eb' => 'eb_units_counter'],
            [$this->deviceId],
            $dayStart,
            $anchor,
            'fibre',
        )[$this->deviceId] ?? CounterSeed::none();

        $bales = ProductionCounter::bucketed(
            $readings, $shiftOf, fn (object $reading) => (int) $reading->bales_counter, previous: $seed->for('bales')
        );
        $ebUnits = ProductionCounter::bucketed(
            $readings, $shiftOf, fn (object $reading) => (int) $reading->eb_units_counter, previous: $seed->for('eb')
        );

        $totals = [];
        foreach ($bales as $shiftId => $count) {
            $totals[(int) $shiftId] = [
                'bales_produced' => $count,
                'eb_units' => $ebUnits[$shiftId] ?? 0,
            ];
        }

        return $totals;
    }

    /**
     * Bales weighed and their weight per shift. Every weighing is its own reading, so these
     * are counted rather than spanned, and nothing is lost between shifts.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @return array<int, array{bales_weighed: int, weight: float}>
     */
    protected function balesWeighedByShift(Carbon $dayStart, Carbon $dayEnd, array $segments, string $anchor): array
    {
        $weighings = DB::table('pulse_device_data_fibre_mega_bales_weight')
            ->select('bale_weight_datetime', 'bale_weight')
            ->where('device_id', $this->deviceId)
            ->where('bale_weight_datetime', '>=', $dayStart)
            ->where('bale_weight_datetime', '<', $dayEnd)
            ->get();

        if ($weighings->isEmpty()) {
            return [];
        }

        return $weighings
            ->groupBy(fn (object $weighing): ?int => $this->shiftIdForMoment($segments, $anchor, $weighing->bale_weight_datetime))
            ->mapWithKeys(fn (Collection $rows, $shiftId) => [(int) $shiftId => [
                'bales_weighed' => $rows->count(),
                'weight' => round($rows->sum('bale_weight')),
            ]])
            ->all();
    }

    /**
     * Freeze shift attribution on the raw readings, so what the summary counted is what the
     * screens see the reading belonging to. Stamped from the same segments the walk grouped
     * by, in one statement for the whole shift day.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     */
    protected function stampShiftIds(string $table, string $column, Carbon $dayStart, Carbon $dayEnd, array $segments, string $anchor): void
    {
        DB::table($table)
            ->where('device_id', $this->deviceId)
            ->where($column, '>=', $dayStart)
            ->where($column, '<', $dayEnd)
            ->update(['shift_id' => DB::raw($this->shiftIdCaseSql($segments, $anchor, $column))]);
    }

    protected function getAllDatesFromDate($dates, $anchor)
    {
        // If no dates are provided, return yesterday's date
        if (empty($dates)) {
            return [Carbon::yesterday()->format('Y-m-d')];
        }

        $start = Carbon::parse($dates[0])->setTimeFromTimeString($anchor);
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

        return [Carbon::yesterday()->format('Y-m-d')];
    }

    public function getTeamId(): int
    {
        return $this->teamId;
    }
}
