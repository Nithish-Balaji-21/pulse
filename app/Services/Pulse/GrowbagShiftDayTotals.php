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
 * What one growbag shift produced, as every screen must agree it happened.
 *
 * The counterpart of {@see FibreShiftDayTotals}, and it follows the same rule: **the nightly
 * summary owns a shift once it has written one, and raw readings fill every gap it leaves**,
 * except on the shift day still in progress, where a partial summary row is outranked by the
 * readings themselves.
 *
 * Growbags differ from fibre in two ways that this class exists to get right.
 *
 * A punch is not a bag. Each machine turns one punch into `bags_per_punch` bags, and every
 * figure the pages print is in bags, so punches are multiplied by the machine that made them
 * — never by a single factor for the whole team.
 *
 * The counter, though, is the device's, not the machine's: one sequence covers every machine
 * wired to it. So production is measured by how far that counter travelled and each step is
 * credited to the machine whose punch closed it, which is the only attribution that both
 * survives a lost upload and still adds up to the device's own total.
 */
class GrowbagShiftDayTotals
{
    use AttributesReadingsToShifts;

    /**
     * Every shift with figures between two shift dates, inclusive.
     *
     * @return Collection<int, object> {shift_date, shift_id, shift_name, machine_id,
     *                                 machine_name, bags_produced, eb_units, eb_price}
     */
    public function forRange(int $teamId, string $startDate, string $endDate, ?int $machineId = null): Collection
    {
        $anchor = Shift::anchorForTeam($teamId);
        $liveDay = IotLiveDay::forAnchor($anchor);

        // Ownership is settled a shift at a time, not a machine at a time: the job writes
        // every machine that ran in a shift in one pass, so a shift the summary knows about
        // is one it knows about in full — while the machine ids raw readings resolve to may
        // not line up with it at all, on a device whose machines nobody has configured.
        $summary = $this->summaryRows($teamId, $startDate, $endDate, $machineId)->groupBy($this->shiftKey(...));
        $raw = $this->rawShiftRows($teamId, $anchor, $startDate, $endDate, $machineId)->groupBy($this->shiftKey(...));

        // Raw outranks the summary only on the day still running; everywhere else it is
        // consulted for the shifts the summary has nothing to say about.
        $rows = $summary->reject(
            fn (Collection $shift, string $key) => $shift->first()->shift_date === $liveDay && $raw->has($key)
        )->union($raw)->flatten();

        return $rows->reject(fn (object $row) => $row->shift_date > $liveDay)
            ->sortBy([['shift_id', 'asc'], ['shift_date', 'asc'], ['machine_name', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    public function forDate(int $teamId, string $date, ?int $machineId = null): Collection
    {
        return $this->forRange($teamId, $date, $date, $machineId);
    }

    /**
     * Bags produced in each hour of one shift day, keyed by the hour's start.
     *
     * Counted by the same walk as the shift figures above, so the bars and the totals beside
     * them are the same measurement at two grains — no reconciling one to the other. It is
     * the same walk as {@see hourlyMachineBagsForDay()} too, summed: an hour's bar is
     * therefore exactly its machines' lines added up, whatever either is read from.
     *
     * @return Collection<string, int>
     */
    public function hourlyBagsForDay(int $teamId, string $date, ?int $machineId = null): Collection
    {
        return $this->hourlyMachineBagsForDay($teamId, $date, $machineId)->map(
            fn (array $perShift) => collect($perShift)->sum(
                fn (array $perMachine) => collect($perMachine)->sum('growbags')
            )
        );
    }

    /**
     * The same hours, split by the shift the punch fell in and the machine that made it —
     * what the day view's "Growbags by Machines" lines are drawn from.
     *
     * The walk already tallies per machine; this is that tally kept rather than summed away.
     * Hours in which a machine made nothing are absent rather than zero, so the caller can
     * tell a machine that was idle from one that is not configured.
     *
     * @return Collection<string, array<int, array<int, array{machine_name: string, growbags: int,
     *                                eb: float}>>> keyed by hour start, then shift id, then machine id
     */
    public function hourlyMachineBagsForDay(int $teamId, string $date, ?int $machineId = null): Collection
    {
        $anchor = Shift::anchorForTeam($teamId);
        $startOfDay = Carbon::parse($date)->setTimeFromTimeString($anchor);
        $machines = $this->machinesByField($teamId, $machineId);
        $segments = $this->shiftDaySegments(Shift::activeForTeam($teamId)->get(), $anchor);

        $walked = $this->walk(
            $this->readings($teamId, $startOfDay, $startOfDay->copy()->addDay()),
            $machines,
            function (object $reading) use ($startOfDay, $anchor, $segments): string {
                $slot = (int) floor($startOfDay->diffInMinutes(Carbon::parse($reading->growbag_datetime), false) / 60);

                return $startOfDay->copy()->addHours($slot)->format('Y-m-d H:i')
                    .'|'.($segments === [] ? 0 : $this->shiftIdOf($reading, $anchor, $segments));
            },
            $this->punchSeedsBefore($teamId, $startOfDay, $anchor, $machines),
            $this->ebSeedsBefore($teamId, $startOfDay, $anchor)
        );

        $hours = [];

        foreach ($walked as $key => $perMachine) {
            [$hour, $shiftId] = explode('|', $key);

            foreach ($perMachine as $id => $tally) {
                $hours[$hour][(int) $shiftId][(int) $id] = [
                    'machine_name' => $tally['machine_name'],
                    'growbags' => $tally['punches'] * $tally['bags_per_punch'],
                    'eb' => $tally['eb'],
                ];
            }
        }

        return collect($hours);
    }

    /**
     * Punches and energy per shift and machine across one shift day, for the nightly job to
     * summarise. It runs one device at a time, so it names the device whose counter it is
     * summarising.
     *
     * The whole day is walked in one pass rather than a shift at a time, because the counter
     * does not stop at a changeover. Measured a shift at a time, the first punch of each shift
     * was worth one bag no matter how far the counter had moved since the previous shift's
     * last reading, so a machine that ran through a changeover — or an upload lost across one
     * — lost everything but one punch. Walking the day gives that stretch to the shift the
     * punch that closed it fell in, and the shifts still add up to the device's own travel.
     *
     * The job settles what this class later reads back, so both measure production the same
     * way — the job cannot write a figure the pages would then count differently.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @return array<int, array<int, array{punches: int, bags_per_punch: int, machine_name: string,
     *                                     eb: float, readings: int}>>
     *                                     keyed by shift id, then machine id
     */
    public function punchesByShift(int $teamId, string $date, string $anchor, array $segments, ?int $deviceId = null): array
    {
        if ($segments === []) {
            return [];
        }

        $startOfDay = Carbon::parse($date)->setTimeFromTimeString($anchor);

        // Placed by time of day alone, never by the shift_id a previous run stamped: what the
        // job writes must depend on the readings and the schedule, not on its own history.
        $machines = $this->machinesByField($teamId, null, $deviceId);

        return $this->walk(
            $this->readings($teamId, $startOfDay, $startOfDay->copy()->addDay(), $deviceId),
            $machines,
            fn (object $reading) => (string) $this->shiftIdForMoment($segments, $anchor, $reading->growbag_datetime),
            $this->punchSeedsBefore($teamId, $startOfDay, $anchor, $machines, $deviceId),
            $this->ebSeedsBefore($teamId, $startOfDay, $anchor, $deviceId)
        );
    }

    /**
     * The nightly job's figures, already in bags.
     *
     * @return Collection<string, object> keyed "shift date|shift id|machine id"
     */
    private function summaryRows(int $teamId, string $startDate, string $endDate, ?int $machineId): Collection
    {
        return DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->when($machineId, fn ($query) => $query->where('growbag_machine_id', $machineId))
            ->groupBy('shift_date', 'shift_id', 'shift_name', 'growbag_machine_id', 'machine_name')
            ->select(
                'shift_date',
                'shift_id',
                'shift_name',
                'growbag_machine_id',
                'machine_name',
                DB::raw('SUM(COALESCE(bags_produced, 0)) as bags_produced'),
                DB::raw('COALESCE(SUM(eb_units), 0) as eb_units'),
                DB::raw('COALESCE(SUM(eb_price), 0) as eb_price')
            )
            ->get()
            ->mapWithKeys(fn ($row) => [$this->key($row->shift_date, (int) $row->shift_id, (int) $row->growbag_machine_id) => (object) [
                'shift_date' => $row->shift_date,
                'shift_id' => (int) $row->shift_id,
                'shift_name' => $row->shift_name,
                'machine_id' => (int) $row->growbag_machine_id,
                'machine_name' => $row->machine_name,
                'bags_produced' => (int) $row->bags_produced,
                'eb_units' => (float) $row->eb_units,
                'eb_price' => (float) $row->eb_price,
            ]]);
    }

    /**
     * The same figures counted from raw readings, for the shifts the summary has not covered.
     *
     * @return Collection<string, object> keyed "shift date|shift id|machine id"
     */
    private function rawShiftRows(int $teamId, string $anchor, string $startDate, string $endDate, ?int $machineId): Collection
    {
        $machines = $this->machinesByField($teamId, $machineId);
        $segments = $this->shiftDaySegments(Shift::activeForTeam($teamId)->get(), $anchor);

        if ($segments === []) {
            return collect();
        }

        $from = Carbon::parse($startDate)->setTimeFromTimeString($anchor);
        $until = Carbon::parse($endDate)->addDay()->setTimeFromTimeString($anchor);

        $shiftNames = Shift::where('team_id', $teamId)->pluck('name', 'id');
        $ebRate = (float) DB::table('pulse_settings')
            ->where('team_id', $teamId)
            ->where('key', 'RS_PER_EB_UNIT')
            ->value('value');

        $walked = $this->walk(
            $this->readings($teamId, $from, $until),
            $machines,
            fn (object $reading) => $this->shiftDateOf($reading->growbag_datetime, $anchor)
                .'|'.$this->shiftIdOf($reading, $anchor, $segments),
            $this->punchSeedsBefore($teamId, $from, $anchor, $machines),
            $this->ebSeedsBefore($teamId, $from, $anchor)
        );

        $rows = collect();

        foreach ($walked as $shiftKey => $perMachine) {
            [$shiftDate, $shiftId] = explode('|', $shiftKey);

            foreach ($perMachine as $machineId => $tally) {
                $ebUnits = $tally['eb'];

                $rows[$this->key($shiftDate, (int) $shiftId, (int) $machineId)] = (object) [
                    'shift_date' => $shiftDate,
                    'shift_id' => (int) $shiftId,
                    'shift_name' => $shiftNames[(int) $shiftId] ?? '',
                    'machine_id' => (int) $machineId,
                    'machine_name' => $tally['machine_name'],
                    'bags_produced' => $tally['punches'] * $tally['bags_per_punch'],
                    'eb_units' => (float) $ebUnits,
                    'eb_price' => round($ebUnits * $ebRate, 1),
                ];
            }
        }

        return $rows;
    }

    /**
     * Walks a device's readings in time order, tallying what each machine produced under
     * whatever key the caller groups by — an hour, or a shift.
     *
     * **A factory runs one device, and the device carries one counter per machine.** Each
     * `field_id` is its own sequence, climbing by one for every punch that machine makes, and
     * what a machine produced is how far its own counter travelled times the bags one of its
     * punches makes. The counters are therefore walked one machine at a time: differencing a
     * reading from D31 against the reading from D32 that happens to precede it in time
     * measures nothing, and scores every machine but the highest-numbered at zero.
     *
     * Energy is the exception, and is walked per device: `eb_units_counter` is the factory's
     * one EB panel, reported on every row whatever machine sent it. Each step of it is
     * credited to the machine that closed it, so the machines still sum to the panel's own
     * travel — including the steps closed by a reading whose punches cannot be credited.
     *
     * A seed carries each walk on from the last reading before the window, so production made
     * while the device was silent across the window's opening boundary is credited rather
     * than scored as a single punch. {@see CounterSeed} decides when a seed may carry.
     *
     * A reading whose field_id names no machine is credited to the only machine on the
     * device, where there is only one — it can have come from nowhere else, and dropping it
     * would lose real production over a stray or unstamped tag. Where several machines share
     * the device such a punch is genuinely unattributable, so it is passed over. A team with
     * no machines configured at all is the last case: there every punch is worth one bag, so
     * a factory still setting up sees its production rather than nothing.
     *
     * @param  Collection<int, object>  $readings  ordered by time, ascending
     * @param  array<string, array{id: int, name: string, bags_per_punch: int}>  $machines
     * @param  array<string, CounterSeed>  $punchSeeds  keyed "deviceId|fieldId"
     * @param  array<int, CounterSeed>  $ebSeeds  keyed by device id
     * @return array<string, array<int, array{punches: int, bags_per_punch: int, machine_name: string,
     *                                        eb: float, readings: int}>>
     */
    private function walk(
        Collection $readings,
        array $machines,
        callable $keyFor,
        array $punchSeeds = [],
        array $ebSeeds = [],
    ): array {
        $tallies = [];
        $unconfigured = ['id' => 0, 'name' => '', 'bags_per_punch' => 1];
        $soleMachine = count($machines) === 1 ? reset($machines) : null;

        foreach ($readings->groupBy('device_id') as $deviceId => $deviceReadings) {
            $previousEb = ($ebSeeds[(int) $deviceId] ?? CounterSeed::none())->floatFor('eb');
            $previousPunches = [];

            foreach ($deviceReadings as $reading) {
                $current = (int) $reading->growbags_counter;
                $eb = (float) $reading->eb_units_counter;

                $machine = $machines === []
                    ? $unconfigured
                    : ($machines[$reading->field_id] ?? $soleMachine);

                // Energy is the factory's, so it is walked whatever machine the reading came
                // from — including one this team has not configured, whose punches cannot be
                // credited but whose current still ran through the same panel.
                $ebStep = ProductionCounter::incrementFloat($previousEb, $eb);
                $previousEb = ProductionCounter::carryForwardFloat($previousEb, $eb);

                if ($machine === null) {
                    continue;
                }

                $field = (string) $reading->field_id;

                if (! array_key_exists($field, $previousPunches)) {
                    $previousPunches[$field] = ($punchSeeds[$deviceId.'|'.$field] ?? CounterSeed::none())->for('punches');
                }

                $key = $keyFor($reading);

                $tally = $tallies[$key][$machine['id']] ?? [
                    'punches' => 0,
                    'bags_per_punch' => $machine['bags_per_punch'],
                    'machine_name' => $machine['name'],
                    'eb' => 0.0,
                    'readings' => 0,
                ];

                $tally['punches'] += ProductionCounter::increment($previousPunches[$field], $current);
                $tally['eb'] += $ebStep;
                $tally['readings']++;

                $tallies[$key][$machine['id']] = $tally;
                $previousPunches[$field] = ProductionCounter::carryForward($previousPunches[$field], $current);
            }
        }

        return $tallies;
    }

    /**
     * The carry-over seed for each machine's punch counter, keyed "deviceId|fieldId".
     *
     * One per machine rather than one per device, because that is how the counters are kept:
     * see {@see walk()}.
     *
     * @param  array<string, array{id: int, name: string, bags_per_punch: int}>  $machines  keyed by field id
     * @return array<string, CounterSeed>
     */
    private function punchSeedsBefore(int $teamId, Carbon $from, string $anchor, array $machines, ?int $deviceId = null): array
    {
        if ($machines === []) {
            return [];
        }

        return CounterSeed::beforePerPartition(
            'pulse_device_data_growbags',
            'growbag_datetime',
            ['punches' => 'growbags_counter'],
            $this->deviceIds($teamId, $deviceId),
            'field_id',
            array_keys($machines),
            $from,
            $anchor,
            'growbags',
        );
    }

    /**
     * The carry-over seed for the EB panel of every device this team's readings can come
     * from. The panel is the factory's, so this one stays per device.
     *
     * @return array<int, CounterSeed>
     */
    private function ebSeedsBefore(int $teamId, Carbon $from, string $anchor, ?int $deviceId = null): array
    {
        return CounterSeed::before(
            'pulse_device_data_growbags',
            'growbag_datetime',
            ['eb' => 'eb_units_counter'],
            $this->deviceIds($teamId, $deviceId),
            $from,
            $anchor,
            'growbags',
        );
    }

    /**
     * @return Collection<int, int>
     */
    private function deviceIds(int $teamId, ?int $deviceId = null): Collection
    {
        return $deviceId !== null
            ? collect([$deviceId])
            : DB::table('pulse_devices')->where('team_id', $teamId)->pluck('device_id');
    }

    /**
     * @return Collection<int, object>
     */
    private function readings(int $teamId, Carbon $from, Carbon $until, ?int $deviceId = null): Collection
    {
        $deviceIds = $this->deviceIds($teamId, $deviceId);

        if ($deviceIds->isEmpty()) {
            return collect();
        }

        return DB::table('pulse_device_data_growbags')
            ->whereIn('device_id', $deviceIds)
            ->where('is_duplicate', 0)
            ->where('growbag_datetime', '>=', $from)
            ->where('growbag_datetime', '<', $until)
            ->orderBy('growbag_datetime')
            ->orderBy('id')
            ->get(['device_id', 'growbag_datetime', 'growbags_counter', 'eb_units_counter', 'field_id', 'shift_id']);
    }

    /**
     * What a punch is worth on each machine, by the field_id its readings carry.
     *
     * @return array<string, array{id: int, name: string, bags_per_punch: int}>
     */
    private function machinesByField(int $teamId, ?int $machineId, ?int $deviceId = null): array
    {
        return DB::table('pulse_master_growbag_machines')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->when($machineId, fn ($query) => $query->where('id', $machineId))
            ->when($deviceId !== null, fn ($query) => $query->where('device_id', $deviceId))
            ->whereNotNull('field_id')
            ->where('field_id', '!=', '')
            ->get(['id', 'field_id', 'machine_name', 'bags_per_punch'])
            ->mapWithKeys(fn ($machine) => [$machine->field_id => [
                'id' => (int) $machine->id,
                'name' => $machine->machine_name,
                'bags_per_punch' => max(1, (int) $machine->bags_per_punch),
            ]])
            ->all();
    }

    /**
     * The shift a reading belongs to: the one stamped on it by the nightly job, or — for the
     * unstamped readings this fallback is for — the one its time of day falls in.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     */
    private function shiftIdOf(object $reading, string $anchor, array $segments): int
    {
        if ($reading->shift_id !== null) {
            return (int) $reading->shift_id;
        }

        return $this->shiftIdForMoment($segments, $anchor, $reading->growbag_datetime);
    }

    /**
     * The shift date a reading belongs to: a night shift running past midnight stays on the
     * day it started, which is how the summary tables key it.
     */
    private function shiftDateOf(string $datetime, string $anchor): string
    {
        return Shift::shiftDateFor($datetime, $anchor);
    }

    private function key(string $shiftDate, int $shiftId, int $machineId): string
    {
        return $shiftDate.'|'.$shiftId.'|'.$machineId;
    }

    /**
     * The shift a row belongs to, which is what decides whether the summary or the raw
     * readings are the ones reporting it.
     */
    private function shiftKey(object $row): string
    {
        return $row->shift_date.'|'.$row->shift_id;
    }
}
