<?php

namespace App\Http\Controllers\Pulse\Dashboard\GrowBags;

use App\Http\Controllers\Pulse\Dashboard\DashboardController;
use App\Models\Pulse\Device;
use App\Models\Pulse\Shift;
use App\Services\Pulse\GrowbagShiftDayTotals;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardGrowBagsApiController extends DashboardController
{
    private array $shiftsCache = [];

    private array $shiftDetailsCache = [];

    private array $machinesCache = [];

    public function index(Request $request)
    {
        // do not call parent as that is for web only!
    }

    public function fetchSummaryData(Request $request, $format = null)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'growbags')
            ->where('status', 'active')
            ->value('device_id');

        if (! $deviceId) {
            return response()->json(['error' => 'No active growbags device found for team: '.$currentTeamId], 404);
        }

        $startDate = $request->input('start');
        $endDate = $request->input('end');
        $summary = $this->fetchDailySummary($currentTeamId, $startDate, $endDate);

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);

        return response()->json([
            'data' => $summary,
            'shift_start_time' => $shiftStartTime,
        ]);
    }

    public function fetchDetailedData(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceIds = Device::where('team_id', $currentTeamId)
            ->where('module', 'growbags')
            ->where('status', 'active')
            ->pluck('device_id');

        if ($deviceIds->isEmpty()) {
            return response()->json(['error' => 'No active growbags device found for team: '.$currentTeamId], 404);
        }

        $startDate = $request->input('start', now()->subDays(1)->toDateString());
        $endDate = $request->input('end', now()->subDays(1)->toDateString());

        $detailed = [
            'shiftData' => $this->fetchShiftData($currentTeamId, $startDate, $endDate),
            'hourlyData' => $this->fetchHourlyData($currentTeamId, $deviceIds, $startDate, $endDate),
            'dailyData' => $this->fetchDailyData($currentTeamId, $startDate, $endDate),
            'monthlyData' => $this->fetchMonthlyData($currentTeamId, $startDate, $endDate),
            'attendance' => $this->fetchAttandanceEvents($startDate, $endDate),
        ];

        return response()->json($detailed);
    }

    private function fetchDailySummary($teamId, $startDate, $endDate)
    {
        return DB::table('pulse_summary_growbags')
            ->select(
                DB::raw('shift_date as shift_date'),
                DB::raw('SUM(COALESCE(bags_produced, 0)) as growbags')
            )
            ->when($startDate !== null && $endDate !== null, function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('shift_date', [$startDate, $endDate]);
            })
            ->where('team_id', $teamId)
            ->groupBy('shift_date')
            ->orderBy('shift_date', 'asc')
            ->get();
    }

    private function fetchShiftData($teamId, $startDate, $endDate)
    {
        $shifts = $this->getShifts($teamId);
        $machines = $this->getMachines($teamId);

        $expectedShifts = [];
        foreach ($shifts as $shift) {
            $expectedShifts[$shift->id] = [
                'shift_id' => $shift->id,
                'shift_name' => $shift->name,
                'shift_growbags' => 0,
                'shift_eb_price' => 0,
                'machines' => $machines->map(function ($machine) {
                    return [
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->machine_name,
                        'growbags' => 0,
                        'eb_price' => 0,
                    ];
                })->values()->toArray(),
            ];
        }

        $shiftTotals = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->selectRaw('
                shift_id,
                MAX(shift_name) as shift_name,
                SUM(COALESCE(bags_produced, 0)) as growbags,
                SUM(eb_price) as eb_price
            ')
            ->groupBy('shift_id')
            ->get();

        $machineTotals = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->selectRaw('
                shift_id,
                growbag_machine_id,
                SUM(COALESCE(bags_produced, 0)) as growbags,
                SUM(eb_price) as eb_price
            ')
            ->groupBy('shift_id', 'growbag_machine_id')
            ->get()
            ->groupBy('shift_id');

        $merged = $expectedShifts;
        foreach ($shiftTotals as $row) {
            $shiftId = $row->shift_id;
            if (! isset($merged[$shiftId])) {
                continue;
            }

            $merged[$shiftId]['shift_name'] = $row->shift_name ?? $merged[$shiftId]['shift_name'];
            $merged[$shiftId]['shift_growbags'] = (int) $row->growbags;
            $merged[$shiftId]['shift_eb_price'] = (float) $row->eb_price;

            $machineRows = $machineTotals->get($shiftId) ?? collect();
            $machineMap = $machineRows->keyBy('growbag_machine_id');

            $machinesPayload = [];
            foreach ($machines as $machine) {
                $machineRow = $machineMap->get($machine->id);
                $machinesPayload[] = [
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->machine_name,
                    'growbags' => (int) ($machineRow->growbags ?? 0),
                    'eb_price' => (float) ($machineRow->eb_price ?? 0),
                ];
            }

            $merged[$shiftId]['machines'] = $machinesPayload;
        }

        $shiftsPayload = array_values($merged);

        $total = [
            'growbags' => array_sum(array_column($shiftsPayload, 'shift_growbags')),
            'eb_price' => array_sum(array_column($shiftsPayload, 'shift_eb_price')),
        ];

        if ($total['growbags'] == 0 && $total['eb_price'] == 0) {
            return [];
        }

        return [
            'shifts' => $shiftsPayload,
            'total' => $total,
        ];
    }

    /**
     * The day's hours, split by shift and machine.
     *
     * Read from {@see GrowbagShiftDayTotals} — the same resolver the growbags dashboard
     * draws its day view from — so the app and the web answer a request for one day with the
     * same numbers. This used to take each hour's own min/max span of the counter per
     * machine, which measured something else entirely: it charged every bucket a punch it
     * had not made, lost whatever the counter did between one hour's last reading and the
     * next hour's first, and, because it differenced each machine against a counter it does
     * not share, gave every machine the whole factory's travel.
     */
    private function fetchHourlyData($teamId, $deviceIds, $startDate, $endDate)
    {
        $shiftDetails = $this->getShifts($teamId);

        if ($shiftDetails->isEmpty()) {
            return [];
        }

        $machines = $this->getMachines($teamId);

        $shiftStartTime = Shift::anchorForTeam($teamId);
        $startOfDay = Carbon::parse($startDate)->setTimeFromTimeString($shiftStartTime);
        $endOfDay = $startOfDay->copy()->addHours(24)->subSecond();
        $isWholeHourStart = $startOfDay->minute === 0;

        $ebPriceValue = DB::table('pulse_settings')
            ->where('key', 'RS_PER_EB_UNIT')
            ->where('team_id', $teamId)
            ->value('value') ?? 0;

        $hourly = app(GrowbagShiftDayTotals::class)
            ->hourlyMachineBagsForDay($teamId, Carbon::parse($startDate)->toDateString());

        if ($hourly->isEmpty()) {
            return [];
        }

        $hourlyData = [];
        $currentStart = $startOfDay->copy();

        while ($currentStart->lessThan($endOfDay)) {
            $perShift = $hourly[$currentStart->format('Y-m-d H:i')] ?? [];

            $shiftsPayload = [];
            foreach ($shiftDetails as $shift) {
                $perMachine = $perShift[$shift->id] ?? [];

                $machinesPayload = [];
                $shiftGrowbags = 0;
                $shiftEbPrice = 0;

                foreach ($machines as $machine) {
                    $growbags = (int) ($perMachine[$machine->id]['growbags'] ?? 0);
                    $ebPrice = round(($perMachine[$machine->id]['eb'] ?? 0) * $ebPriceValue, 1);

                    $machinesPayload[] = [
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->machine_name,
                        'growbags' => $growbags,
                        'eb_price' => $ebPrice,
                    ];

                    $shiftGrowbags += $growbags;
                    $shiftEbPrice += $ebPrice;
                }

                $shiftsPayload[] = [
                    'shift_id' => $shift->id,
                    'shift_name' => $shift->name,
                    'shift_growbags' => $shiftGrowbags,
                    'shift_eb_price' => $shiftEbPrice,
                    'machines' => $machinesPayload,
                ];
            }

            $hourlyData[] = [
                'hour_interval' => $currentStart->format($isWholeHourStart ? 'g A' : 'g:i A'),
                'shifts' => $shiftsPayload,
            ];

            $currentStart->addHour();
        }

        return $hourlyData;
    }

    private function fetchDailyData($teamId, $startDate, $endDate)
    {
        $shifts = $this->getShifts($teamId);
        $machines = $this->getMachines($teamId);

        $results = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->selectRaw('
                shift_date,
                shift_id,
                shift_name,
                growbag_machine_id,
                SUM(COALESCE(bags_produced, 0)) as growbags,
                SUM(eb_price) as eb_price
            ')
            ->groupBy('shift_date', 'shift_id', 'shift_name', 'growbag_machine_id')
            ->orderBy('shift_date', 'asc')
            ->orderBy('shift_id', 'asc')
            ->get();

        $grouped = [];
        foreach ($results as $row) {
            $grouped[$row->shift_date][$row->shift_id][$row->growbag_machine_id] = [
                'growbags' => (int) $row->growbags,
                'eb_price' => (float) $row->eb_price,
                'shift_name' => $row->shift_name,
            ];
        }

        $allDays = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            $allDays[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }

        $final = [];
        foreach ($allDays as $date) {
            $shiftsPayload = [];
            foreach ($shifts as $shift) {
                $shiftRows = $grouped[$date][$shift->id] ?? [];
                $shiftName = $shift->name;

                $machinesPayload = [];
                $shiftGrowbags = 0;
                $shiftEbPrice = 0;

                foreach ($machines as $machine) {
                    $machineRow = $shiftRows[$machine->id] ?? null;
                    $growbags = (int) ($machineRow['growbags'] ?? 0);
                    $ebPrice = (float) ($machineRow['eb_price'] ?? 0);

                    $machinesPayload[] = [
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->machine_name,
                        'growbags' => $growbags,
                        'eb_price' => $ebPrice,
                    ];

                    $shiftGrowbags += $growbags;
                    $shiftEbPrice += $ebPrice;
                    if (! empty($machineRow['shift_name'])) {
                        $shiftName = $machineRow['shift_name'];
                    }
                }

                $shiftsPayload[] = [
                    'shift_id' => $shift->id,
                    'shift_name' => $shiftName,
                    'shift_growbags' => $shiftGrowbags,
                    'shift_eb_price' => $shiftEbPrice,
                    'machines' => $machinesPayload,
                ];
            }

            $final[] = [
                'date' => $date,
                'shifts' => $shiftsPayload,
            ];
        }

        return $final;
    }

    private function fetchMonthlyData($teamId, $startDate, $endDate)
    {
        $shifts = $this->getShifts($teamId);
        $machines = $this->getMachines($teamId);

        $results = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->selectRaw('
                DATE_FORMAT(shift_date, "%Y-%m") as month,
                shift_id,
                shift_name,
                growbag_machine_id,
                SUM(COALESCE(bags_produced, 0)) as growbags,
                SUM(eb_price) as eb_price
            ')
            ->groupBy('month', 'shift_id', 'shift_name', 'growbag_machine_id')
            ->orderBy('month', 'asc')
            ->orderBy('shift_id', 'asc')
            ->get();

        $grouped = [];
        foreach ($results as $row) {
            $grouped[$row->month][$row->shift_id][$row->growbag_machine_id] = [
                'growbags' => (int) $row->growbags,
                'eb_price' => (float) $row->eb_price,
                'shift_name' => $row->shift_name,
            ];
        }

        $allMonths = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            $allMonths[] = date('Y-m', $current);
            $current = strtotime('+1 month', $current);
        }

        $final = [];
        foreach ($allMonths as $month) {
            $shiftsPayload = [];
            foreach ($shifts as $shift) {
                $shiftRows = $grouped[$month][$shift->id] ?? [];
                $shiftName = $shift->name;

                $machinesPayload = [];
                $shiftGrowbags = 0;
                $shiftEbPrice = 0;

                foreach ($machines as $machine) {
                    $machineRow = $shiftRows[$machine->id] ?? null;
                    $growbags = (int) ($machineRow['growbags'] ?? 0);
                    $ebPrice = (float) ($machineRow['eb_price'] ?? 0);

                    $machinesPayload[] = [
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->machine_name,
                        'growbags' => $growbags,
                        'eb_price' => $ebPrice,
                    ];

                    $shiftGrowbags += $growbags;
                    $shiftEbPrice += $ebPrice;
                    if (! empty($machineRow['shift_name'])) {
                        $shiftName = $machineRow['shift_name'];
                    }
                }

                $shiftsPayload[] = [
                    'shift_id' => $shift->id,
                    'shift_name' => $shiftName,
                    'shift_growbags' => $shiftGrowbags,
                    'shift_eb_price' => $shiftEbPrice,
                    'machines' => $machinesPayload,
                ];
            }

            $final[] = [
                'month' => date('M', strtotime($month)),
                'shifts' => $shiftsPayload,
            ];
        }

        return $final;
    }

    private function getShifts($teamId)
    {
        return $this->shiftsCache[$teamId] ??= DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->select('id', 'name')
            ->orderBy('id')
            ->get();
    }

    private function getShiftDetails($teamId)
    {
        return $this->shiftDetailsCache[$teamId] ??= DB::table('pulse_shifts')
            ->select('id', 'name', 'start_time', 'end_time')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->get()
            ->keyBy('id');
    }

    private function getMachines($teamId)
    {
        return $this->machinesCache[$teamId] ??= DB::table('pulse_master_growbag_machines')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('field_id')
            ->get(['id', 'field_id', 'machine_name', 'bags_per_punch']);
    }
}
