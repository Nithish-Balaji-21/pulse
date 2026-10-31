<?php

namespace App\Services\Pulse;

use App\Models\Pulse\Shift;
use App\Support\Pulse\CounterSeed;
use App\Support\Pulse\IotLiveDay;
use App\Support\Pulse\ProductionCounter;
use App\Traits\AttributesReadingsToShifts;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What one fibre shift produced and weighed, as every screen must agree it happened.
 *
 * The IoT Insights calendars and the dashboard's day view used to answer this question
 * separately — one from the nightly summary, the other from raw device rows, each with its
 * own handling of the day still running — so the same shift could be printed two different
 * ways on two screens. Both now read from here.
 *
 * One rule decides where a figure comes from: **the nightly summary owns a shift once it
 * has written one, and raw readings fill every gap it leaves.** The gaps are real and each
 * used to show up as a discrepancy — the shift day still in progress, a day the job never
 * processed, and readings it never attributed to a shift. The day in progress is the one
 * exception in the other direction: a summary row written mid-day is partial by definition,
 * so raw readings outrank it until the day is over.
 */
class FibreShiftDayTotals
{
    use AttributesReadingsToShifts;

    /**
     * Every shift with figures between two shift dates, inclusive.
     *
     * @return Collection<int, object> {shift_date, shift_id, shift_name, bales_produced,
     *                                 bales_weighed, weight, eb_units, eb_price}
     */
    public function forRange(int $teamId, string $startDate, string $endDate): Collection
    {
        $anchor = Shift::anchorForTeam($teamId);
        $liveDay = IotLiveDay::forAnchor($anchor);

        $summary = $this->summaryRows($teamId, $startDate, $endDate);
        $raw = $this->rawRows($teamId, $anchor, $startDate, $endDate);

        // Raw outranks the summary only on the day still running; everywhere else it is
        // consulted for the shifts the summary has nothing to say about.
        $rows = $summary->reject(
            fn (object $row, string $key) => $row->shift_date === $liveDay && $raw->has($key)
        )->union($raw);

        return $rows->reject(fn (object $row) => $row->shift_date > $liveDay)
            ->sortBy([['shift_id', 'asc'], ['shift_date', 'asc']])
            ->values();
    }

    /**
     * Every shift with figures on one shift date.
     *
     * @return Collection<int, object>
     */
    public function forDate(int $teamId, string $date): Collection
    {
        return $this->forRange($teamId, $date, $date);
    }

    /**
     * The nightly job's figures, which have had duplicate readings and counter resets
     * corrected out of them.
     *
     * @return Collection<string, object> keyed "shift date|shift id"
     */
    private function summaryRows(int $teamId, string $startDate, string $endDate): Collection
    {
        return DB::table('pulse_summary_fibre_mega_bales')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->groupBy('shift_date', 'shift_id', 'shift_name')
            ->select(
                'shift_date',
                'shift_id',
                'shift_name',
                DB::raw('SUM(bales_produced) as bales_produced'),
                DB::raw('COALESCE(SUM(bales_weighed), 0) as bales_weighed'),
                DB::raw('COALESCE(SUM(weight), 0) as weight'),
                DB::raw('COALESCE(SUM(eb_units), 0) as eb_units'),
                DB::raw('COALESCE(SUM(eb_price), 0) as eb_price')
            )
            ->get()
            ->mapWithKeys(fn ($row) => [$this->key($row->shift_date, (int) $row->shift_id) => (object) [
                'shift_date' => $row->shift_date,
                'shift_id' => (int) $row->shift_id,
                'shift_name' => $row->shift_name,
                'bales_produced' => (int) $row->bales_produced,
                'bales_weighed' => (int) $row->bales_weighed,
                'weight' => (float) $row->weight,
                'eb_units' => (float) $row->eb_units,
                'eb_price' => (float) $row->eb_price,
            ]]);
    }

