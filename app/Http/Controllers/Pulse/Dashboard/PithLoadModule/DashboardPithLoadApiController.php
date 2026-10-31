<?php

namespace App\Http\Controllers\Pulse\Dashboard\PithLoadModule;

use App\Http\Controllers\Pulse\Dashboard\DashboardController;
use App\Models\Pulse\Device;
use App\Models\Pulse\MasterWaterMeter;
use App\Models\Pulse\MasterWaterTank;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithBlocksProduction;
use App\Models\Pulse\Shift;
use App\Services\Pulse\Dashboard\WaterMeterConsumptionService;
use App\Services\Pulse\Dashboard\WaterTankLevelService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardPithLoadApiController extends DashboardController
{
    /**
     * Not calling parent index() because that is for web only
     */
    public function index(Request $request)
    {
        // If needed, you can leave this blank or handle any default logic.
    }

    public function fetchSummaryData(Request $request, $format = null)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');
        if (! $deviceId) {
            return response()->json(['error' => 'No active pith device found for team: '.$currentTeamId], 404);
        }

        $startDate = $request->input('start');
        $endDate = $request->input('end');
        $summary = $this->fetchDailySummary($deviceId, $startDate, $endDate);

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);

        return response()->json([
            'data' => $summary,
            'shift_start_time' => $shiftStartTime,
            'prod_summary_delay_mins' => config('app.daily_pith_cron_interval_mins'),
        ]);
    }

    public function fetchDetailedData(Request $request)
    {
        $team = Auth::user()->currentTeam;
        $currentTeamId = $team->id;
        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');
        if (! $deviceId) {
            return response()->json(['error' => 'No active pith device found for team: '.$currentTeamId], 404);
        }

        $startDate = $request->input('start', now()->subDays(1)->toDateString());
        $endDate = $request->input('end', now()->subDays(1)->toDateString());

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Check if the range is exactly the full month
        $isFullMonth = $start->isSameDay($start->copy()->startOfMonth()) &&
               $end->isSameDay($end->copy()->endOfMonth()) &&
               $start->isSameMonth($end);

        $detailed = [
            'shiftData' => $this->fetchShiftData($currentTeamId, $startDate, $endDate),
            'machineStates' => $this->fetchDeviceStates($deviceId, $startDate, $endDate),
            'attendance' => $this->fetchAttandanceEvents($startDate, $endDate),
        ];

        if ($team->has_pith_module_bales) {
            $detailed['fibreData'] = $this->fetchFibreData($deviceId, $currentTeamId, $startDate, $endDate);
        }

        if ($team->has_pith_module_blocks) {
            $detailed['pithBlocks'] = $this->fetchPithBlocksSummary($deviceId, $currentTeamId, $startDate, $endDate);
        }

        if ($team->has_water_meter_module) {
            $detailed['waterConsumption'] = $this->fetchWaterConsumptionSummary($deviceId, $start, $end);
            $detailed['waterAvailability'] = $this->fetchWaterAvailabilitySummary($deviceId, $start, $end);
        }

        // Add monthlySummary only if the range covers a full month
        if ($isFullMonth) {
            $detailed['monthlySummary'] = $this->dailyPithLoadSummary($currentTeamId, $startDate, $endDate);
        }

        return response()->json($detailed);
    }

    private function fetchDailySummary($deviceId, $startDate, $endDate)
    {
        $results = DB::table('pulse_summary_pith_productions')
            ->select(
                'shift_date',
                DB::raw('SUM(cft_expected) as expected'),
                DB::raw('SUM(cft_produced) as acutals')
            )
            ->when($startDate !== null && $endDate !== null, function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('shift_date', [$startDate, $endDate]);
            })
            ->where('device_id', $deviceId)
            ->groupBy('shift_date')
            ->orderBy('shift_date', 'asc')
            ->get();

        // Round the values for each row
        $results = $results->map(function ($item) {
            $item->expected = round($item->expected, 1);
            $item->acutals = round($item->acutals, 1);

            return $item;
        });

        return $results;
    }

    private function fetchMonthlySummary($deviceId, $startDate, $endDate)
    {
        $results = DB::table('pulse_summary_pith_productions')
            ->select(
                DB::raw("DATE_FORMAT(shift_date, '%Y-%m') as month"),
                DB::raw('SUM(cft_produced) as loads_produced'),
                DB::raw('SUM(cft_expected) as loads_expected'),
                DB::raw('SUM(eb_units) as eb_units')
            )
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->where('device_id', $deviceId)
            ->groupBy(DB::raw("DATE_FORMAT(shift_date, '%Y-%m')"))
            ->orderBy('month', 'asc')
            ->get();

        // Round the values for each row
        $results = $results->map(function ($item) {
            $item->loads_produced = round($item->loads_produced, 1);
            $item->loads_expected = round($item->loads_expected, 1);
            $item->eb_units = round($item->eb_units, 1);

            return $item;
        });

        return $results;
    }

    private function fetchShiftData($teamId, $startDate, $endDate)
    {
        $allShifts = DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->select('id as shift_id', 'name as shift_name')
            ->orderBy('id', 'asc')
            ->get();

        $expectedShifts = [];
        foreach ($allShifts as $shift) {
            $expectedShifts[$shift->shift_id] = [
                'shift_id' => $shift->shift_id,
                'shift_name' => $shift->shift_name,
                'loads_produced' => 0.0,
                'loads_expected' => 0.0,
                'eb_units' => 0.0,
                'eb_price' => 0.0,
            ];
        }

        $query = '
            WITH shift_summary AS (
                SELECT
                    shift_id,
                    shift_name,
                    SUM(cft_produced) AS loads_produced,
                    SUM(cft_expected) AS loads_expected,
                    SUM(eb_units) AS eb_units,
                    SUM(eb_price) AS eb_price
                FROM pulse_summary_pith_productions
                WHERE shift_date BETWEEN ? AND ?
                AND team_id = ?
                GROUP BY shift_id
                Order BY shift_id
            )
            SELECT * FROM shift_summary
        ';

        // Fetch raw aggregated data
        $shiftData = DB::select($query, [$startDate, $endDate, $teamId]);

        // Convert result from stdClass objects to associative array
        $fetchedShifts = json_decode(json_encode($shiftData), true);

        $mergedShifts = $expectedShifts;

        foreach ($fetchedShifts as $shift) {
            $id = $shift['shift_id'];

            // Round the values for fetched shift
            $shift['loads_expected'] = round($shift['loads_expected'], 1);
            $shift['loads_produced'] = round($shift['loads_produced'], 1);
            $shift['eb_units'] = round($shift['eb_units'], 1);
            $shift['eb_price'] = round($shift['eb_price'], 1);

            if (isset($mergedShifts[$id])) {
                $mergedShifts[$id] = array_merge($mergedShifts[$id], $shift);
            } else {
                $mergedShifts[$id] = $shift;
            }
        }

        $pulse_shifts = array_values($mergedShifts);

        // Calculate total loads produced, loads expected, and eb price
        $totalLoadsProduced = array_sum(array_column($pulse_shifts, 'loads_produced')) ?? 0;
        $totalLoadsExpected = array_sum(array_column($pulse_shifts, 'loads_expected')) ?? 0;
        $totalEbUnits = array_sum(array_column($pulse_shifts, 'eb_units')) ?? 0;
        $totalEbPrice = array_sum(array_column($pulse_shifts, 'eb_price')) ?? 0;

        $total = [
            'loads_produced' => round($totalLoadsProduced, 1),
            'loads_expected' => round($totalLoadsExpected, 1),
            'eb_units' => round($totalEbUnits, 1),
            'eb_price' => round($totalEbPrice, 1),
        ];

        if ($totalLoadsExpected == 0 && $totalLoadsProduced == 0 && $totalEbUnits == 0) {
            return [];
        }

        // Format the response
        return [
            'shifts' => $pulse_shifts,
            'total' => $total,
        ];
    }

    private function fetchDeviceStates($deviceId, $startDate, $endDate)
    {
        $statesToShow = ['efficient', 'off', 'idle', 'overload', 'shutdown', 'missing'];

        $rows = DB::table('pulse_summary_power_states')
            ->select('state', DB::raw('SUM(duration) AS total_duration'))
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->where('device_id', $deviceId)
            ->whereIn('state', $statesToShow)
            ->groupBy('state')
            ->pluck('total_duration', 'state')
            ->toArray();

        // Ensure all states exist in the final array and convert to integer values
        $result = array_replace(array_fill_keys($statesToShow, 0), $rows);
        $result = array_map('intval', $result);

        return $result;
    }

    public function fetchFibreData($deviceId, $currentTeamId, $startDate, $endDate)
    {
        $isToday = Carbon::parse($startDate)->isToday() && Carbon::parse($endDate)->isToday();

        if ($isToday) {
            // Try raw device data first
            $deviceData = '
                SELECT
                    COUNT(*) AS total_bales_produced
                FROM pulse_device_data_fibre_bales
                WHERE bale_date = ?
                AND device_id = ?
                AND is_duplicate = 0
            ';
            $expected = DB::selectOne($deviceData, [$startDate, $deviceId]);
            $producedCount = $expected ? $expected->total_bales_produced : 0;

            if ($producedCount == 0) {
                // No raw device data for today — fall back to the summary table (manual entries)
                $prodQuery = '
                    SELECT
                        COALESCE(SUM(bales_produced), 0) AS total_bales_produced
                    FROM pulse_summary_mini_bales
                    WHERE shift_date = ?
                    AND team_id = ?
                ';
                $todayProd = DB::selectOne($prodQuery, [$startDate, $currentTeamId]);
                $producedCount = $todayProd ? $todayProd->total_bales_produced : 0;
            }

            // Stock comes from the daily ledger, which nets sales and stock-takes off
            // production rather than only accumulating it.
            $baseStock = MiniBaleProduction::closingStockAt($currentTeamId, $startDate);

            $ledgerCoversToday = MiniBaleProduction::where('team_id', $currentTeamId)
                ->whereDate('shift_date', $startDate)
                ->exists();

            return [
                'total_bales_expected' => (float) $producedCount,
                // Today's shifts are not summarised into the ledger yet, so live device
                // counts sit on top of yesterday's closing balance until they are.
                'bales_in_stock' => (float) ($ledgerCoversToday ? $baseStock : $baseStock + $producedCount),
            ];
        } else {
            // Get Total Production for the range
            $prodQuery = '
                SELECT
                    COALESCE(SUM(bales_produced), 0) AS total_bales_produced,
                    COALESCE(SUM(bales_expected), 0) AS total_bales_expected
                FROM pulse_summary_mini_bales
                WHERE shift_date BETWEEN ? AND ?
                AND team_id = ?
            ';

            $prodResult = DB::selectOne($prodQuery, [$startDate, $endDate, $currentTeamId]);

            return [
                'total_bales_produced' => (float) ($prodResult->total_bales_produced ?? 0),
                'total_bales_expected' => (float) ($prodResult->total_bales_expected ?? 0),
                // Balance as at the end of the range, which holds even when the last day
                // in it had no production.
                'bales_in_stock' => (float) MiniBaleProduction::closingStockAt($currentTeamId, $endDate),
            ];
        }
    }

    public function dailyPithLoadSummary($teamId, $startDate, $endDate)
    {
        // Group by date
        $query = '
            SELECT
                shift_date,
                shift_id,
                shift_name,
                SUM(cft_produced) AS loads_produced
            FROM pulse_summary_pith_productions
            WHERE shift_date BETWEEN ? AND ?
            AND team_id = ?
            GROUP BY shift_date, shift_id, shift_name
            ORDER BY shift_date ASC, shift_id ASC
        ';

        $data = DB::select($query, [$startDate, $endDate, $teamId]);

        // Get shift names
        $shiftNames = DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->pluck('name')
            ->toArray();

        $result = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($current->lte($end)) {
            // Format matches standard MySQL DATE format (Y-m-d)
            $dateKey = $current->format('Y-m-d');

            $result[$dateKey] = [];
            foreach ($shiftNames as $shift) {
                $result[$dateKey][$shift] = 0.0;
            }

            $current->addDay();
        }

        foreach ($data as $row) {
            if (isset($result[$row->shift_date])) {
                $result[$row->shift_date][$row->shift_name] = round($row->loads_produced, 1);
            }
        }

        return $result;
    }

    public function fetchPithBlocksSummary($deviceId, $currentTeamId, $startDate, $endDate)
    {
        $isToday = Carbon::parse($startDate)->isToday() && Carbon::parse($endDate)->isToday();
        if ($isToday) {
            $prodQuery = '
                SELECT
                    COUNT(*) AS total_blocks_produced
                FROM pulse_device_data_pith_blocks
                WHERE block_date = ?
                AND device_id = ?
                AND is_duplicate = 0
            ';
            $todayProd = DB::selectOne($prodQuery, [$startDate, $deviceId]);
            $producedCount = $todayProd ? $todayProd->total_blocks_produced : 0;

            // Stock comes from the daily ledger, which nets sales and stock-takes off
            // production rather than only accumulating it.
            $baseStock = PithBlocksProduction::closingStockAt($currentTeamId, $startDate);

            $summaryCoversToday = PithBlocksProduction::where('team_id', $currentTeamId)
                ->whereDate('shift_date', $startDate)
                ->exists();

            return [
                'total_blocks_produced' => (float) $producedCount,
                'blocks_in_stock' => (float) ($summaryCoversToday ? $baseStock : $baseStock + $producedCount),
            ];
        } else {
            // Get Total Production for the range
            $prodQuery = '
                SELECT
                    COALESCE(SUM(blocks_expected), 0) AS total_blocks_expected,
                    COALESCE(SUM(blocks_produced), 0) AS total_blocks_produced
                FROM pulse_summary_pith_blocks
                WHERE shift_date BETWEEN ? AND ?
                AND team_id = ?
            ';

            $prodResult = DB::selectOne($prodQuery, [$startDate, $endDate, $currentTeamId]);

            return [
                'total_blocks_expected' => (float) ($prodResult->total_blocks_expected ?? 0),
                'total_blocks_produced' => (float) ($prodResult->total_blocks_produced ?? 0),
                // Balance as at the end of the range, which holds even when the last day
                // in it had no production.
                'blocks_in_stock' => (float) PithBlocksProduction::closingStockAt($currentTeamId, $endDate),
            ];
        }
    }

    public function fetchWaterConsumptionSummary($deviceId, $startDate, $endDate)
    {
        // Fetch all configured water meters for this device from the Master table
        $meters = MasterWaterMeter::where('device_id', $deviceId)->get();

        // If no meters are configured, return empty
        if ($meters->isEmpty()) {
            return [];
        }

        $consumptionData = [];

        $totalFieldId = config('app.water_consumption.total');
        $totalMeterName = null;

        $service = app(WaterMeterConsumptionService::class);

        foreach ($meters as $meter) {
            $fieldId = $meter->field_id;
            $key = $meter->consumption_name;

            if ($fieldId === $totalFieldId) {
                $totalMeterName = $key;
            }

            $serviceResult = $service->get($startDate->copy(), $endDate->copy(), 'range', $fieldId);

            $consumptionData[$key] = (float) ($serviceResult['total_litres'] ?? 0);
        }

        if ($totalMeterName && isset($consumptionData[$totalMeterName])) {
            $totalWater = $consumptionData[$totalMeterName];

            // Calculate sum of all OTHER meters
            $subMetersSum = 0;
            foreach ($consumptionData as $k => $v) {
                if ($k !== $totalMeterName) {
                    $subMetersSum += $v;
                }
            }

            // Calculate Others
            $others = max(0, $totalWater - $subMetersSum);

            // Re-order
            unset($consumptionData[$totalMeterName]);

            $consumptionData['Others'] = round($others, 1);
        }

        return $consumptionData;
    }

    public function fetchWaterAvailabilitySummary($deviceId, $startDate, $endDate)
    {
        $tanks = MasterWaterTank::where('device_id', $deviceId)->get();

        if ($tanks->isEmpty()) {
            return [];
        }

        $availabilityData = [];

        foreach ($tanks as $tank) {
            $fieldId = $tank->field_id;

            // Fetch data using your existing service
            $data = app(WaterTankLevelService::class)->get($startDate->copy(), $endDate->copy(), $fieldId);

            if (! empty($data)) {
                unset($data['tank_name']);

                $key = $tank->tank_name;
                $availabilityData[$key] = $data;
            }
        }

        return $availabilityData;
    }
}
