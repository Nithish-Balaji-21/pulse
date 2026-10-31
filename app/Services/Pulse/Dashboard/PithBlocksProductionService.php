<?php

namespace App\Services\Pulse\Dashboard;

use App\Models\Pulse\Shift;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PithBlocksProductionService
{
    public function get($startDate, $endDate, $type)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        if ($startDate->isSameDay($endDate)) {
            $pithBlocks = $this->getProductionByDay($currentTeamId, $startDate);

            return [
                'data' => $pithBlocks['data'],
                'total_blocks' => $pithBlocks['total_blocks_expected'],
                'total_blocks_expected' => $pithBlocks['total_blocks_expected'],
                'total_blocks_produced' => $pithBlocks['total_blocks_produced'],
            ];
        } elseif ($type == 'year') {
            $pithBlocks = $this->getProductionByYear($currentTeamId, $startDate, $endDate);
        } else {
            $pithBlocks = $this->getProductionByDateRange($currentTeamId, $startDate, $endDate);
        }
        $totalBlocksExpected = $pithBlocks->sum('blocks_expected');
        $totalBlocksProduced = $pithBlocks->sum('blocks_produced');

        return [
            'data' => $pithBlocks->isEmpty() ? [] : $pithBlocks,
            'total_blocks' => $totalBlocksExpected,
            'total_blocks_expected' => $totalBlocksExpected,
            'total_blocks_produced' => $totalBlocksProduced,
        ];
    }

    private function calculateBlocksExpected(int $deviceId, Carbon $startTime, Carbon $endTime): float
    {
        $result = DB::selectOne('
            SELECT
                f.first_counter,
                l.last_counter
            FROM
                (
                    SELECT blocks_counter AS first_counter
                    FROM pulse_device_data_pith_blocks
                    WHERE device_id = ? AND block_datetime < ?
                    ORDER BY block_datetime DESC
                    LIMIT 1
                ) AS f
            JOIN
                (
                    SELECT blocks_counter AS last_counter
                    FROM pulse_device_data_pith_blocks
                    WHERE device_id = ? AND block_datetime <= ?
                    ORDER BY block_datetime DESC
                    LIMIT 1
                ) AS l ON 1=1
        ', [$deviceId, $startTime, $deviceId, $endTime]);

        if ($result && $result->last_counter >= $result->first_counter) {
            return round($result->last_counter - $result->first_counter, 1);
        }

        return 0;
    }

    private function getProductionByDay($currentTeamId, $selectedDate, $intervalHours = 1)
    {
        $hasShifts = Shift::activeForTeam($currentTeamId)->exists();

        if (! $hasShifts) {
            // fallback to midnight window
            $startOfDay = Carbon::parse($selectedDate)->startOfDay();
            $endOfDay = Carbon::parse($selectedDate)->endOfDay();
        } else {
            $startOfDay = Carbon::parse($selectedDate)
                ->setTimeFromTimeString(Shift::anchorForTeam($currentTeamId));

            $endOfDay = $startOfDay->copy()->addDay();
        }

        $teamDeviceIds = DB::table('pulse_devices')
            ->where('team_id', $currentTeamId)
            ->pluck('device_id');

        $deviceData = DB::table('pulse_device_data_pith_blocks')
            ->select('device_id', 'block_datetime', 'blocks_counter')
            ->whereIn('device_id', $teamDeviceIds)
            ->whereBetween('block_datetime', [$startOfDay, $endOfDay])
            ->where('is_duplicate', 0)
            ->orderBy('block_datetime')
            ->get()
            ->groupBy('device_id');

        $hourly = collect();
        $hourlyLimit = config('app.pith_blocks.hourly_production_limit');
        $intervalSeconds = $intervalHours * 3600;

        for ($block = 0; $block < 24 / $intervalHours; $block++) {
            $startTime = (clone $startOfDay)->addSeconds($block * $intervalSeconds);
            $endTime = (clone $startTime)->addSeconds($intervalSeconds - 1);

            $blocksExpected = 0;

            foreach ($teamDeviceIds as $deviceId) {
                $readings = $deviceData[$deviceId] ?? collect();
                $intervalExists = $readings->first(function ($item) use ($startTime, $endTime) {
                    $dt = Carbon::parse($item->block_datetime);

                    return $dt->between($startTime, $endTime);
                });

                if ($intervalExists) {
                    $blocksExpected += $this->calculateBlocksExpected($deviceId, $startTime, $endTime);
                }
            }

            // Skip this hour if production exceeds the limit
            if ($hourlyLimit && $blocksExpected > $hourlyLimit) {
                continue;
            }

            $hourly->push([
                'period' => $startTime->format($startTime->minute !== 0 ? 'g:i a' : 'g a')
                        .' - '
                        .$endTime->addSecond()->format($endTime->minute !== 0 ? 'g:i a' : 'g a'),
                'blocks' => $blocksExpected,
            ]);
        }

        $summaryTotal = DB::table('pulse_summary_pith_blocks')
            ->join('pulse_devices', 'pulse_summary_pith_blocks.device_id', '=', 'pulse_devices.device_id')
            ->where('pulse_devices.team_id', $currentTeamId)
            ->where('shift_date', $selectedDate)
            ->selectRaw('SUM(blocks_expected) as blocks_expected, SUM(blocks_produced) as blocks_produced')
            ->first();

        return [
            'data' => $hourly,
            'total_blocks_expected' => round($summaryTotal->blocks_expected ?? 0) ?: round($hourly->sum('blocks')),
            'total_blocks_produced' => round($summaryTotal->blocks_produced ?? 0),
            'total_blocks' => round($summaryTotal->blocks_expected ?? 0) ?: round($hourly->sum('blocks')),
        ];
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
            $monthlyBuckets = collect(CarbonPeriod::create($startDate->copy()->startOfMonth(), '1 month', $endDate->copy()->startOfMonth())->toArray())
                ->map(function ($monthDate) use ($currentTeamId, $startDate, $endDate) {
                    $monthKey = $monthDate->format('Y-m');
                    $monthStart = Carbon::parse($monthKey.'-01')->startOfMonth();
                    $monthEnd = Carbon::parse($monthKey.'-01')->endOfMonth();

                    $clampedStart = $monthStart->lt($startDate) ? $startDate->copy() : $monthStart;
                    $clampedEnd = $monthEnd->gt($endDate) ? $endDate->copy() : $monthEnd;

                    $clampedStartStr = $clampedStart->format('Y-m-d');
                    $clampedEndStr = $clampedEnd->format('Y-m-d');

                    $aggregated = DB::table('pulse_summary_pith_blocks')
                        ->join('pulse_devices', 'pulse_summary_pith_blocks.device_id', '=', 'pulse_devices.device_id')
                        ->select(
                            DB::raw("'{$clampedStartStr} to {$clampedEndStr}' as period"),
                            DB::raw('SUM(blocks_expected) as total_blocks_expected'),
                            DB::raw('SUM(blocks_produced) as total_blocks_produced')
                        )
                        ->where('pulse_devices.team_id', $currentTeamId)
                        ->whereBetween('shift_date', [$clampedStartStr, $clampedEndStr])
                        ->groupBy('shift_date')
                        ->havingRaw('SUM(blocks_expected) <= ?', [config('app.pith_blocks.daily_production_limit')])
                        ->get();

                    $totalExpected = $aggregated->sum('total_blocks_expected');
                    $totalProduced = $aggregated->sum('total_blocks_produced');

                    return [
                        'period' => Carbon::parse($monthStart)->format('M Y'),
                        'blocks' => round($totalExpected ?? 0, 1),
                        'blocks_expected' => round($totalExpected ?? 0, 1),
                        'blocks_produced' => round($totalProduced ?? 0, 1),
                    ];
                })
                ->values();

            return $monthlyBuckets;
        }

        if ($diffInDays > 14) {
            // Chunk date range into weekly buckets
            $weeklyBuckets = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
                ->chunk(7)
                ->map(function ($weekDates) use ($currentTeamId) {
                    $weekStart = $weekDates->first()->format('Y-m-d');
                    $weekEnd = $weekDates->last()->format('Y-m-d');

                    $aggregated = DB::table('pulse_summary_pith_blocks')
                        ->join('pulse_devices', 'pulse_summary_pith_blocks.device_id', '=', 'pulse_devices.device_id')
                        ->select(
                            DB::raw("'{$weekStart} to {$weekEnd}' as period"),
                            DB::raw('SUM(blocks_expected) as total_blocks_expected'),
                            DB::raw('SUM(blocks_produced) as total_blocks_produced')
                        )
                        ->where('pulse_devices.team_id', $currentTeamId)
                        ->whereBetween('shift_date', [$weekStart, $weekEnd])
                        ->groupBy('shift_date')
                        ->havingRaw('SUM(blocks_expected) <= ?', [config('app.pith_blocks.daily_production_limit')])
                        ->get();

                    $totalExpected = $aggregated->sum('total_blocks_expected');
                    $totalProduced = $aggregated->sum('total_blocks_produced');

                    return [
                        'period' => Carbon::parse($weekStart)->format('M j').' - '.Carbon::parse($weekEnd)->format('M j'),
                        'blocks' => round($totalExpected ?? 0, 1),
                        'blocks_expected' => round($totalExpected ?? 0, 1),
                        'blocks_produced' => round($totalProduced ?? 0, 1),
                    ];
                });

            return $weeklyBuckets;
        }

        // Daily view fallback
        $datesInRange = collect(CarbonPeriod::create($startDate, $endDate)->toArray())
            ->map(fn ($date) => $date->format('Y-m-d'));

        $blockData = DB::table('pulse_summary_pith_blocks')
            ->join('pulse_devices', 'pulse_summary_pith_blocks.device_id', '=', 'pulse_devices.device_id')
            ->select(
                'shift_date as period',
                DB::raw('SUM(blocks_expected) as total_blocks_expected'),
                DB::raw('SUM(blocks_produced) as total_blocks_produced')
            )
            ->where('pulse_devices.team_id', $currentTeamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->groupBy('shift_date')
            ->havingRaw('SUM(blocks_expected) <= ?', [config('app.pith_blocks.daily_production_limit')])
            ->orderBy('shift_date')
            ->get();

        $blockMap = $blockData->pluck(null, 'period');

        return $datesInRange->map(fn ($date) => [
            'period' => Carbon::parse($date)->format('M j'),
            'blocks' => $blockMap[$date]->total_blocks_expected ?? 0,
            'blocks_expected' => $blockMap[$date]->total_blocks_expected ?? 0,
            'blocks_produced' => $blockMap[$date]->total_blocks_produced ?? 0,
        ]);
    }

    private function getProductionByYear($currentTeamId, $startDate, $endDate)
    {
        return DB::table('pulse_summary_pith_blocks')
            ->join('pulse_devices', 'pulse_summary_pith_blocks.device_id', '=', 'pulse_devices.device_id')
            ->select(
                DB::raw('YEAR(shift_date) AS year'),
                DB::raw('MONTH(shift_date) AS month'),
                DB::raw('SUM(blocks_expected) AS total_blocks_expected'),
                DB::raw('SUM(blocks_produced) AS total_blocks_produced')
            )
            ->where('pulse_devices.team_id', $currentTeamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->groupBy(DB::raw('YEAR(shift_date), MONTH(shift_date), shift_date'))
            ->havingRaw('SUM(blocks_expected) <= ?', [config('app.pith_blocks.daily_production_limit')])
            ->orderBy(DB::raw('YEAR(shift_date)'))
            ->orderBy(DB::raw('MONTH(shift_date)'))
            ->get()
            ->groupBy(fn ($item) => $item->year.'-'.$item->month)
            ->map(fn ($group) => [
                'period' => Carbon::create($group->first()->year, $group->first()->month)->format('M Y'),
                'blocks' => $group->sum('total_blocks_expected'),
                'blocks_expected' => $group->sum('total_blocks_expected'),
                'blocks_produced' => $group->sum('total_blocks_produced'),
            ])
            ->values();
    }
}
