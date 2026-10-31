<?php

namespace App\Services\Pulse\Dashboard;

use App\Models\Pulse\Shift;
use App\Services\Pulse\FibreShiftDayTotals;
use App\Support\Pulse\CounterSeed;
use App\Support\Pulse\ProductionCounter;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FibreProductionService
{
    public function __construct(private readonly FibreShiftDayTotals $shiftDayTotals) {}

    public function get($startDate, $endDate = null, $type = 'day')
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $shiftDetails = $this->getShiftDetails($currentTeamId);

        // Exactly the same behavior as before
        $shiftMap = $shiftDetails->pluck('name', 'id');

        // [ [19 => 'Shift 1'], [21 => 'Shift 2'], ... ]
        $shifts = $shiftMap->map(fn ($name, $id) => [$id => $name])->values();

        // [19, 21, ...]
        $shiftIds = $shiftMap->keys()->values();

        if ($type === 'day') {
            $shiftStartTime = Shift::anchorForTeam($currentTeamId);
            $production = $this->getProductionByDay($currentTeamId, $startDate, $shiftStartTime, $shiftDetails);

            $totalBalesProduced = $production['total_bales_produced'] ?? 0;
            $totalBalesWeighed = $production['total_bales_weighed'] ?? 0;
            $totalEbPrice = $production['total_eb_price'] ?? 0;
            $totalWeight = $production['total_weight'] ?? 0;
            $shiftSummary = $production['shiftSummary'] ?? [];
            $productionData = $production['data'] ?? [];
        } else {
            if ($type === 'year') {
                $production = $this->getProductionByYear($currentTeamId, $shiftIds, $startDate, $endDate);
            } else {
                $production = $this->getProductionByDateRange($currentTeamId, $shiftIds, $startDate, $endDate);
            }

            // Totals
            $totalBalesProduced = $production->sum(function ($row) {
                return collect($row['shifts'])->sum('bales_produced');
            });

            $totalBalesWeighed = $production->sum(function ($row) {
                return collect($row['shifts'])->sum('bales_weighed');
            });

            $totalEbPrice = $production->sum(function ($row) {
                return collect($row['shifts'])->sum('eb_price');
            });

            $totalWeight = $production->sum(function ($row) {
                return collect($row['shifts'])->sum('weight');
            });

            // Initialize per-shift totals
            $shiftSummary = [];
            foreach ($shiftMap->keys() as $shiftId) {
                $shiftSummary[$shiftId] = [
                    'bales_produced' => 0,
                    'bales_weighed' => 0,
                    'weight' => 0,
                    'eb_price' => 0,
                ];
            }

            // Aggregate
            foreach ($production as $periodRow) {
                foreach ($periodRow['shifts'] ?? [] as $shift) {
                    $id = $shift['shift_id'];
                    $shiftSummary[$id]['bales_produced'] += $shift['bales_produced'] ?? 0;
                    $shiftSummary[$id]['bales_weighed'] += $shift['bales_weighed'] ?? 0;
                    $shiftSummary[$id]['weight'] += $shift['weight'] ?? 0;
                    $shiftSummary[$id]['eb_price'] += $shift['eb_price'] ?? 0;
                }
            }

            $productionData = $production->isEmpty() ? [] : $production;
        }

        return [
            'data' => $productionData,
            'shifts' => $shifts,
            'total_bales_produced' => round($totalBalesProduced, 1),
            'total_bales_weighed' => round($totalBalesWeighed, 1),
            'total_eb_price' => round($totalEbPrice, 1),
            'total_weight' => round($totalWeight, 1),
            'shiftSummary' => $shiftSummary,
        ];
    }

    private function getProductionByDay($teamId, $selectedDate, $shiftStartTime, $shiftDetails)
    {
        $date = $selectedDate instanceof Carbon ? $selectedDate->toDateString() : Carbon::parse($selectedDate)->toDateString();
        $isWholeHourStart = Carbon::parse($shiftStartTime)->minute === 0;
        $startOfDay = Carbon::parse($date)->setTimeFromTimeString($shiftStartTime);
        $endOfDay = $startOfDay->copy()->addHours(24)->subSecond();

        $deviceIds = $this->getDeviceIds($teamId);
        $ebPriceValue = DB::table('pulse_settings')
            ->where('key', 'RS_PER_EB_UNIT')
            ->where('team_id', $teamId)
            ->value('value');

        $bucketOf = function ($datetime) use ($startOfDay): string {
            $slotIndex = (int) floor($startOfDay->diffInMinutes(Carbon::parse($datetime), false) / 60);

            return $startOfDay->copy()->addHours($slotIndex)->format('Y-m-d H:i');
        };

        $rawProductionRows = DB::table('pulse_device_data_fibre_bales')
            ->whereIn('device_id', $deviceIds)
            ->where('is_duplicate', 0)
            ->whereBetween('bale_datetime', [$startOfDay, $endOfDay])
            ->orderBy('bale_datetime')
            ->get();

        /*
         * An hour's bar is how far the machine's counter travelled in it, not how many rows
         * arrived: a lost upload costs a row but never a bale, and the counter is what the
         * nightly summary — and so every shift and day figure beside these bars — is built
         * from. Walking the readings in order rather than taking each hour's own span is what
         * keeps the bars adding up to those figures.
         */
        // The bars open where the shift and day figures beside them do: on the reading before
        // the day, so a machine that ran overnight is not credited a single bale for it.
        $seeds = CounterSeed::before(
            'pulse_device_data_fibre_bales',
            'bale_datetime',
            ['bales' => 'bales_counter', 'eb' => 'eb_units_counter'],
            $deviceIds,
            $startOfDay,
            $shiftStartTime,
            'fibre hourly',
        );
        $baleSeeds = array_map(fn (CounterSeed $seed) => $seed->for('bales'), $seeds);
        $ebSeeds = array_map(fn (CounterSeed $seed) => $seed->for('eb'), $seeds);

        $producedPerHour = ProductionCounter::bucketedPerDevice(
            $rawProductionRows,
            fn ($row) => (int) $row->device_id,
            fn ($row) => $bucketOf($row->bale_datetime),
            fn ($row) => (int) $row->bales_counter,
            $baleSeeds
        );

        // Energy by the same walk, for the same reason: an hour's own min/max span invented a
        // unit per hour and dropped whatever the meter did between them.
        $ebPerHour = ProductionCounter::bucketedPerDevice(
            $rawProductionRows,
            fn ($row) => (int) $row->device_id,
            fn ($row) => $bucketOf($row->bale_datetime),
            fn ($row) => (int) $row->eb_units_counter,
            $ebSeeds
        );

        $weighedPerHour = DB::table('pulse_device_data_fibre_mega_bales_weight')
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('bale_weight_datetime', [$startOfDay, $endOfDay])
            ->get()
            ->groupBy(fn ($row) => $bucketOf($row->bale_weight_datetime))
            ->map(fn ($group) => [
                'bales_weighed' => $group->count(),
                'weight' => $group->sum('bale_weight'),
            ]);

        $hourlyData = collect();
        $currentStart = $startOfDay->copy();

        while ($currentStart->lessThan($endOfDay)) {
            $intervalKey = $currentStart->format('Y-m-d H:i');

            $balesProduced = $producedPerHour[$intervalKey] ?? 0;
            $ebPrice = round(($ebPerHour[$intervalKey] ?? 0) * $ebPriceValue, 1);
            $weighed = $weighedPerHour->get($intervalKey);
            $balesWeighed = $weighed['bales_weighed'] ?? 0;
            $weightKg = $weighed['weight'] ?? 0;

            $startFormatted = $currentStart->format($isWholeHourStart ? 'g a' : 'g:i a');
            $endFormatted = $currentStart->copy()->addHour()->format($isWholeHourStart ? 'g a' : 'g:i a');

            $hourlyData->push([
                'period' => $startFormatted.' - '.$endFormatted,
                'bales_produced' => $balesProduced,
                'bales_weighed' => $balesWeighed,
                'total_weight' => round($weightKg / 1000, 2),
                'average_weight' => round($balesWeighed > 0 ? $weightKg / $balesWeighed : 0, 1),
                'eb_price' => $ebPrice,
            ]);

            $currentStart->addHour();
        }

        return array_merge(
            ['data' => $hourlyData],
            $this->shiftTotalsForDay($teamId, $date, $shiftDetails)
        );
    }

    /**
     * The day's shift figures, from the one resolver every fibre screen reads.
     *
     * The hourly bars above have to be counted here — the summary table has no hourly
     * grain — but the shift and day figures beside them must be the same numbers the IoT
     * Insights calendars print, so they are not recomputed here at all.
     *
     * @return array{shiftSummary: array<int, array<string, float>>, total_bales_produced: int,
     *               total_bales_weighed: int, total_weight: float, total_eb_price: float}
     */
    private function shiftTotalsForDay($teamId, string $date, $shiftDetails): array
    {
        $totals = $this->shiftDayTotals->forDate($teamId, $date);

        // Anything the resolver could not place on an active shift is pooled on 0, which the
        // dashboard renders as the unattributed remainder rather than dropping.
        $shiftSummary = [0 => ['bales_produced' => 0, 'bales_weighed' => 0, 'weight' => 0, 'eb_price' => 0]];

        foreach ($shiftDetails as $shift) {
            $shiftSummary[$shift->id] = ['bales_produced' => 0, 'bales_weighed' => 0, 'weight' => 0, 'eb_price' => 0];
        }

        foreach ($totals as $row) {
            $shiftId = array_key_exists($row->shift_id, $shiftSummary) ? $row->shift_id : 0;

            $shiftSummary[$shiftId]['bales_produced'] += $row->bales_produced;
            $shiftSummary[$shiftId]['bales_weighed'] += $row->bales_weighed;
            // `weight` is resolved in kilograms; the dashboard works in tonnes throughout.
            $shiftSummary[$shiftId]['weight'] += round($row->weight / 1000, 2);
            $shiftSummary[$shiftId]['eb_price'] += $row->eb_price;
        }

        return [
            'shiftSummary' => $shiftSummary,
            'total_bales_produced' => (int) $totals->sum('bales_produced'),
            'total_bales_weighed' => (int) $totals->sum('bales_weighed'),
            'total_weight' => round($totals->sum('weight') / 1000, 1),
            'total_eb_price' => round($totals->sum('eb_price'), 1),
        ];
    }

    private function getProductionByDateRange($teamId, $shifts, $start, $end)
    {
        $deviceIds = $this->getDeviceIds($teamId);
        $diffInDays = $start->diffInDays($end);

        if ($diffInDays > 91 || ($diffInDays > 31 && $start->isStartOfMonth() && $end->copy()->isSameDay($end->copy()->endOfMonth()))) {
            return $this->getMonthlyProduction($deviceIds, $shifts, $start, $end);
        }

        if ($diffInDays > 31) {
            return $this->getWeeklyProduction($deviceIds, $shifts, $start, $end);
        }

        return $this->getDailyProduction($deviceIds, $shifts, $start, $end);
    }

    private function getMonthlyProduction($deviceIds, $shifts, $start, $end)
    {
        return collect(CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end->copy()->startOfMonth())->toArray())
            ->map(function ($monthDate) use ($deviceIds, $shifts, $start, $end) {
                $monthKey = $monthDate->format('Y-m');
                $monthStart = Carbon::parse($monthKey.'-01')->startOfMonth();
                $monthEnd = Carbon::parse($monthKey.'-01')->endOfMonth();

                $clampedStart = $monthStart->lt($start) ? $start->copy() : $monthStart;
                $clampedEnd = $monthEnd->gt($end) ? $end->copy() : $monthEnd;

                $clampedStartStr = $clampedStart->format('Y-m-d');
                $clampedEndStr = $clampedEnd->format('Y-m-d');

                $production = $this->queryProduction($deviceIds, $clampedStartStr, $clampedEndStr);

                return $this->buildPeriodResult(
                    Carbon::parse($monthStart)->format('M Y'),
                    $production,
                    $shifts
                );
            })
            ->values();
    }

    private function getWeeklyProduction($deviceIds, $shifts, $start, $end)
    {
        return collect(CarbonPeriod::create($start, $end)->toArray())
            ->chunk(7)
            ->map(function ($weekDates) use ($deviceIds, $shifts) {
                $weekStart = $weekDates->first()->copy()->startOfDay();
                $weekEnd = $weekDates->last()->copy()->endOfDay();
                $production = $this->queryProduction($deviceIds, $weekStart->toDateString(), $weekEnd->toDateString());
                $period = $weekStart->format('M j').' - '.$weekEnd->format('M j');

                return $this->buildPeriodResult($period, $production, $shifts);
            });
    }

    private function getDailyProduction($deviceIds, $shifts, $start, $end)
    {
        $datesInRange = collect(CarbonPeriod::create($start, $end)->toArray())
            ->map(fn ($date) => $date->format('Y-m-d'));

        $productionData = DB::table('pulse_summary_fibre_mega_bales')
            ->select('shift_date', 'shift_id',
                DB::raw('SUM(bales_produced) as bales_produced'),
                DB::raw('COALESCE(SUM(bales_weighed), 0) as bales_weighed'),
                DB::raw('COALESCE(SUM(weight), 0) as weight'),
                DB::raw('SUM(eb_price) as eb_price')
            )
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('shift_date', 'shift_id')
            ->get()
            ->groupBy('shift_date');

        return $datesInRange->map(function ($date) use ($productionData, $shifts) {
            $production = ($productionData[$date] ?? collect())->keyBy('shift_id');

            return $this->buildPeriodResult(
                Carbon::parse($date)->format('M j'),
                $production,
                $shifts
            );
        });
    }

    // Shared query helper used by monthly and weekly
    private function queryProduction($deviceIds, $dateFrom, $dateTo)
    {
        return DB::table('pulse_summary_fibre_mega_bales')
            ->select('shift_id',
                DB::raw('SUM(bales_produced) as bales_produced'),
                DB::raw('SUM(bales_weighed) as bales_weighed'),
                DB::raw('SUM(eb_price) as eb_price'),
                DB::raw('SUM(weight) as weight')
            )
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('shift_date', [$dateFrom, $dateTo])
            ->groupBy('shift_id')
            ->get()
            ->keyBy('shift_id');
    }

    // Shared result builder used by all three groupings
    private function buildPeriodResult($period, $production, $shifts)
    {
        $totalWeight = $production->sum(fn ($row) => (float) ($row->weight ?? 0));
        $totalBalesWeighed = $production->sum(fn ($row) => (int) ($row->bales_weighed ?? 0));
        $totalWeightInTons = $totalWeight / 1000;
        $avgWeightInKgs = $totalBalesWeighed > 0 ? ($totalWeightInTons * 1000) / $totalBalesWeighed : 0;

        return [
            'period' => $period,
            'shifts' => $shifts->map(function ($shiftId) use ($production) {
                $record = $production->get($shiftId);

                return [
                    'shift_id' => $shiftId,
                    'bales_produced' => (int) ($record->bales_produced ?? 0),
                    'bales_weighed' => (int) ($record->bales_weighed ?? 0),
                    'weight' => round(($record->weight ?? 0) / 1000, 2),
                    'eb_price' => (int) ($record->eb_price ?? 0),
                ];
            }),
            'total_weight' => round($totalWeightInTons, 2),
            'average_weight' => round($avgWeightInKgs, 1),
        ];
    }

    private function getProductionByYear($teamId, $shiftIds, $start, $end)
    {
        $deviceIds = $this->getDeviceIds($teamId);

        // Fetch production grouped by year, month, shift_id
        $rawProduction = DB::table('pulse_summary_fibre_mega_bales')
            ->select(
                DB::raw('YEAR(shift_date) as year'),
                DB::raw('MONTH(shift_date) as month'),
                'shift_id',
                DB::raw('SUM(bales_produced) as bales_produced'),
                DB::raw('COALESCE(SUM(bales_weighed),0) as bales_weighed'),
                DB::raw('SUM(eb_price) as eb_price'),
                DB::raw('COALESCE(SUM(weight), 0) as total_weight')
            )
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('shift_date', [$start, $end])
            ->groupBy(DB::raw('YEAR(shift_date)'), DB::raw('MONTH(shift_date)'), 'shift_id')
            ->get()
            ->groupBy(fn ($item) => $item->year.'-'.str_pad($item->month, 2, '0', STR_PAD_LEFT));

        // Loop over months and build the JSON
        return $rawProduction->map(function ($items, $ym) use ($shiftIds) {
            $byShift = $items->keyBy('shift_id');
            $period = Carbon::parse($ym.'-01')->format('M');

            $totalWeight = $items->sum('total_weight');
            $totalBalesWeighed = $items->sum(fn ($item) => (int) ($item->bales_weighed ?? 0));

            $totalWeightInTons = $totalWeight / 1000;
            $avgWeightInKgs = ($totalBalesWeighed != 0) ? ($totalWeightInTons * 1000) / $totalBalesWeighed : 0;

            return [
                'period' => $period,
                'shifts' => $shiftIds->map(function ($shiftId) use ($byShift) {
                    $record = $byShift->get($shiftId);

                    return [
                        'shift_id' => $shiftId,
                        'bales_produced' => (int) round($record->bales_produced ?? 0),
                        'bales_weighed' => (int) round($record->bales_weighed ?? 0),
                        'weight' => round(($record->total_weight ?? 0) / 1000, 1), // tons
                        'eb_price' => (int) round($record->eb_price ?? 0),
                    ];
                }),
                'total_weight' => round($totalWeightInTons, 2), // month total in tons
                'average_weight' => round($avgWeightInKgs, 1),
            ];
        })->values();
    }

    private function getDeviceIds($teamId)
    {
        return DB::table('pulse_devices')
            ->where('team_id', $teamId)
            ->pluck('device_id');
    }

    private function getShiftDetails($teamId)
    {
        return Shift::activeForTeam($teamId)
            ->select('id', 'team_id', 'name', 'start_time', 'end_time')
            ->orderedByOffset(Shift::anchorForTeam($teamId))
            ->get()
            ->keyBy('id');
    }
}
