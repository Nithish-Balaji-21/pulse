<?php

namespace App\Services\Pulse\Dashboard;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PithLoadProductionService
{
    public function get($startDate, $endDate = null, $type = 'day')
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        // 1. Get shift breakdown data for the full date range. This is always required.
        $shiftBreakdown = $this->getProductionByShift($currentTeamId, $startDate, $endDate);

        // 2. Get data for the main chart, which is shown for date ranges but not single days.
        $pithProductionForChart = collect([]);
        if ($type == 'year') {
            $pithProductionForChart = $this->getProductionByYear($currentTeamId, $startDate, $endDate);
        } elseif ($type != 'day') {
            $pithProductionForChart = $this->getProductionByDateRange($currentTeamId, $startDate, $endDate);
        }

        // 3. Calculate total summary from the aggregated shift data.
        $totalExpectedLoads = round(collect($shiftBreakdown)->sum(fn ($shift) => $shift['expected'] ?? 0), 1);
        $totalActualLoads = round(collect($shiftBreakdown)->sum(fn ($shift) => $shift['actual'] ?? 0), 1);
        $totalEbPrice = round(collect($shiftBreakdown)->sum(fn ($shift) => $shift['eb_price'] ?? 0), 1);

        return [
            'shifts' => $shiftBreakdown, // Always return shift data for the horizontal bars
            'data' => $pithProductionForChart->isEmpty() ? [] : $pithProductionForChart, // Data for the date-range trend chart
            'expected' => $totalExpectedLoads, // For summary card
            'total' => $totalActualLoads, // For summary card
            'total_eb_price' => $totalEbPrice,
        ];
    }

    private function getProductionByShift($currentTeamId, $startDate, $endDate)
    {
        $productionData = DB::table('pulse_summary_pith_productions')
            ->select(
                'shift_name as period',
                DB::raw('SUM(eb_price) as total_eb_price'),
                DB::raw('SUM(cft_expected) as total_loads_expected'),
                DB::raw('SUM(cft_produced) as total_loads_produced')
            )
            ->where('team_id', $currentTeamId)
            ->whereBetween('shift_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('shift_name')
            ->get();

        $shifts = [];

        foreach ($productionData as $row) {
            $period = trim((string) ($row->period ?? ''));

            if ($period === '') {
                continue;
            }

            $actuals = $row->total_loads_produced ?? 0;
            $expected = $row->total_loads_expected ?? 0;
            $ebPrice = $row->total_eb_price ?? 0;

            $shifts[$period] = [
                'actual' => $actuals,
                'expected' => round($expected, 1),
                'eb_price' => round($ebPrice),
            ];
        }

        return $shifts;
    }

    private function getProductionByDateRange($currentTeamId, $startDate, $endDate)
    {
        $diffInDays = $startDate->diffInDays($endDate);
        $isExactOneMonth = $startDate->isStartOfMonth()
            && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth())
            && $diffInDays <= 31;

        if (
            $diffInDays > 63 ||
            ($diffInDays > 14 && ! $isExactOneMonth && $startDate->isStartOfMonth() && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth()))
        ) {
            // Monthly grouping
            $periodStart = $startDate->copy()->startOfMonth();
            $periodEnd = $endDate->copy()->startOfMonth();

            $months = collect(CarbonPeriod::create($periodStart, '1 month', $periodEnd)->toArray())
                ->map(function ($monthDate) use ($currentTeamId, $startDate, $endDate) {
                    $monthKey = $monthDate->format('Y-m');

                    // Clamp each month's range to the actual startDate/endDate
                    $monthStart = Carbon::parse($monthKey.'-01')->startOfMonth();
                    $monthEnd = Carbon::parse($monthKey.'-01')->endOfMonth();

                    $clampedStart = $monthStart->lt($startDate) ? $startDate->copy() : $monthStart;
                    $clampedEnd = $monthEnd->gt($endDate) ? $endDate->copy() : $monthEnd;

                    $production = DB::table('pulse_summary_pith_productions')
                        ->select(
                            DB::raw('SUM(eb_price) as total_eb_price'),
                            DB::raw('SUM(cft_expected) as total_loads_expected'),
                            DB::raw('SUM(cft_produced) as total_loads_produced')
                        )
                        ->where('team_id', $currentTeamId)
                        ->whereBetween('shift_date', [
                            $clampedStart->format('Y-m-d'),
                            $clampedEnd->format('Y-m-d'),
                        ])
                        ->first();

                    return [
                        'period' => Carbon::parse($monthKey.'-01')->format('M Y'),
                        'loads_expected' => round($production->total_loads_expected ?? 0, 1),
                        'loads_actuals' => $production->total_loads_produced ?? 0,
                        'eb_price' => round($production->total_eb_price ?? 0),
                    ];
                })
                ->values();

            return $months;
        }

        if ($diffInDays > 14) {
            // Weekly grouping
            $weeks = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
                ->chunk(7)
                ->map(function ($weekDates) use ($currentTeamId) {
                    $weekStart = $weekDates->first()->format('Y-m-d');
                    $weekEnd = $weekDates->last()->format('Y-m-d');

                    $production = DB::table('pulse_summary_pith_productions')
                        ->select(
                            DB::raw("'{$weekStart} to {$weekEnd}' as period"),
                            DB::raw('SUM(eb_price) as total_eb_price'),
                            DB::raw('SUM(cft_expected) as total_loads_expected'),
                            DB::raw('SUM(cft_produced) as total_loads_produced')
                        )
                        ->where('team_id', $currentTeamId)
                        ->whereBetween('shift_date', [$weekStart, $weekEnd])
                        ->first();

                    return [
                        'period' => Carbon::parse($weekStart)->format('M j').' - '.Carbon::parse($weekEnd)->format('M j'),
                        'loads_expected' => round($production->total_loads_expected ?? 0, 1),
                        'loads_actuals' => $production->total_loads_produced ?? 0,
                        'eb_price' => round($production->total_eb_price ?? 0),
                    ];
                });

            return $weeks;
        }

        // Daily grouping for <= 14 days
        $datesInRange = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
            ->map(fn ($date) => $date->format('Y-m-d'));

        $productionData = DB::table('pulse_summary_pith_productions')
            ->select(
                'shift_date as period',
                DB::raw('SUM(eb_price) as total_eb_price'),
                DB::raw('SUM(cft_expected) as total_loads_expected'),
                DB::raw('SUM(cft_produced) as total_loads_produced')
            )
            ->where('team_id', $currentTeamId)
            ->whereBetween('shift_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('shift_date')
            ->orderBy('shift_date')
            ->get();

        $productionMap = $productionData->pluck(null, 'period');

        return $datesInRange->map(fn ($date) => [
            'period' => Carbon::parse($date)->format('M j'),
            'loads_expected' => round(($productionMap[$date]->total_loads_expected ?? 0), 1),
            'loads_actuals' => ($productionMap[$date]->total_loads_produced ?? 0),
            'eb_price' => round($productionMap[$date]->total_eb_price ?? 0),
        ]);
    }

    private function getProductionByYear($currentTeamId, $startDate, $endDate)
    {
        return DB::table('pulse_summary_pith_productions')
            ->select(
                DB::raw('YEAR(shift_date) AS year'),
                DB::raw('MONTH(shift_date) AS month'),
                DB::raw('SUM(eb_price) AS total_eb_price'),
                DB::raw('SUM(cft_expected) AS total_loads_expected'),
                DB::raw('SUM(cft_produced) AS total_loads_produced')
            )
            ->where('team_id', $currentTeamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->groupBy(DB::raw('YEAR(shift_date), MONTH(shift_date)'))
            ->orderBy(DB::raw('YEAR(shift_date)'))
            ->orderBy(DB::raw('MONTH(shift_date)'))
            ->get()
            ->map(function ($item) {
                return [
                    'period' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'loads_expected' => round($item->total_loads_expected, 1),
                    'loads_actuals' => $item->total_loads_produced,
                    'eb_price' => round($item->total_eb_price),
                ];
            });
    }
}
