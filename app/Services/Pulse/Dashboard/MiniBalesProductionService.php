<?php

namespace App\Services\Pulse\Dashboard;

use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\Shift;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MiniBalesProductionService
{
    public function get($startDate, $endDate, $type)
    {
        $currentTeam = Auth::user()->currentTeam;
        $currentTeamId = $currentTeam->id;

        $machines = DB::table('pulse_master_minibale_machines')
            ->where('team_id', $currentTeamId)
            ->where('is_active', 1)
            ->orderBy('machine_name')
            ->get();

        if ($startDate->isSameDay($endDate)) {
            // Use hourly data for daily view like blocks
            $fibreProduction = $this->getProductionByDay($currentTeamId, $startDate);

            return [
                'data' => $fibreProduction['data'],
                'total_bales_produced' => $fibreProduction['total_bales_produced'],
                'total_bales_expected' => $fibreProduction['total_bales_expected'],
                'by_machine' => $this->getMachineBreakdownForDay($currentTeamId, $startDate, $machines),
                'by_machine_series' => $this->getMachineSeriesForDay($currentTeamId, $startDate, $machines),
            ];
        } elseif ($type == 'year') {
            $fibreProduction = $this->getProductionByYear($currentTeamId, $startDate, $endDate);
        } else {
            $fibreProduction = $this->getProductionByDateRange($currentTeamId, $startDate, $endDate);
        }

        $totalBalesProduced = round($fibreProduction->sum('bales_produced'));
        $totalBalesExpected = round($fibreProduction->sum('bales_expected'));

        return [
            'data' => $fibreProduction->isEmpty() ? [] : $fibreProduction,
            'total_bales_produced' => $totalBalesProduced,
            'total_bales_expected' => $totalBalesExpected,
            'by_machine' => $this->getMachineBreakdownForRange($currentTeamId, $startDate, $endDate, $machines),
            'by_machine_series' => $this->getMachineSeriesForRange($currentTeamId, $startDate, $endDate, $machines, $type),
        ];
    }

    private function getMachineSeriesForRange($currentTeamId, $startDate, $endDate, $machines, $type)
    {
        if ($machines->isEmpty()) {
            return (object) [];
        }

        $diffInDays = $startDate->diffInDays($endDate);
        $isExactOneMonth = $startDate->isStartOfMonth()
            && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth())
            && $diffInDays <= 31;

        $result = [];

        foreach ($machines as $machine) {
            $machineId = $machine->id;
            $machineName = $machine->machine_name;

            if ($type === 'year' || $diffInDays > 63 ||
                ($diffInDays > 14 && ! $isExactOneMonth && $startDate->isStartOfMonth() && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth()))
            ) {
                $series = collect(CarbonPeriod::create($startDate->copy()->startOfMonth(), $endDate->copy()->endOfMonth())->toArray())
                    ->groupBy(fn ($date) => $date->format('Y-m'))
                    ->map(function ($monthDates, $monthKey) use ($currentTeamId, $machineId) {
                        $monthStart = Carbon::parse($monthKey.'-01')->startOfMonth()->format('Y-m-d');
                        $monthEnd = Carbon::parse($monthKey.'-01')->endOfMonth()->format('Y-m-d');
                        $aggregated = DB::table('pulse_summary_mini_bales')
                            ->selectRaw('SUM(bales_produced) as bales_produced, SUM(bales_expected) as bales_expected')
                            ->where('team_id', $currentTeamId)
                            ->where('minibale_machine_id', $machineId)
                            ->whereBetween('shift_date', [$monthStart, $monthEnd])
                            ->first();

                        return [
                            'period' => Carbon::parse($monthStart)->format('M Y'),
                            'bales_produced' => round($aggregated->bales_produced ?? 0, 1),
                            'bales_expected' => round($aggregated->bales_expected ?? 0, 1),
                        ];
                    })->values()->toArray();

            } elseif ($diffInDays > 14) {
                $series = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
                    ->chunk(7)
                    ->map(function ($weekDates) use ($currentTeamId, $machineId) {
                        $weekStart = $weekDates->first()->format('Y-m-d');
                        $weekEnd = $weekDates->last()->format('Y-m-d');
                        $aggregated = DB::table('pulse_summary_mini_bales')
                            ->selectRaw('SUM(bales_produced) as bales_produced, SUM(bales_expected) as bales_expected')
                            ->where('team_id', $currentTeamId)
                            ->where('minibale_machine_id', $machineId)
                            ->whereBetween('shift_date', [$weekStart, $weekEnd])
                            ->first();

                        return [
                            'period' => Carbon::parse($weekStart)->format('M j').' - '.Carbon::parse($weekEnd)->format('M j'),
                            'bales_produced' => round($aggregated->bales_produced ?? 0, 1),
                            'bales_expected' => round($aggregated->bales_expected ?? 0, 1),
                        ];
                    })->values()->toArray();

            } else {
                $datesInRange = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
                    ->map(fn ($date) => $date->format('Y-m-d'));

                $productionData = DB::table('pulse_summary_mini_bales')
                    ->selectRaw('shift_date as period, SUM(bales_produced) as bales_produced, SUM(bales_expected) as bales_expected')
                    ->where('team_id', $currentTeamId)
                    ->where('minibale_machine_id', $machineId)
                    ->whereRaw('DATE(shift_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
                    ->groupBy('period')
                    ->orderBy('period')
                    ->get()
                    ->keyBy(fn ($item) => Carbon::parse($item->period)->toDateString());

                $lastStock = 0;
                $series = $datesInRange->map(function ($date) use ($productionData, &$lastStock) {
                    $data = $productionData->get($date, null);
                    if ($data) {
                        return [
                            'period' => Carbon::parse($data->period)->format('M j'),
                            'bales_produced' => round($data->bales_produced, 1),
                            'bales_expected' => round($data->bales_expected, 1),
                        ];
                    }

                    return ['period' => Carbon::parse($date)->format('M j'), 'bales_produced' => 0, 'bales_expected' => 0];
                })->values()->toArray();
            }

            $result[$machineId] = $series;
        }

        return $result;
    }

    private function getMachineSeriesForDay($currentTeamId, $selectedDate, $machines)
    {
        if ($machines->isEmpty()) {
            return (object) [];
        }

        $result = [];
        foreach ($machines as $machine) {
            $machineId = $machine->id;
            $machineName = $machine->machine_name;
            $row = DB::table('pulse_summary_mini_bales')
                ->selectRaw('SUM(bales_produced) as bales_produced, SUM(bales_expected) as bales_expected')
                ->where('team_id', $currentTeamId)
                ->where('shift_date', $selectedDate->toDateString())
                ->where('minibale_machine_id', $machine->id)
                ->first();

            $result[$machine->id] = [[
                'period' => $selectedDate->format('M j'),
                'bales_produced' => round($row->bales_produced ?? 0, 1),
                'bales_expected' => round($row->bales_expected ?? 0, 1),
            ]];
        }

        return $result;
    }

    private function getMachineBreakdownForRange($currentTeamId, $startDate, $endDate, $machines)
    {
        if ($machines->isEmpty()) {
            return [];
        }

        $rows = DB::table('pulse_summary_mini_bales')
            ->select('minibale_machine_id', DB::raw('SUM(bales_produced) as bales_produced'), DB::raw('SUM(bales_expected) as bales_expected'))
            ->where('team_id', $currentTeamId)
            ->whereNotNull('minibale_machine_id')
            ->whereRaw('DATE(shift_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('minibale_machine_id')
            ->get()
            ->keyBy('minibale_machine_id');  // ← key by ID

        return $machines->map(function ($machine) use ($rows) {
            $row = $rows->get($machine->id);  // ← match by id

            return [
                'machine_id' => $machine->id,
                'machine_name' => $machine->machine_name,
                'bales_produced' => round($row->bales_produced ?? 0, 1),
                'bales_expected' => round($row->bales_expected ?? 0, 1),
            ];
        })->values()->toArray();
    }

    private function getMachineBreakdownForDay($currentTeamId, $selectedDate, $machines)
    {
        if ($machines->isEmpty()) {
            return [];
        }

        $rows = DB::table('pulse_summary_mini_bales')
            ->select('minibale_machine_id', DB::raw('SUM(bales_produced) as bales_produced'), DB::raw('SUM(bales_expected) as bales_expected'))
            ->where('team_id', $currentTeamId)
            ->where('shift_date', $selectedDate->toDateString())
            ->whereNotNull('minibale_machine_id')
            ->groupBy('minibale_machine_id')
            ->get()
            ->keyBy('minibale_machine_id');

        return $machines->map(function ($machine) use ($rows) {
            $row = $rows->get($machine->id);

            return [
                'machine_id' => $machine->id,
                'machine_name' => $machine->machine_name,
                'bales_produced' => round($row->bales_produced ?? 0, 1),
                'bales_expected' => round($row->bales_expected ?? 0, 1),
            ];
        })->values()->toArray();
    }

    private function calculateBalesExpected(int $deviceId, Carbon $startTime, Carbon $endTime): float
    {
        $result = DB::selectOne('
            SELECT f.first_counter, l.last_counter
            FROM (
                SELECT bales_counter AS first_counter FROM pulse_device_data_fibre_bales
                WHERE device_id = ? AND bale_datetime < ? ORDER BY bale_datetime DESC LIMIT 1
            ) AS f
            JOIN (
                SELECT bales_counter AS last_counter FROM pulse_device_data_fibre_bales
                WHERE device_id = ? AND bale_datetime <= ? ORDER BY bale_datetime DESC LIMIT 1
            ) AS l ON 1=1
        ', [$deviceId, $startTime, $deviceId, $endTime]);

        if ($result && $result->last_counter >= $result->first_counter) {
            return round($result->last_counter - $result->first_counter, 1);
        }

        return 0;
    }

    private function getProductionByDay($currentTeamId, $selectedDate, $intervalHours = 1)
    {
        $team = Auth::user()->currentTeam;

        $hourly = collect();
        if ($team->has_pith_module_bales) {
            $startOfDay = Shift::activeForTeam($currentTeamId)->exists()
                ? Carbon::parse($selectedDate)->setTimeFromTimeString(Shift::anchorForTeam($currentTeamId))
                : Carbon::parse($selectedDate)->startOfDay();
            $endOfDay = $startOfDay->copy()->addDay();

            $teamDeviceIds = DB::table('pulse_devices')->where('team_id', $currentTeamId)->pluck('device_id');

            $deviceData = DB::table('pulse_device_data_fibre_bales')
                ->select('device_id', 'bale_datetime', 'bales_counter')
                ->whereIn('device_id', $teamDeviceIds)
                ->whereBetween('bale_datetime', [$startOfDay, $endOfDay])
                ->where('is_duplicate', 0)
                ->orderBy('bale_datetime')
                ->get()
                ->groupBy('device_id');

            $intervalSeconds = $intervalHours * 3600;
            $hourlyLimit = config('app.minibales.hourly_production_limit');

            for ($i = 0; $i < 24 / $intervalHours; $i++) {
                $startTime = (clone $startOfDay)->addSeconds($i * $intervalSeconds);
                $endTime = (clone $startTime)->addSeconds($intervalSeconds - 1);
                $balesExpected = 0;

                foreach ($teamDeviceIds as $deviceId) {
                    $readings = $deviceData[$deviceId] ?? collect();
                    $intervalExists = $readings->first(function ($item) use ($startTime, $endTime) {
                        return Carbon::parse($item->bale_datetime)->between($startTime, $endTime);
                    });
                    if ($intervalExists) {
                        $balesExpected += $this->calculateBalesExpected($deviceId, $startTime, $endTime);
                    }
                }

                if ($hourlyLimit && $balesExpected > $hourlyLimit) {
                    continue;
                }

                $hourly->push([
                    'period' => $startTime->format($startTime->minute !== 0 ? 'g:i a' : 'g a')
                                      .' - '
                                      .$endTime->addSecond()->format($endTime->minute !== 0 ? 'g:i a' : 'g a'),
                    'bales_produced' => 0,           // device counter only tracks expected
                    'bales_expected' => $balesExpected,
                    'bales_in_stock' => 0,
                ]);
            }
        }

        // ── Summary rows: always fetch for totals + fallback chart data ───────
        $summaryRows = DB::table('pulse_summary_mini_bales')
            ->where('team_id', $currentTeamId)
            ->whereRaw('DATE(shift_date) = ?', [$selectedDate->toDateString()])
            ->orderBy('id')
            ->get();

        $totalProduced = round($summaryRows->sum('bales_produced'), 1);
        $totalExpected = round($summaryRows->sum('bales_expected'), 1);
        // Closing stock comes from the daily ledger, which nets sales and stock-takes
        // off production instead of only accumulating it.
        $closingStock = MiniBaleProduction::closingStockAt($currentTeamId, $selectedDate->toDateString());

        // ── KEY FIX: fallback to summary shift rows when hourly device data is all zeros ─
        $hourlyHasData = $hourly->some(fn ($row) => ($row['bales_expected'] > 0) || ($row['bales_produced'] > 0));

        if ((! $hourlyHasData || $hourly->isEmpty()) && $summaryRows->isNotEmpty()) {
            $hourly = collect([[
                'period' => $selectedDate->format('M j, Y'),
                'bales_produced' => round($summaryRows->sum('bales_produced') ?? 0, 1),
                'bales_expected' => round($summaryRows->sum('bales_expected') ?? 0, 1),
                'bales_in_stock' => $closingStock,
            ]]);
        }

        return [
            'data' => $hourly,
            'total_bales_produced' => $totalProduced,
            'total_bales_expected' => $totalExpected,
            'last_bales_in_stock' => $closingStock,
        ];
    }

    private function getProductionByDateRange($currentTeamId, $startDate, $endDate)
    {
        $diffInDays = $startDate->diffInDays($endDate);
        $isExactOneMonth = $startDate->isStartOfMonth()
            && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth())
            && $diffInDays <= 31;

        if ($diffInDays > 63 ||
            ($diffInDays > 14 && ! $isExactOneMonth && $startDate->isStartOfMonth() && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth()))
        ) {
            $periodStart = $startDate->copy()->startOfMonth();
            $periodEnd = $endDate->copy()->startOfMonth();

            return collect(CarbonPeriod::create($periodStart, '1 month', $periodEnd)->toArray())
                ->map(function ($monthDate) use ($currentTeamId, $startDate, $endDate) {
                    $monthKey = $monthDate->format('Y-m');

                    $monthStart = Carbon::parse($monthKey.'-01')->startOfMonth();
                    $monthEnd = Carbon::parse($monthKey.'-01')->endOfMonth();

                    // Clamp to actual startDate/endDate
                    $clampedStart = $monthStart->lt($startDate) ? $startDate->copy() : $monthStart;
                    $clampedEnd = $monthEnd->gt($endDate) ? $endDate->copy() : $monthEnd;

                    $clampedStartStr = $clampedStart->format('Y-m-d');
                    $clampedEndStr = $clampedEnd->format('Y-m-d');

                    $aggregated = DB::table('pulse_summary_mini_bales')
                        ->select(
                            DB::raw("'{$clampedStartStr} to {$clampedEndStr}' as period"),
                            DB::raw('SUM(bales_produced) as bales_produced'),
                            DB::raw('SUM(bales_expected) as bales_expected'),
                            DB::raw('MAX(bales_in_stock) as bales_in_stock')
                        )
                        ->where('team_id', $currentTeamId)
                        ->whereBetween('shift_date', [$clampedStartStr, $clampedEndStr])
                        ->groupBy('shift_date')
                        ->get();

                    return [
                        'period' => Carbon::parse($monthKey.'-01')->format('M Y'),
                        'bales_produced' => round($aggregated->sum('bales_produced') ?? 0, 1),
                        'bales_expected' => round($aggregated->sum('bales_expected') ?? 0, 1),
                        'bales_in_stock' => round($aggregated->max('bales_in_stock') ?? 0),
                    ];
                })->values();
        }

        if ($diffInDays > 14) {
            return collect(CarbonPeriod::create($startDate, $endDate)->toArray())
                ->chunk(7)
                ->map(function ($weekDates) use ($currentTeamId) {
                    $weekStart = $weekDates->first()->format('Y-m-d');
                    $weekEnd = $weekDates->last()->format('Y-m-d');
                    $aggregated = DB::table('pulse_summary_mini_bales')
                        ->select(DB::raw("'{$weekStart} to {$weekEnd}' as period"), DB::raw('SUM(bales_produced) as bales_produced'), DB::raw('SUM(bales_expected) as bales_expected'), DB::raw('MAX(bales_in_stock) as bales_in_stock'))
                        ->where('team_id', $currentTeamId)
                        ->whereBetween('shift_date', [$weekStart, $weekEnd])
                        ->groupBy('shift_date')
                        ->get();

                    return [
                        'period' => Carbon::parse($weekStart)->format('M j').' - '.Carbon::parse($weekEnd)->format('M j'),
                        'bales_produced' => round($aggregated->sum('bales_produced') ?? 0, 1),
                        'bales_expected' => round($aggregated->sum('bales_expected') ?? 0, 1),
                        'bales_in_stock' => round($aggregated->max('bales_in_stock') ?? 0),
                    ];
                });
        }

        // Daily view
        $datesInRange = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
            ->map(fn ($date) => $date->format('Y-m-d'));

        $productionData = DB::table('pulse_summary_mini_bales')
            ->selectRaw('
            DATE(shift_date) as period,
            SUM(bales_produced) as bales_produced,
            SUM(bales_expected) as bales_expected,
            (
                SELECT s2.bales_in_stock
                FROM pulse_summary_mini_bales s2
                WHERE s2.team_id = pulse_summary_mini_bales.team_id
                AND DATE(s2.shift_date) = DATE(pulse_summary_mini_bales.shift_date)
                ORDER BY s2.id DESC
                LIMIT 1
            ) as bales_in_stock
        ')
            ->where('team_id', $currentTeamId)
            ->whereRaw('DATE(shift_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy(DB::raw('DATE(shift_date)'))  // ← removed team_id grouping and havingRaw
            ->orderBy('period')
            ->get();

        $productionMap = $productionData->keyBy('period');
        $lastBalesInStock = 0;

        return $datesInRange->map(function ($date) use ($productionMap, &$lastBalesInStock) {
            $data = $productionMap->get($date, null);
            if ($data) {
                $lastBalesInStock = round($data->bales_in_stock);

                return ['period' => Carbon::parse($data->period)->format('M j'), 'bales_produced' => round($data->bales_produced, 1), 'bales_expected' => round($data->bales_expected, 1), 'bales_in_stock' => $lastBalesInStock];
            }

            return ['period' => Carbon::parse($date)->format('M j'), 'bales_produced' => 0, 'bales_expected' => 0, 'bales_in_stock' => $lastBalesInStock];
        });
    }

    private function getProductionByYear($currentTeamId, $startDate, $endDate)
    {
        $fibreProduction = DB::table('pulse_summary_mini_bales')
            ->selectRaw('
                YEAR(shift_date) as year,
                MONTH(shift_date) as month,
                SUM(bales_produced) as bales_produced,
                SUM(bales_expected) as bales_expected,
                MAX(shift_date) as last_date
            ')
            ->where('team_id', $currentTeamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->groupBy(DB::raw('YEAR(shift_date)'), DB::raw('MONTH(shift_date)'))
            ->get()
            ->groupBy(fn ($item) => $item->year.'-'.$item->month)
            ->map(fn ($group) => (object) [
                'year' => $group->first()->year,
                'month' => $group->first()->month,
                'bales_produced' => $group->sum('bales_produced'),
                'bales_expected' => $group->sum('bales_expected'),
                'last_date' => $group->max('last_date'),
            ]);

        $lastBalesInStockData = DB::table('pulse_summary_mini_bales')
            ->select('shift_date', 'bales_in_stock')
            ->where('team_id', $currentTeamId)
            ->whereIn('shift_date', $fibreProduction->pluck('last_date'))
            ->get()->keyBy('shift_date');

        return $fibreProduction->map(function ($item) use ($lastBalesInStockData) {
            return [
                'period' => Carbon::create($item->year, $item->month)->format('M Y'),
                'bales_produced' => round($item->bales_produced),
                'bales_expected' => round($item->bales_expected),
                'bales_in_stock' => round($lastBalesInStockData->get($item->last_date)?->bales_in_stock),
            ];
        });
    }

    private function getDailySummary($currentTeamId, $selectedDate)
    {
        // No raw hourly device data for this day — return shift-level breakdown (not hourly)
        $summaryRows = DB::table('pulse_summary_mini_bales')
            ->select('bales_produced', 'bales_expected', 'bales_in_stock')
            ->where('team_id', $currentTeamId)
            ->whereRaw('DATE(shift_date) = ?', [$selectedDate->toDateString()])
            ->orderBy('id')
            ->get();

        $totalProduced = $summaryRows->sum('bales_produced');
        $totalExpected = $summaryRows->sum('bales_expected');
        $closingStock = MiniBaleProduction::closingStockAt($currentTeamId, $selectedDate->toDateString());

        $dayData = collect([[
            'period' => $selectedDate->format('M j, Y'),
            'bales_produced' => round($totalProduced, 1),
            'bales_expected' => round($totalExpected, 1),
            'bales_in_stock' => $closingStock,
        ]]);

        return [
            'data' => $summaryRows->isEmpty() ? collect([[
                'period' => $selectedDate->format('M j, Y'),
                'bales_produced' => 0,
                'bales_expected' => 0,
                'bales_in_stock' => 0,
            ]]) : $dayData,
            'total_bales_produced' => round($totalProduced, 1),
            'total_bales_expected' => round($totalExpected, 1),
            'last_bales_in_stock' => round($closingStock),
        ];
    }
}
