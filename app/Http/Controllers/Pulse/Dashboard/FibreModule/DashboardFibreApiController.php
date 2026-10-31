<?php

namespace App\Http\Controllers\Pulse\Dashboard\FibreModule;

use App\Http\Controllers\Pulse\Dashboard\DashboardController;
use App\Models\Pulse\Device;
use App\Models\Pulse\Shift;
use App\Services\Pulse\FibreShiftDayTotals;
use App\Support\Pulse\CounterSeed;
use App\Support\Pulse\ProductionCounter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardFibreApiController extends DashboardController
{
    public function __construct(private readonly FibreShiftDayTotals $shiftDayTotals) {}

    public function index(Request $request)
    {
        // do not call parent as that is for web only!
    }

    public function fetchSummaryData(Request $request, $format = null)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'fibre')
            ->where('status', 'active')
            ->value('device_id');
        if (! $deviceId) {
            return response()->json(['error' => 'No active fibre device found for team: '.$currentTeamId], 404);
        }

        $startDate = $request->input('start');
        $endDate = $request->input('end');
        $summary = $this->fetchDailySummary($deviceId, $startDate, $endDate);

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);

        return response()->json([
            'data' => $summary,
            'shift_start_time' => $shiftStartTime,
        ]);
    }

    public function fetchDetailedData(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'fibre')
            ->where('status', 'active')
            ->value('device_id');
        if (! $deviceId) {
            return response()->json(['error' => 'No active fibre device found for team: '.$currentTeamId], 404);
        }

        $startDate = $request->input('start', now()->subDays(1)->toDateString());
        $endDate = $request->input('end', now()->subDays(1)->toDateString());

        $detailed = [
            'shiftData' => $this->fetchShiftData($currentTeamId, $startDate, $endDate),
            'hourlyData' => $this->fetchHourlyData($currentTeamId, $deviceId, $startDate, $endDate),
            'dailyData' => $this->fetchDailyData($currentTeamId, $deviceId, $startDate, $endDate),
            'monthlyData' => $this->fetchMonthlyData($currentTeamId, $deviceId, $startDate, $endDate),
            'attendance' => $this->fetchAttandanceEvents($startDate, $endDate),
        ];

        return response()->json($detailed);
    }

    /**
     * Returns daily summary JSON with count of records and sum of eb_units
     * for a given date range and device_id.
     *
     * @param  int  $deviceId
     * @param  string  $start_date
     * @param  string  $end_date
     * @return JsonResponse
     */
    private function fetchDailySummary($device_id, $start_date, $end_date)
    {
        return DB::table('pulse_summary_fibre_mega_bales')
            ->select(
                DB::raw('shift_date as shift_date'),
                DB::raw('SUM(bales_produced) as bales_produced'),
            )
            ->when($start_date !== null && $end_date !== null, function ($query) use ($start_date, $end_date) {
                return $query->whereBetween('shift_date', [$start_date, $end_date]);
            })
            ->where('device_id', $device_id)
            ->groupBy('shift_date')
            ->orderBy('shift_date', 'asc')
            ->get();
    }

    public function fetchDailyData($teamId, $deviceId, $startDate, $endDate)
    {
        $allShifts = DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        $shiftMap = [];
        foreach ($allShifts as $shift) {
            $shiftMap[$shift->id] = $shift->name;
        }
        $allPossibleShiftIds = array_keys($shiftMap);

        $query = '
            SELECT
                shift_date AS date,
                shift_id,
                SUM(bales_produced) AS bales_produced,
                SUM(bales_weighed) AS bales_weighed,
                SUM(weight) AS weight,
                SUM(eb_units) AS eb_units
            FROM pulse_summary_fibre_mega_bales
            WHERE shift_date BETWEEN ? AND ?
            AND device_id = ?
            GROUP BY shift_date, shift_id
            ORDER BY shift_date ASC, shift_id ASC
        ';

        $results = DB::select($query, [$startDate, $endDate, $deviceId]);

        if (empty($results)) {
            return [];
        }

        // Group results by date and shift
        $grouped = [];
        foreach ($results as $row) {
            $date = $row->date;
            if (! isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][$row->shift_id] = [
                'shift_id' => $row->shift_id,
                'shift_name' => $shiftMap[$row->shift_id],
                'bales_produced' => (int) $row->bales_produced,
                'bales_weighed' => (int) $row->bales_weighed,
                'weight' => (int) $row->weight,
                'eb_units' => (int) $row->eb_units,
            ];
        }

        // Generate full list of days between start and end
        $allDays = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $allDays[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }

        // Fill missing pulse_shifts for each day
        $final = [];
        foreach ($allDays as $date) {
            $pulse_shifts = [];
            foreach ($allPossibleShiftIds as $shiftId) {
                if (isset($grouped[$date][$shiftId])) {
                    $pulse_shifts[] = $grouped[$date][$shiftId];
                } else {
                    // Missing shift → zeroed data
                    $pulse_shifts[] = [
                        'shift_id' => $shiftId,
                        'shift_name' => $shiftMap[$shiftId],
                        'bales_produced' => 0,
                        'bales_weighed' => 0,
                        'weight' => 0,
                        'eb_units' => 0,
                    ];
                }
            }
            $final[] = [
                'date' => $date,
                'shifts' => $pulse_shifts,
            ];
        }

        return $final;
    }

    public function fetchMonthlyData($teamId, $deviceId, $startDate, $endDate)
    {
        $allShifts = DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        $shiftMap = [];
        foreach ($allShifts as $shift) {
            $shiftMap[$shift->id] = $shift->name;
        }
        $allPossibleShiftIds = array_keys($shiftMap);

        $query = "
            SELECT
                DATE_FORMAT(shift_date, '%Y-%m') AS month,
                shift_id,
                SUM(bales_produced) AS bales_produced,
                SUM(bales_weighed) AS bales_weighed,
                SUM(weight) AS weight,
                SUM(eb_units) AS eb_units
            FROM pulse_summary_fibre_mega_bales
            WHERE shift_date BETWEEN ? AND ?
            AND device_id = ?
            GROUP BY month, shift_id
            ORDER BY month ASC, shift_id ASC
        ";

        $results = DB::select($query, [$startDate, $endDate, $deviceId]);

        if (empty($results)) {
            return []; // No data at all
        }

        // Group actual data by month
        $grouped = [];

        foreach ($results as $row) {
            $month = $row->month;
            if (! isset($grouped[$month])) {
                $grouped[$month] = [];
            }

            $grouped[$month][$row->shift_id] = [
                'shift_id' => $row->shift_id,
                'shift_name' => $shiftMap[$row->shift_id],
                'bales_produced' => (int) $row->bales_produced,
                'bales_weighed' => (int) $row->bales_weighed,
                'weight' => (int) $row->weight,
                'eb_units' => (int) $row->eb_units,
            ];
        }

        // Build full month list between start and end
        $allMonths = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $allMonths[] = date('Y-m', $current);
            $current = strtotime('+1 month', $current);
        }

        // Fill missing months with zero data
        $final = [];

        foreach ($allMonths as $month) {
            $pulse_shifts = [];
            foreach ($allPossibleShiftIds as $shiftId) {
                if (isset($grouped[$month][$shiftId])) {
                    // Data exists for this month and shift, so use it.
                    $pulse_shifts[] = $grouped[$month][$shiftId];
                } else {
                    // Data is missing, so add a zeroed placeholder.
                    $pulse_shifts[] = [
                        'shift_id' => $shiftId,
                        'shift_name' => $shiftMap[$shiftId],
                        'bales_produced' => 0,
                        'bales_weighed' => 0,
                        'weight' => 0,
                        'eb_units' => 0,
                    ];
                }
            }
            $final[] = [
                'month' => date('M', strtotime($month)), // e.g., 'Aug'
                'shifts' => $pulse_shifts,
            ];
        }

        return $final;
    }

    /**
     * Returns monthly summary JSON with count of records and sum of eb_units
     * for a given date range and device_id.
     *
     * @param  int  $deviceId
     * @param  string  $start_date
     * @param  string  $end_date
     * @return JsonResponse
     */
    private function fetchMonthlySummary($device_id, $start_date, $end_date)
    {
        return DB::table('pulse_summary_fibre_mega_bales')
            ->select(
                DB::raw("DATE_FORMAT(shift_date, '%Y-%m') as month"),
                DB::raw('COUNT(*) as bales_produced'),
                DB::raw('SUM(eb_units) as eb_units')
            )
            ->whereBetween('shift_date', [$start_date, $end_date])
            ->where('device_id', $device_id)
            ->groupBy(DB::raw("DATE_FORMAT(shift_date, '%Y-%m')"))
            ->orderBy('month', 'asc')
            ->get();
    }

    /**
     * Returns the shift summary JSON for the given shift_date.
     *
     * The JSON structure returned looks like:
     * {
     *   "pulse_shifts": [
     *      { "shift_name": "Shift 1", "production": 131, "eb_units": 136.0 },
     *      { "shift_name": "Shift 2", "production": 153, "eb_units": 169.0 }
     *   ],
     *   "total": {
     *      "production": 284,
     *      "eb_units": 305.0
     *   }
     * }
     *
     * @param  string  $deviceId
     * @param  string  $startDate
     * @param  string  $endDate
     * @return JsonResponse
     */
    private function fetchShiftData($teamId, $startDate, $endDate)
    {
        // Fetch all active pulse_shifts for the team
        $allShifts = DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->select('id as shift_id', 'name as shift_name')
            ->orderBy('id', 'asc')
            ->get();

        // Initialize expected pulse_shifts with zeros
        $expectedShifts = [];
        foreach ($allShifts as $shift) {
            $expectedShifts[$shift->shift_id] = [
                'shift_id' => $shift->shift_id,
                'shift_name' => $shift->shift_name,
                'bales_produced' => 0.0,
                'bales_weighed' => 0.0,
                'total_weight' => 0.0,
                'avg_weight' => 0.0,
                'eb_units' => 0.0,
            ];
        }

        // The same resolver the dashboard and the IoT Insights calendars read, so the app
        // and the web cannot print a shift two different ways.
        $mergedShifts = $expectedShifts;

        foreach ($this->shiftDayTotals->forRange($teamId, $startDate, $endDate) as $row) {
            $existing = $mergedShifts[$row->shift_id] ?? [
                'shift_id' => $row->shift_id,
                'shift_name' => $row->shift_name,
                'bales_produced' => 0.0,
                'bales_weighed' => 0.0,
                'total_weight' => 0.0,
                'eb_units' => 0.0,
            ];

            $existing['bales_produced'] += $row->bales_produced;
            $existing['bales_weighed'] += $row->bales_weighed;
            $existing['total_weight'] += $row->weight;
            $existing['eb_units'] += $row->eb_units;

            $mergedShifts[$row->shift_id] = $existing;
        }

        $pulse_shifts = array_map(function (array $shift) {
            $shift['bales_produced'] = round($shift['bales_produced'], 1);
            $shift['bales_weighed'] = round($shift['bales_weighed'], 1);
            $shift['total_weight'] = round($shift['total_weight'], 1);
            $shift['avg_weight'] = $shift['bales_weighed'] > 0 ? round($shift['total_weight'] / $shift['bales_weighed'], 1) : 0;
            $shift['eb_units'] = round($shift['eb_units'], 1);

            return $shift;
        }, array_values($mergedShifts));

        // Calculate totals
        $total = [
            'bales_produced' => round(array_sum(array_column($pulse_shifts, 'bales_produced')), 1),
            'bales_weighed' => round(array_sum(array_column($pulse_shifts, 'bales_weighed')), 1),
            'total_weight' => round(array_sum(array_column($pulse_shifts, 'total_weight')), 1),
            'avg_weight' => ($valid = array_filter(array_column($pulse_shifts, 'avg_weight'))) ? round(array_sum($valid) / count($valid), 1) : 0.0,
            'eb_units' => round(array_sum(array_column($pulse_shifts, 'eb_units')), 1),
        ];

        // Return empty array if all totals are zero
        if ($total['bales_produced'] == 0 && $total['bales_weighed'] == 0 && $total['eb_units'] == 0) {
            return [];
        }

        // Format the response
        return [
            'shifts' => $pulse_shifts,
            'total' => $total,
        ];
    }

    /**
     * Returns the hourly breakdown JSON for the given shift_date.
     *
     * The JSON is an array of objects, each representing a single hour interval and shift.
     * For example:
     * [
     *   {
     *     "shift_name": "Shift 1",
     *     "hour_interval": "7 AM",
     *     "record_count": 100,
     *     "total_eb_units": 210
     *   },
     *   {
     *     "shift_name": "Shift 2",
     *     "hour_interval": "7 AM",
     *     "record_count": 85,
     *     "total_eb_units": 195
     *   },
     *   ...
     * ]
     *
     * @param  string  $deviceId
     * @param  string  $startDate
     * @param  string  $endDate
     * @return JsonResponse
     */
    private function fetchHourlyData($teamId, $deviceId, $startDate, $endDate)
    {
        $anchor = Shift::anchorForTeam($teamId);
        $shiftDetails = Shift::activeForTeam($teamId)
            ->select('id', 'team_id', 'name', 'start_time', 'end_time')
            ->orderedByOffset($anchor)
            ->get()
            ->keyBy('id');

        if ($shiftDetails->isEmpty()) {
            return [];
        }

        $shiftStartTime = $anchor;

        // Define the 24-Hour Production Window
        $startOfDay = Carbon::parse($startDate)->setTimeFromTimeString($shiftStartTime);
        $endOfDay = $startOfDay->copy()->addHours(24)->subSecond();
        $isWholeHourStart = $startOfDay->minute === 0;

        $deviceIds = DB::table('pulse_devices')->where('team_id', $teamId)->pluck('device_id');

        $bucketOf = function ($datetime) use ($startOfDay): string {
            $slotIndex = (int) floor($startOfDay->diffInMinutes(Carbon::parse($datetime), false) / 60);

            return $startOfDay->copy()->addHours($slotIndex)->format('Y-m-d H:i');
        };

        // Fetch all raw production data for the day
        $rawProductionRows = DB::table('pulse_device_data_fibre_bales')
            ->whereIn('device_id', $deviceIds)
            ->where('is_duplicate', 0)
            ->whereBetween('bale_datetime', [$startOfDay, $endOfDay])
            ->orderBy('bale_datetime')
            ->get();

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

        // An hour's bales are how far the machine's counter travelled in it, which is what
        // the shift and day figures beside these bars are built from — counting rows instead
        // loses every bale whose reading never arrived.
        $producedPerHour = ProductionCounter::bucketedPerDevice(
            $rawProductionRows,
            fn ($row) => (int) $row->device_id,
            fn ($row) => $bucketOf($row->bale_datetime),
            fn ($row) => (int) $row->bales_counter,
            $baleSeeds
        );

        // Energy by the same walk, for the same reason: an hour's own min/max span dropped
        // whatever the meter did between one hour's last reading and the next hour's first.
        $ebPerHour = ProductionCounter::bucketedPerDevice(
            $rawProductionRows,
            fn ($row) => (int) $row->device_id,
            fn ($row) => $bucketOf($row->bale_datetime),
            fn ($row) => (int) $row->eb_units_counter,
            $ebSeeds
        );

        // Fetch all raw weight data for the day
        $deviceWeighedData = DB::table('pulse_device_data_fibre_mega_bales_weight')
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('bale_weight_datetime', [$startOfDay, $endOfDay])
            ->get()
            ->groupBy(fn ($row) => $bucketOf($row->bale_weight_datetime))
            ->map(fn ($group) => [
                'bales_weighed' => $group->count(),
                'weight' => $group->sum('bale_weight'),
            ]);

        if ($rawProductionRows->isEmpty() && $deviceWeighedData->isEmpty()) {
            return [];
        }

        $hourlyData = collect();
        $currentStart = $startOfDay->copy();

        // Loop through each hour of the day and process the fetched data
        while ($currentStart->lessThan($endOfDay)) {
            $intervalKey = $currentStart->format('Y-m-d H:i');

            $weighed = $deviceWeighedData->get($intervalKey);

            $balesProduced = $producedPerHour[$intervalKey] ?? 0;
            $ebUnits = $ebPerHour[$intervalKey] ?? 0;
            $balesWeighed = $weighed['bales_weighed'] ?? 0;
            $weightKg = $weighed['weight'] ?? 0;

            // Determine which shift this hour belongs to
            $shiftId = 0; // Default or unassigned
            foreach ($shiftDetails as $shift) {
                if ($shift->containsMoment($currentStart, $anchor)) {
                    $shiftId = $shift->id;
                    break;
                }
            }

            // 5. Format the output to match what the Flutter charts expect
            $hourlyData->push([
                'hour_interval' => $currentStart->format('g:i A'),
                'shift_id' => $shiftId,
                'bales_produced' => $balesProduced,
                'eb_units' => $ebUnits,
                'bales_weighed' => $balesWeighed,
                'total_weight' => $weightKg,
            ]);

            $currentStart->addHour();
        }

        return $hourlyData;
    }
}