    /**
     * The same figures counted from raw device readings, for the shifts the summary has
     * not covered.
     *
     * A reading keeps the shift stamped on it where it has one, so it can only ever land on
     * the shift whose summary row would supersede it — a shift since deactivated included.
     * The job stamps that column when it summarises, so the readings this fallback exists to
     * cover are exactly the unstamped ones, and those are placed by their time of day. The
     * segments span the whole day, so a reading inside a shift's window lands on the same
     * shift either way.
     *
     * @return Collection<string, object> keyed "shift date|shift id"
     */
    private function rawRows(int $teamId, string $anchor, string $startDate, string $endDate): Collection
    {
        $segments = $this->shiftDaySegments(Shift::activeForTeam($teamId)->get(), $anchor);

        if ($segments === []) {
            return collect();
        }

        $deviceIds = DB::table('pulse_devices')->where('team_id', $teamId)->pluck('device_id');

        if ($deviceIds->isEmpty()) {
            return collect();
        }

        $from = Carbon::parse($startDate)->setTimeFromTimeString($anchor);
        $until = Carbon::parse($endDate)->addDay()->setTimeFromTimeString($anchor);
        // Named from every shift, not just the active ones: a day summarised before a shift
        // was retired still has readings that belong to it.
        $names = Shift::where('team_id', $teamId)->pluck('name', 'id');
        $ebRate = (float) DB::table('pulse_settings')
            ->where('team_id', $teamId)
            ->where('key', 'RS_PER_EB_UNIT')
            ->value('value');

        $produced = $this->rawProduction($deviceIds, $anchor, $segments, $from, $until);
        $weighed = $this->rawWeighings($deviceIds, $anchor, $segments, $from, $until);

        return $produced->keys()->merge($weighed->keys())->unique()
            ->mapWithKeys(function (string $key) use ($produced, $weighed, $names, $ebRate) {
                $production = $produced->get($key);
                $weighing = $weighed->get($key);
                [$shiftDate, $shiftId] = explode('|', $key);
                $ebUnits = (float) ($production->eb_units ?? 0);

                return [$key => (object) [
                    'shift_date' => $shiftDate,
                    'shift_id' => (int) $shiftId,
                    'shift_name' => $names[(int) $shiftId] ?? '',
                    'bales_produced' => (int) ($production->bales_produced ?? 0),
                    'bales_weighed' => (int) ($weighing->bales_weighed ?? 0),
                    'weight' => (float) ($weighing->weight ?? 0),
                    'eb_units' => $ebUnits,
                    'eb_price' => round($ebUnits * $ebRate, 1),
                ]];
            });
    }

