<?php

namespace App\Services\Pulse\Dashboard;

use App\Http\Controllers\Pulse\Dashboard\GrowBags\DashboardGrowBagsApiController;
use App\Models\Pulse\Shift;
use App\Services\Pulse\GrowbagShiftDayTotals;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GrowBagsProductionService
{
    public function __construct(private readonly GrowbagShiftDayTotals $shiftDayTotals) {}

    public function get($startDate, $endDate, $type)
    {
        $teamId = Auth::user()->currentTeam->id;
        $machines = $this->getMachines($teamId);
        $shifts = $this->getShifts($teamId);
        $shiftIds = $shifts->pluck('id');

        if ($type === 'year') {
            $production = $this->getProductionByYear($teamId, $shiftIds, $machines, $startDate, $endDate);
        } elseif ($type === 'day') {
            $production = $this->getProductionByDay($teamId, $shiftIds, $machines, $startDate);
        } else {
            $production = $this->getProductionByDateRange($teamId, $shiftIds, $machines, $startDate, $endDate);
        }

        if ($type === 'day') {
            $totalGrowbags = $production->sum('day_growbags');
            $totalEbPrice = $production->sum('eb_price');

            $dayTotals = [
                'data' => $production->values(),
                'machines' => $machines,
                'shifts' => $shifts,
                'shiftSummary' => [],
                'total_punch' => $totalGrowbags,
                'total_growbags' => $totalGrowbags,
                'total_eb_units' => $production->sum('eb_units'),
                'total_eb_price' => $totalEbPrice,
            ];

            return [
                'growbagsProduction' => array_merge(
                    $dayTotals,
                    $this->getDayShiftTotals($teamId, $startDate, $shiftIds)
                ),
            ];
        }

        // Totals
        $totalPunch = $production->sum(fn ($row) => collect($row['shifts'])->sum('shift_punch'));
        $totalGrowbags = $production->sum(fn ($row) => collect($row['shifts'])->sum('shift_growbags'));
        $totalEbUnits = $production->sum(fn ($row) => collect($row['shifts'])->sum('shift_eb_units'));
        $totalEbPrice = $production->sum(fn ($row) => collect($row['shifts'])->sum('shift_eb_price'));

        // Shift summary
        $shiftSummary = [];
        foreach ($shiftIds as $shiftId) {
            $shiftSummary[$shiftId] = ['punch' => 0, 'growbags' => 0, 'eb_units' => 0, 'eb_price' => 0];
        }
        foreach ($production as $row) {
            foreach ($row['shifts'] as $shift) {
                $id = $shift['shift_id'];
                $shiftSummary[$id]['punch'] += $shift['shift_punch'] ?? 0;
                $shiftSummary[$id]['growbags'] += $shift['shift_growbags'] ?? 0;
                $shiftSummary[$id]['eb_units'] += $shift['shift_eb_units'] ?? 0;
                $shiftSummary[$id]['eb_price'] += $shift['shift_eb_price'] ?? 0;
            }
        }

        return [
            'growbagsProduction' => [
                'data' => $production->values(),
                'machines' => $machines,
                'shifts' => $shifts,
                'shiftSummary' => $shiftSummary,
                'total_punch' => $totalPunch,
                'total_growbags' => $totalGrowbags,
                'total_eb_units' => $totalEbUnits,
                'total_eb_price' => $totalEbPrice,
            ],
        ];
    }

    /**
     * The day's shift figures, from the one resolver every growbag screen reads.
     *
     * The hourly bars have to be counted from raw readings — the summary table has no hourly
     * grain — but the figures beside them must be the same numbers the IoT Insights calendar
     * prints, so they are not recomputed here at all. Both are now the same measurement at
     * two grains, which is what removed the scale factor that used to reconcile them.
     *
     * @return array{shiftSummary: array<int, array<string, float>>, total_punch: float,
     *               total_growbags: float, total_eb_units: float, total_eb_price: float}
     */
    private function getDayShiftTotals($teamId, $date, $shiftIds): array
    {
        $dateStr = ($date instanceof Carbon) ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $totals = $this->shiftDayTotals->forDate($teamId, $dateStr);

        // Shift 0 catches rows the job wrote against a shift that has since been deactivated,
        // so their production still reaches the totals instead of vanishing from the tiles.
        $shiftSummary = [0 => ['punch' => 0, 'growbags' => 0, 'eb_units' => 0, 'eb_price' => 0]];

        foreach ($shiftIds as $shiftId) {
            $shiftSummary[$shiftId] = ['punch' => 0, 'growbags' => 0, 'eb_units' => 0, 'eb_price' => 0];
        }

        foreach ($totals as $row) {
            $shiftId = array_key_exists($row->shift_id, $shiftSummary) ? $row->shift_id : 0;

            $shiftSummary[$shiftId]['growbags'] += $row->bags_produced;
            $shiftSummary[$shiftId]['eb_units'] += $row->eb_units;
            $shiftSummary[$shiftId]['eb_price'] += $row->eb_price;
        }

        return [
            'shiftSummary' => $shiftSummary,
            'total_punch' => $totals->sum('bags_produced'),
            'total_growbags' => $totals->sum('bags_produced'),
            'total_eb_units' => round($totals->sum('eb_units'), 1),
            'total_eb_price' => round($totals->sum('eb_price'), 1),
        ];
    }

    // ── Machines & Shifts ────────────────────────────────────────────────────

    private function getMachines($teamId)
    {
        return DB::table('pulse_master_growbag_machines')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('field_id')
            ->get(['id', 'field_id', 'machine_name', 'bags_per_punch']);
    }

    /**
     * The shifts the page reports on: the team's active ones, plus any the summary still
     * carries production for.
     *
     * Reading only the summary, as this did, meant a shift did not exist on the dashboard
     * until the nightly job had summarised it — so a day counted live from raw readings had
     * nowhere to put its figures and they were pooled under the unattributed remainder.
     */
    private function getShifts($teamId)
    {
        $active = Shift::activeForTeam($teamId)
            ->orderedByOffset(Shift::anchorForTeam($teamId))
            ->get()
            ->map(fn ($shift) => (object) ['id' => $shift->id, 'name' => $shift->name]);

        $summarised = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->select('shift_id as id', 'shift_name as name')
            ->distinct()
            ->orderBy('shift_id')
            ->get();

        return $active->concat($summarised)->unique('id')->values();
    }

    // ── Production by type ───────────────────────────────────────────────────

    private function getProductionByDateRange($teamId, $shiftIds, $machines, $start, $end)
    {
        $diffInDays = $start->diffInDays($end);

        if ($diffInDays > 91 || ($diffInDays > 31 && $start->isStartOfMonth() && $end->copy()->isSameDay($end->copy()->endOfMonth()))) {
            return $this->getMonthlyProduction($teamId, $shiftIds, $machines, $start, $end);
        }

        if ($diffInDays > 31) {
            return $this->getWeeklyProduction($teamId, $shiftIds, $machines, $start, $end);
        }

        return $this->getDailyProduction($teamId, $shiftIds, $machines, $start, $end);
    }

    private function getDailyProduction($teamId, $shiftIds, $machines, $start, $end)
    {
        $startStr = ($start instanceof Carbon) ? $start->toDateString() : Carbon::parse($start)->toDateString();
        $endStr = ($end instanceof Carbon) ? $end->toDateString() : Carbon::parse($end)->toDateString();

        $results = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startStr, $endStr])
            ->get();

        $dates = collect(CarbonPeriod::create($startStr, $endStr)->toArray())
            ->map(fn ($d) => Carbon::instance($d)->format('Y-m-d'));

        return $dates->map(function ($date) use ($results, $shiftIds, $machines) {
            $dayRows = $results->where('shift_date', $date);

            return $this->buildPeriodResult(
                Carbon::parse($date)->format('M j'),
                $date,
                $dayRows,
                $shiftIds,
                $machines
            );
        });
    }

    private function getWeeklyProduction($teamId, $shiftIds, $machines, $start, $end)
    {
        $startStr = $start->copy()->toDateString();
        $endStr = $end->copy()->toDateString();

        $results = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startStr, $endStr])
            ->get();

        return collect(CarbonPeriod::create($startStr, $endStr)->toArray())
            ->chunk(7)
            ->map(function ($weekDates) use ($results, $shiftIds, $machines) {
                $weekStart = $weekDates->first()->format('Y-m-d');
                $weekEnd = $weekDates->last()->format('Y-m-d');
                $weekRows = $results->whereBetween('shift_date', [$weekStart, $weekEnd]);
                $period = Carbon::parse($weekStart)->format('M j').' - '.Carbon::parse($weekEnd)->format('M j');

                return $this->buildPeriodResult($period, $weekStart, $weekRows, $shiftIds, $machines);
            });
    }

    private function getMonthlyProduction($teamId, $shiftIds, $machines, $start, $end)
    {
        $startStr = $start->copy()->startOfMonth()->toDateString();
        $endStr = $end->copy()->endOfMonth()->toDateString();

        $results = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startStr, $endStr])
            ->get();

        return collect(CarbonPeriod::create($startStr, $endStr)->toArray())
            ->groupBy(fn ($date) => Carbon::instance($date)->format('Y-m'))
            ->map(function ($_, $monthKey) use ($results, $shiftIds, $machines) {
                $monthStart = Carbon::parse($monthKey.'-01')->startOfMonth()->toDateString();
                $monthEnd = Carbon::parse($monthKey.'-01')->endOfMonth()->toDateString();
                $monthRows = $results->whereBetween('shift_date', [$monthStart, $monthEnd]);
                $period = Carbon::parse($monthStart)->format('M Y');

                return $this->buildPeriodResult($period, $monthKey, $monthRows, $shiftIds, $machines);
            })
            ->values();
    }

    private function getProductionByDay($teamId, $shiftIds, $machines, $date)
    {
        $dateStr = ($date instanceof Carbon) ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $shiftStartTime = Shift::anchorForTeam($teamId);
        $isWholeHourStart = Carbon::parse($shiftStartTime)->minute === 0;
        $startOfDay = Carbon::parse($dateStr)->setTimeFromTimeString($shiftStartTime);
        $endOfDay = $startOfDay->copy()->addHours(24)->subSecond();

        $deviceIds = DB::table('pulse_devices')
            ->where('team_id', $teamId)
            ->pluck('device_id');

        $rawPunches = DB::table('pulse_device_data_growbags')
            ->whereIn('device_id', $deviceIds)
            ->where('is_duplicate', 0)
            ->whereBetween('growbag_datetime', [$startOfDay, $endOfDay])
            ->get();

        // No raw device data for this day (no device, or it hasn't reported yet) —
        // fall back to a simple daily summary instead of an all-zero hourly chart.
        if ($rawPunches->isEmpty()) {
            $totals = $this->getDayShiftTotals($teamId, $dateStr, $shiftIds);

            return collect([[
                'period' => Carbon::parse($dateStr)->format('M j, Y'),
                'shift_id' => null,
                'shifts' => [],
                'granularity' => 'summary',
                'day_punch' => $totals['total_growbags'],
                'day_growbags' => $totals['total_growbags'],
                'eb_units' => $totals['total_eb_units'],
                'eb_price' => $totals['total_eb_price'],
            ]]);
        }

        $ebPriceValue = DB::table('pulse_settings')
            ->where('key', 'RS_PER_EB_UNIT')
            ->where('team_id', $teamId)
            ->value('value') ?? 0;

        /*
         * An hour's bar is how far the machine's counter travelled in it, priced in bags by
         * the machine that made the punches — the same measurement, at a finer grain, as the
         * shift and day figures printed beside it. The bars used to be raw row counts scaled
         * by the day's summary total, with the rounding difference dropped on the busiest
         * hour, so a day whose uploads went missing drew one invented spike and no real shape.
         */
        $hourlyMachineBags = $this->shiftDayTotals->hourlyMachineBagsForDay($teamId, $dateStr);
        $hourlyBags = $hourlyMachineBags->map(
            fn (array $perShift) => collect($perShift)->sum(
                fn (array $perMachine) => collect($perMachine)->sum('growbags')
            )
        );
        $shiftNames = Shift::where('team_id', $teamId)->pluck('name', 'id');

        // Energy off the same walk as the bags. Taking each hour's own min/max span instead
        // charged every hour a unit it had not used and lost whatever the meter did between
        // one hour's last reading and the next hour's first.
        $ebPerHour = $hourlyMachineBags->map(
            fn (array $perShift) => collect($perShift)->sum(
                fn (array $perMachine) => collect($perMachine)->sum('eb')
            )
        );

        // Build 24 hourly rows starting from shift start
        $hourlyData = collect();
        $currentStart = $startOfDay->copy();

        while ($currentStart->lessThan($endOfDay)) {
            $intervalKey = $currentStart->format('Y-m-d H:i');
            $ebUnits = $ebPerHour[$intervalKey] ?? 0;

            $startFormatted = $currentStart->format($isWholeHourStart ? 'g a' : 'g:i a');
            $endFormatted = $currentStart->copy()->addHour()->format($isWholeHourStart ? 'g a' : 'g:i a');

            $hourlyData->push([
                'period' => $startFormatted.' - '.$endFormatted,
                'shift_id' => null,
                // The hour split by shift and machine, shaped as the range views shape it so
                // the machine chart reads one payload whatever period is in view.
                'shifts' => $this->hourShifts($hourlyMachineBags[$intervalKey] ?? [], $machines, $shiftNames),
                'day_punch' => $hourlyBags[$intervalKey] ?? 0,
                'day_growbags' => $hourlyBags[$intervalKey] ?? 0,
                'eb_units' => $ebUnits,
                // Priced per machine and then added, which is how the app's endpoint prices
                // the same hour. Rounding the hour's total instead left the two a paisa apart
                // on some hours and level on others.
                'eb_price' => $this->hourEbPrice($hourlyMachineBags[$intervalKey] ?? [], $ebPriceValue),
            ]);

            $currentStart->addHour();
        }

        return $hourlyData;
    }

    /**
     * What one hour's energy costs: each machine's share priced and rounded on its own, then
     * added. {@see DashboardGrowBagsApiController}
     * prices the same hour the same way, so a day costs the same on both.
     *
     * @param  array<int, array<int, array{machine_name: string, growbags: int, eb: float}>>  $perShift
     */
    private function hourEbPrice(array $perShift, $ebPriceValue): float
    {
        $price = 0.0;

        foreach ($perShift as $perMachine) {
            foreach ($perMachine as $tally) {
                $price += round(($tally['eb'] ?? 0) * $ebPriceValue, 1);
            }
        }

        return round($price, 1);
    }

    /**
     * One hour's machine tallies in the shape the charts read: a list of shifts, each naming
     * every configured machine so a machine that stood idle draws a zero rather than a gap.
     *
     * @param  array<int, array<int, array{machine_name: string, growbags: int}>>  $perShift
     * @return list<array<string, mixed>>
     */
    private function hourShifts(array $perShift, $machines, $shiftNames): array
    {
        $shifts = [];

        foreach ($perShift as $shiftId => $perMachine) {
            $production = [];
            $total = 0;

            foreach ($machines as $machine) {
                $bags = (int) ($perMachine[$machine->id]['growbags'] ?? 0);
                $total += $bags;

                $production[] = [
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->machine_name,
                    'growbags' => $bags,
                ];
            }

            $shifts[] = [
                'shift_id' => $shiftId,
                'shift_name' => $shiftNames[$shiftId] ?? '',
                'shift_growbags' => $total,
                'production' => $production,
            ];
        }

        return $shifts;
    }

    private function getProductionByYear($teamId, $shiftIds, $machines, $startDate, $endDate)
    {
        $start = Carbon::parse($startDate)->copy()->startOfYear()->toDateString();
        $end = Carbon::parse($endDate)->copy()->endOfYear()->toDateString();

        // Shift-level totals per month
        $shiftResults = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$start, $end])
            ->selectRaw('
                shift_id,
                MAX(shift_name) as shift_name,
                DATE_FORMAT(shift_date, "%Y-%m") as month,
                SUM(COALESCE(bags_produced, 0)) as growbags,
                SUM(COALESCE(eb_units, 0)) as eb_units,
                SUM(eb_price) as eb_price
            ')
            ->groupBy('shift_id', 'month')
            ->get();

        // Machine-level totals per month per shift
        $machineResults = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$start, $end])
            ->selectRaw('
                growbag_machine_id,
                shift_id,
                DATE_FORMAT(shift_date, "%Y-%m") as month,
                SUM(COALESCE(bags_produced, 0)) as growbags,
                SUM(eb_price) as eb_price
            ')
            ->groupBy('growbag_machine_id', 'shift_id', 'month')
            ->get();

        $months = collect(CarbonPeriod::create(
            Carbon::parse($start)->startOfYear(),
            '1 month',
            Carbon::parse($end)->endOfYear()
        )->toArray())->map(fn ($d) => Carbon::instance($d)->format('Y-m'));

        return $months->map(function ($month) use ($shiftResults, $machineResults, $shiftIds, $machines) {
            $monthShiftRows = $shiftResults->where('month', $month);
            $monthMachineRows = $machineResults->where('month', $month);
            $period = Carbon::parse($month.'-01')->format('M Y');

            $dayPunch = 0;
            $dayGrowbags = 0;
            $shiftsData = [];

            foreach ($shiftIds as $shiftId) {
                $shiftRow = $monthShiftRows->where('shift_id', $shiftId)->first();
                $shiftGrowbags = (int) ($shiftRow?->growbags ?? 0);
                $shiftEbUnits = (float) ($shiftRow?->eb_units ?? 0);
                $shiftEbPrice = (float) ($shiftRow?->eb_price ?? 0);
                $shiftName = $shiftRow?->shift_name ?? 'Shift '.$shiftId;

                $production = $machines->map(function ($machine) use ($monthMachineRows, $shiftId) {
                    $machineRow = $monthMachineRows
                        ->where('growbag_machine_id', $machine->id)
                        ->where('shift_id', $shiftId)
                        ->first();

                    return [
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->machine_name,
                        'growbags' => (int) ($machineRow?->growbags ?? 0),
                        'eb_price' => (float) ($machineRow?->eb_price ?? 0),
                    ];
                })->values()->toArray();

                $dayGrowbags += $shiftGrowbags;

                $shiftsData[] = [
                    'shift_id' => $shiftId,
                    'shift_name' => $shiftName,
                    'shift_growbags' => $shiftGrowbags,
                    'shift_eb_units' => $shiftEbUnits,
                    'shift_eb_price' => $shiftEbPrice,
                    'production' => $production,
                ];
            }

            return [
                'period' => $period,
                'date' => $month,
                'shifts' => $shiftsData,
                'day_punch' => $dayPunch,
                'day_growbags' => $dayGrowbags,
            ];
        });
    }

    // ── Shared result builders ───────────────────────────────────────────────

    /**
     * Used by day, daily, weekly, monthly — works on raw (ungrouped) rows
     */
    private function buildPeriodResult($period, $date, $rows, $shiftIds, $machines)
    {
        $dayGrowbags = 0;
        $shiftsData = [];

        foreach ($shiftIds as $shiftId) {
            $shiftRows = $rows->where('shift_id', $shiftId);
            $shiftName = $shiftRows->first()?->shift_name ?? 'Shift '.$shiftId;
            $shiftGrowbags = $shiftRows->sum('bags_produced');

            $production = $machines->map(function ($machine) use ($shiftRows) {
                $machineRow = $shiftRows->where('growbag_machine_id', $machine->id)->first();

                return [
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->machine_name,
                    'growbags' => $machineRow?->bags_produced ?? 0,
                    'eb_price' => $machineRow?->eb_price ?? 0,
                ];
            })->values()->toArray();

            $dayGrowbags += $shiftGrowbags;

            $shiftsData[] = [
                'shift_id' => $shiftId,
                'shift_name' => $shiftName,
                'shift_growbags' => $shiftGrowbags,
                'shift_eb_units' => $shiftRows->sum('eb_units'),
                'shift_eb_price' => $shiftRows->sum('eb_price'),
                'production' => $production,
            ];
        }

        return [
            'period' => $period,
            'date' => $date,
            'shifts' => $shiftsData,
            'day_growbags' => $dayGrowbags,
        ];
    }

    /**
     * Used by year — works on pre-grouped (selectRaw) rows
     */
    private function buildPeriodResultFromGrouped($period, $date, $rows, $shiftIds, $machines)
    {
        $dayPunch = 0;
        $dayGrowbags = 0;
        $shiftsData = [];

        foreach ($shiftIds as $shiftId) {
            $shiftRows = $rows->where('shift_id', $shiftId);
            $shiftGrowbags = $shiftRows->sum('bags_produced');

            $production = $machines->map(function ($machine) use ($shiftRows) {
                $machineRow = $shiftRows->where('growbag_machine_id', $machine->id)->first();

                return [
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->machine_name,
                    'growbags' => $machineRow?->bags_produced ?? 0,
                    'eb_price' => $machineRow?->eb_price ?? 0,
                ];
            })->values()->toArray();

            $dayGrowbags += $shiftGrowbags;

            $shiftsData[] = [
                'shift_id' => $shiftId,
                'shift_name' => $rows->first()?->shift_name ?? 'Shift '.$shiftId,
                'shift_growbags' => $shiftGrowbags,
                'shift_eb_price' => $shiftRows->sum('eb_price'),
                'production' => $production,
            ];
        }

        return [
            'period' => $period,
            'date' => $date,
            'shifts' => $shiftsData,
            'day_growbags' => $dayGrowbags,
        ];
    }
}
