<?php

namespace App\Services\Pulse\Dashboard;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WaterMeterConsumptionService
{
    public function get($startDate, $endDate, $type, $fieldId = null)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $fieldId = $fieldId ?? config('app.water_consumption.total');

        if ($startDate->isSameDay($endDate)) {
            $waterLiters = $this->getProductionByDay($currentTeamId, $startDate, 1, $fieldId);

            return [
                'data' => $waterLiters['data'],
                'total_litres' => $waterLiters['total_litres'],
            ];
        } elseif ($type == 'year') {
            $waterLiters = $this->getProductionByYear($currentTeamId, $startDate, $endDate, $fieldId);
        } else {
            $waterLiters = $this->getProductionByDateRange($currentTeamId, $startDate, $endDate, $fieldId);
        }

        $totalLitres = $waterLiters->sum('litres');

        return [
            'data' => $waterLiters->isEmpty() ? [] : $waterLiters,
            'total_litres' => $totalLitres,
        ];
    }

    private function calculateLitresConsumed(int $deviceId, Carbon $startTime, Carbon $endTime, $fieldId): float
    {
        $result = DB::selectOne('
            SELECT
                f.first_counter,
                l.last_counter
            FROM
                (
                    SELECT litres_counter AS first_counter
                    FROM pulse_device_data_water_meters
                    WHERE device_id = ? AND meter_datetime < ? AND field_id = ?
                    ORDER BY meter_datetime DESC
                    LIMIT 1
                ) AS f
            JOIN
                (
                    SELECT litres_counter AS last_counter
                    FROM pulse_device_data_water_meters
                    WHERE device_id = ? AND meter_datetime <= ? AND field_id = ?
                    ORDER BY meter_datetime DESC
                    LIMIT 1
                ) AS l ON 1=1
        ', [$deviceId, $startTime, $fieldId, $deviceId, $endTime, $fieldId]);

        if ($result && $result->last_counter >= $result->first_counter) {
            return round($result->last_counter - $result->first_counter, 1);
        }

        return 0;
    }

    private function getProductionByDay($currentTeamId, $selectedDate, $intervalHours = 1, $fieldId = null)
    {
        $fieldId = $fieldId ?? config('app.water_consumption.total');
        $startOfDay = Carbon::parse($selectedDate)->startOfDay();
        $endOfDay = Carbon::parse($selectedDate)->endOfDay();

        $teamDeviceIds = DB::table('pulse_devices')
            ->where('team_id', $currentTeamId)
            ->pluck('device_id');

        $deviceData = DB::table('pulse_device_data_water_meters')
            ->select('device_id', 'meter_datetime', 'litres_counter')
            ->whereIn('device_id', $teamDeviceIds)
            ->where('field_id', $fieldId)
            ->whereBetween('meter_datetime', [$startOfDay, $endOfDay])
            ->where('is_duplicate', 0)
            ->orderBy('meter_datetime')
            ->get()
            ->groupBy('device_id');

        $intervalSeconds = $intervalHours * 3600;
        $hourly = collect();
        $hourlyLimit = config('app.water_consumption.hourly_consumption_limit');

        for ($block = 0; $block < 24 / $intervalHours; $block++) {
            $startTime = (clone $startOfDay)->addSeconds($block * $intervalSeconds);
            $endTime = (clone $startTime)->addSeconds($intervalSeconds - 1);

            $litres = 0;

            foreach ($teamDeviceIds as $deviceId) {
                $readings = $deviceData[$deviceId] ?? collect();
                $intervalExists = $readings->first(function ($item) use ($startTime, $endTime) {
                    $dt = Carbon::parse($item->meter_datetime);

                    return $dt->between($startTime, $endTime);
                });

                if ($intervalExists) {
                    $litres += $this->calculateLitresConsumed($deviceId, $startTime, $endTime, $fieldId);
                }
            }

            // If hourlyLimit is defined and litres exceeds it, skip adding this hour to the output
            if ($hourlyLimit && $litres > $hourlyLimit) {
                continue;
            }

            $hourly->push([
                'period' => $startTime->format('g a').' - '.$endTime->addSecond()->format('g a'),
                'litres' => $litres,
            ]);
        }

        return [
            'data' => $hourly,
            'total_litres' => round($hourly->sum('litres')),
        ];
    }

    private function getProductionByDateRange($currentTeamId, $startDate, $endDate, $fieldId = null)
    {
        $fieldId = $fieldId ?? config('app.water_consumption.total');
        $diffInDays = $startDate->diffInDays($endDate);
        $isExactOneMonth = $startDate->isStartOfMonth()
            && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth())
            && $diffInDays <= 31;

        $dailyConsumptionLimit = config('app.water_consumption.daily_consumption_limit');

        if (
            $diffInDays > 63 ||
            ($diffInDays > 14 && ! $isExactOneMonth && $startDate->isStartOfMonth() && $endDate->copy()->isSameDay($endDate->copy()->endOfMonth()))
        ) {
            $periodStart = $startDate->copy()->startOfMonth();
            $periodEnd = $endDate->copy()->startOfMonth();

            $monthlyBuckets = collect(CarbonPeriod::create($periodStart, '1 month', $periodEnd)->toArray())
                ->map(function ($monthDate) use ($currentTeamId, $fieldId, $dailyConsumptionLimit, $startDate, $endDate) {
                    $monthKey = $monthDate->format('Y-m');

                    $monthStart = Carbon::parse($monthKey.'-01')->startOfMonth();
                    $monthEnd = Carbon::parse($monthKey.'-01')->endOfMonth();

                    // Clamp to actual startDate/endDate
                    $clampedStart = $monthStart->lt($startDate) ? $startDate->copy() : $monthStart;
                    $clampedEnd = $monthEnd->gt($endDate) ? $endDate->copy() : $monthEnd;

                    $clampedStartStr = $clampedStart->format('Y-m-d');
                    $clampedEndStr = $clampedEnd->format('Y-m-d');

                    $aggregated = DB::table('pulse_summary_water_meter')
                        ->join('pulse_devices', 'pulse_summary_water_meter.device_id', '=', 'pulse_devices.device_id')
                        ->select(
                            DB::raw("'{$clampedStartStr} to {$clampedEndStr}' as period"),
                            DB::raw('SUM(litres_consumed) as total_litres')
                        )
                        ->where('pulse_devices.team_id', $currentTeamId)
                        ->where('pulse_summary_water_meter.field_id', $fieldId)
                        ->where('pulse_summary_water_meter.litres_consumed', '<=', $dailyConsumptionLimit)
                        ->whereBetween('date', [$clampedStartStr, $clampedEndStr])
                        ->first();

                    return [
                        'period' => Carbon::parse($monthKey.'-01')->format('M Y'),
                        'litres' => round($aggregated->total_litres ?? 0, 2),
                    ];
                })
                ->values();

            return $monthlyBuckets;
        }

        if ($diffInDays > 14) {
            $weeklyBuckets = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
                ->chunk(7)
                ->map(function ($weekDates) use ($currentTeamId, $fieldId, $dailyConsumptionLimit) {
                    $weekStart = $weekDates->first()->format('Y-m-d');
                    $weekEnd = $weekDates->last()->format('Y-m-d');

                    $aggregated = DB::table('pulse_summary_water_meter')
                        ->join('pulse_devices', 'pulse_summary_water_meter.device_id', '=', 'pulse_devices.device_id')
                        ->select(
                            DB::raw("'{$weekStart} to {$weekEnd}' as period"),
                            DB::raw('SUM(litres_consumed) as total_litres')
                        )
                        ->where('pulse_devices.team_id', $currentTeamId)
                        ->where('pulse_summary_water_meter.field_id', $fieldId)
                        ->where('pulse_summary_water_meter.litres_consumed', '<=', $dailyConsumptionLimit)
                        ->whereBetween('date', [$weekStart, $weekEnd])
                        ->first();

                    return [
                        'period' => Carbon::parse($weekStart)->format('M j').' - '.Carbon::parse($weekEnd)->format('M j'),
                        'litres' => round($aggregated->total_litres ?? 0, 2),
                    ];
                });

            return $weeklyBuckets;
        }

        // Daily breakdown for <= 14 days
        $datesInRange = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
            ->map(fn ($date) => $date->format('Y-m-d'));

        $waterData = DB::table('pulse_summary_water_meter')
            ->join('pulse_devices', 'pulse_summary_water_meter.device_id', '=', 'pulse_devices.device_id')
            ->select(
                'date as period',
                DB::raw('SUM(litres_consumed) as total_litres')
            )
            ->where('pulse_devices.team_id', $currentTeamId)
            ->where('pulse_summary_water_meter.field_id', $fieldId)
            ->where('pulse_summary_water_meter.litres_consumed', '<=', $dailyConsumptionLimit)
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $waterMap = $waterData->pluck(null, 'period');

        return $datesInRange->map(fn ($date) => [
            'period' => Carbon::parse($date)->format('M j'),
            'litres' => $waterMap[$date]->total_litres ?? 0,
        ]);
    }

    private function getProductionByYear($currentTeamId, $startDate, $endDate, $fieldId = null)
    {
        $fieldId = $fieldId ?? config('app.water_consumption.total');
        $dailyConsumptionLimit = config('app.water_consumption.daily_consumption_limit');

        return DB::table('pulse_summary_water_meter')
            ->join('pulse_devices', 'pulse_summary_water_meter.device_id', '=', 'pulse_devices.device_id')
            ->select(
                DB::raw('YEAR(date) AS year'),
                DB::raw('MONTH(date) AS month'),
                DB::raw('SUM(litres_consumed) AS total_litres')
            )
            ->where('pulse_devices.team_id', $currentTeamId)
            ->where('pulse_summary_water_meter.field_id', $fieldId)
            ->where('pulse_summary_water_meter.litres_consumed', '<=', $dailyConsumptionLimit)
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy(DB::raw('YEAR(date), MONTH(date)'))
            ->orderBy(DB::raw('YEAR(date)'))
            ->orderBy(DB::raw('MONTH(date)'))
            ->get()
            ->map(function ($item) {
                return [
                    'period' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'litres' => $item->total_litres,
                ];
            });
    }
}