    /**
     * Bales produced per shift, as the distance the machine's counter travelled — the same
     * measure the nightly job settles on, so a shift does not change size when the job
     * later replaces this figure with its own.
     *
     * Four things have to happen in order, which is why this is built up in layers rather
     * than written as one query:
     *
     * 1. Each reading is paired with the one before it on the same device.
     * 2. A reading that fell to at most {@see ProductionCounter::RESET_RATIO} of its
     *    predecessor is a machine restarting; a running count of those splits each device's
     *    readings into runs, so the counter's history restarts where the machine did.
     * 3. Within a run, a reading is measured against the *highest* the counter has reached,
     *    not the reading immediately before it — uploads do not always arrive in the order
     *    the bales were made, and measuring against a straggler charges the climb twice.
     * 4. A predecessor older than {@see CounterSeed}'s bound is masked away, so the reading
     *    scores as the first of its day exactly as an unseeded walk would.
     *
     * The range is widened by a day at the bottom so the first day in it has a predecessor to
     * carry from, and those extra readings are dropped before the totals are taken.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @return Collection<string, object>
     */
    private function rawProduction($deviceIds, string $anchor, array $segments, Carbon $from, Carbon $until): Collection
    {
        $shiftDate = $this->shiftDateSql('bale_datetime', $anchor);
        $shiftId = $this->shiftIdSql($segments, $anchor, 'bale_datetime');
        $ordered = 'PARTITION BY device_id ORDER BY bale_datetime, id';
        $ratio = ProductionCounter::RESET_RATIO;

        // 1. each reading and the one before it
        $paired = DB::table('pulse_device_data_fibre_bales')
            ->whereIn('device_id', $deviceIds)
            ->where('is_duplicate', 0)
            ->where('bale_datetime', '>=', $from->copy()->subDay())
            ->where('bale_datetime', '<', $until)
            ->selectRaw(
                "device_id,
                 id,
                 {$shiftDate} as shift_date,
                 {$shiftId} as reading_shift_id,
                 bale_datetime as at,
                 bales_counter,
                 eb_units_counter,
                 LAG(bale_datetime) OVER ({$ordered}) as prev_at,
                 LAG(bales_counter) OVER ({$ordered}) as prev_bales,
                 LAG(eb_units_counter) OVER ({$ordered}) as prev_eb"
            );

        // 2. a restart opens a new run of the counter
        $runs = DB::query()
            ->fromSub($paired, 'paired')
            ->selectRaw(
                "device_id, id, shift_date, reading_shift_id, at, prev_at, bales_counter, eb_units_counter,
                 SUM(CASE WHEN prev_bales IS NOT NULL AND bales_counter <= prev_bales * {$ratio} THEN 1 ELSE 0 END)
                     OVER (PARTITION BY device_id ORDER BY at, id ROWS UNBOUNDED PRECEDING) as bales_run,
                 SUM(CASE WHEN prev_eb IS NOT NULL AND eb_units_counter <= prev_eb * {$ratio} THEN 1 ELSE 0 END)
                     OVER (PARTITION BY device_id ORDER BY at, id ROWS UNBOUNDED PRECEDING) as eb_run"
            );

        // 3. within a run, measure against the highest the counter has reached
        $highWater = DB::query()
            ->fromSub($runs, 'runs')
            ->selectRaw(
                'shift_date, reading_shift_id, at, prev_at, bales_counter, eb_units_counter,
                 MAX(bales_counter) OVER (PARTITION BY device_id, bales_run ORDER BY at, id
                     ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) as prev_bales,
                 MAX(eb_units_counter) OVER (PARTITION BY device_id, eb_run ORDER BY at, id
                     ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) as prev_eb'
            );

        // 4. apply the carry-over bound, and drop the readings pulled in from before the
        //    range: they are here to be a predecessor, never to report a day of their own
        $carries = CounterSeed::carriesSql('at', 'prev_at', $anchor);

        $bounded = DB::query()
            ->fromSub($highWater, 'high_water')
            ->selectRaw(
                "shift_date,
                 reading_shift_id,
                 bales_counter,
                 eb_units_counter,
                 CASE WHEN {$carries} THEN prev_bales END as prev_bales,
                 CASE WHEN {$carries} THEN prev_eb END as prev_eb"
            )
            ->where('at', '>=', $from);

        $baleStep = ProductionCounter::incrementSql('bales_counter', 'prev_bales');
        $ebStep = ProductionCounter::incrementSql('eb_units_counter', 'prev_eb');

        return DB::query()
            ->fromSub($bounded, 'steps')
            ->selectRaw(
                "shift_date,
                 reading_shift_id,
                 SUM({$baleStep}) as bales_produced,
                 SUM({$ebStep}) as eb_units"
            )
            ->groupBy('shift_date', 'reading_shift_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$this->key($row->shift_date, (int) $row->reading_shift_id) => (object) [
                'bales_produced' => (int) $row->bales_produced,
                'eb_units' => (int) $row->eb_units,
            ]]);
    }

    /**
     * Bales weighed per shift. Every weighing is its own reading, so these are counted
     * rather than spanned.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @return Collection<string, object>
     */
    private function rawWeighings($deviceIds, string $anchor, array $segments, Carbon $from, Carbon $until): Collection
    {
        $shiftDate = $this->shiftDateSql('bale_weight_datetime', $anchor);
        $shiftId = $this->shiftIdSql($segments, $anchor, 'bale_weight_datetime');

        return DB::table('pulse_device_data_fibre_mega_bales_weight')
            ->whereIn('device_id', $deviceIds)
            ->where('bale_weight_datetime', '>=', $from)
            ->where('bale_weight_datetime', '<', $until)
            ->selectRaw(
                "{$shiftDate} as shift_date,
                 {$shiftId} as shift_id,
                 COUNT(*) as bales_weighed,
                 COALESCE(SUM(bale_weight), 0) as weight"
            )
            ->groupBy(DB::raw($shiftDate), DB::raw($shiftId))
            ->get()
            ->mapWithKeys(fn ($row) => [$this->key($row->shift_date, (int) $row->shift_id) => (object) [
                'bales_weighed' => (int) $row->bales_weighed,
                'weight' => (float) $row->weight,
            ]]);
    }

    /**
     * The shift a reading belongs to: the one stamped on it by the nightly job, or — for the
     * unstamped readings this fallback is for — the one its time of day falls in.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     */
    private function shiftIdSql(array $segments, string $anchor, string $column): string
    {
        return 'COALESCE(shift_id, '.$this->shiftIdCaseSql($segments, $anchor, $column).')';
    }

    /**
     * The shift date a reading belongs to: a night shift running past midnight stays on
     * the day it started, which is how the summary tables key it.
     */
    private function shiftDateSql(string $column, string $anchor): string
    {
        return Shift::shiftDateSqlFor($column, $anchor);
    }

    private function key(string $shiftDate, int $shiftId): string
    {
        return $shiftDate.'|'.$shiftId;
    }
}
