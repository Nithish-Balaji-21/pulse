<?php

namespace App\Http\Controllers\Pulse\Dashboard\PithLoadModule;

use App\Http\Controllers\Pulse\Dashboard\DashboardController;
use App\Models\Pulse\Device;
use App\Models\Pulse\MasterWaterTank;
use App\Models\Pulse\Shift;
use App\Services\Pulse\Dashboard\DeviceStateService;
use App\Services\Pulse\Dashboard\HourlyMachineStateService;
use App\Services\Pulse\Dashboard\MiniBalesProductionService;
use App\Services\Pulse\Dashboard\PithBlocksProductionService;
use App\Services\Pulse\Dashboard\PithLoadProductionService;
use App\Services\Pulse\Dashboard\WaterMeterConsumptionService;
use App\Services\Pulse\Dashboard\WaterTankLevelService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardPithLoadController extends DashboardController
{
    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;

        if (! $team->has_pith_module) {
            if ($team->has_fibre_module) {
                return redirect("/{$team->id}/dashboard/fibre");
            }
            if ($team->has_growbags_module) {
                return redirect("/{$team->id}/dashboard/growbags");
            }
            Log::error('No module found for the team!');
            echo 'No module found for the team!';
            exit;
        }

        // For non-ajax requests, return the view
        if (! $request->ajax() && ! $request->wantsJson()) {
            $teamId = $team->id;

            $startTime = Shift::anchorForTeam($teamId);

            $shiftStartWithBuffer = null;
            if (! empty($startTime)) {
                $shiftStartWithBuffer = Carbon::today()->setTimeFromTimeString($startTime)->addMinutes(30);
            }

            $deviceId = Device::where('team_id', $teamId)
                ->where('module', 'pith')
                ->where('status', 'active')
                ->value('device_id');

            $hasWaterTanks = false;
            if ($deviceId) {
                $hasWaterTanks = MasterWaterTank::where('device_id', $deviceId)->exists();
            }

            return view('pulse::dashboard.pithmodule.pith', [
                'shiftStartWithBuffer' => $shiftStartWithBuffer,
                'hasWaterTanks' => $hasWaterTanks,
                'teamId' => Auth::user()->currentTeam->id,
            ]);
        }

        $startDate = Carbon::parse($request->query('start'));
        $endDate = $request->query('end');
        $type = $request->query('type', 'day');

        switch ($type) {
            case 'day':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy();
                break;

            case 'week':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy()->addDays(6);
                if ($endDate->equalTo($startDate)) {
                    $endDate = $startDate->copy()->addDays(6);
                }
                break;

            case 'month':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy()->endOfMonth();
                $startDate = $startDate->copy()->startOfMonth();
                break;

            case 'year':
                $startDate = $startDate->copy()->startOfYear();
                $endDate = $endDate ? Carbon::parse($endDate)->endOfYear() : $startDate->copy()->endOfYear();
                break;

            case 'range':
                $startDate = $startDate->startOfDay();
                $endDate = Carbon::parse($endDate)->endOfDay();
                break;
        }
        $fieldId = $request->query('field_id', config('app.water_consumption.total'));
        $dashboardData = $this->pithLoadModule($team, $startDate, $endDate, $type, $fieldId);

        $isSingleDay = $startDate->toDateString() === $endDate->toDateString();

        return response()->json([
            'success' => true,
            'data' => $dashboardData,
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString(),
            'type' => $type,
            'isSingleDay' => $isSingleDay,
        ]);
    }

    public function pithLoadModule($team, $startDate, $endDate, $type, $fieldId)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');
        if (! $deviceId) {
            return response()->json(['error' => 'No active pith device found for team: '.$currentTeamId], 404);
        }

        $data = [];

        $data['pithProduction'] = app(PithLoadProductionService::class)->get($startDate, $endDate, $type);

        // Get machine states data for the selected day
        if ($startDate->toDateString() === $endDate->toDateString()) {
            $startDate = $startDate->copy()->startOfDay();
            $endDate = $endDate->copy()->endOfDay();
            $data['hourlyMachineState'] = app(HourlyMachineStateService::class)->get($startDate, $endDate);
        } else {
            $data['hourlyMachineState'] = []; // empty when range > 1 day
        }

        $data['deviceStateAvg'] = app(DeviceStateService::class)->get($startDate, $endDate);
        $data['miniBaleProduction'] = app(MiniBalesProductionService::class)->get($startDate, $endDate, $type);

        if ($team->has_pith_module_blocks) {
            $data['pithBlocks'] = app(PithBlocksProductionService::class)->get($startDate, $endDate, $type);
        }

        if ($team->has_water_meter_module) {

            // 1. Total water consumption (d27)
            $normal = app(WaterMeterConsumptionService::class)->get($startDate, $endDate, $type, config('app.water_consumption.total'));

            // 2. Husk water consumption (d16)
            $husk = app(WaterMeterConsumptionService::class)->get($startDate, $endDate, $type, config('app.water_consumption.d16'));

            // Return both datasets to dashboard
            $data['waterMeter'] = [
                'normal' => $normal,
                'husk' => $husk,
            ];

            $data['waterTankLevel'] = $this->fetchWaterAvailabilitySummary($deviceId, $startDate, $endDate);
        }

        $data['attendance'] = $this->fetchAttandanceEvents($startDate, $endDate);

        // Include all data
        return $this->normalizeDashboardData($data);
    }

    private function normalizeDashboardData(array $data): array
    {
        // Helper: pick the first non-null value
        $pick = fn (array $source, array $keys, $default = null) => collect($keys)->map(fn ($k) => $source[$k] ?? null)->first(fn ($v) => $v !== null, $default);

        // Define normalization config
        $normalizers = [
            'pithProduction' => [
                'total' => ['total'],    // Changed from ['total_loads_expected']
                'expected' => ['expected'], // Changed from ['total_loads_expected']
                '_copy' => ['shifts', 'data'], // Explicitly copy over the new keys
            ],
            'miniBaleProduction' => [
                'total' => ['total_bales_expected'],
                'expected' => ['total_bales_expected'],
            ],
            'pithBlocks' => [
                'total' => ['total_blocks', 'total'],
            ],
            'waterMeter' => [
                'total' => ['total_litres', 'total', 'total_consumption', 'consumed'],
                'unit' => ['unit', 'uom'],
                '_copy' => ['tank_capacity'],
            ],
        ];

        // Apply normalizations
        foreach ($normalizers as $section => $rules) {
            if (! isset($data[$section])) {
                continue;
            }
            $source = $data[$section];
            foreach ($rules as $key => $keys) {
                if ($key === '_copy') {
                    foreach ($keys as $copyKey) {
                        if (isset($source[$copyKey])) {
                            $data[$section][$copyKey] = $source[$copyKey];
                        }
                    }
                } else {
                    $data[$section][$key] = $pick($source, (array) $keys, $key === 'unit' ? 'ltr' : null);
                }
            }
        }

        // --- Labour (attendance salaries) ---
        $salaryTotal = 0;
        if (isset($data['attendance'])) {
            try {
                $salaryTotal = collect($data['attendance'])->sum('total_salary');
            } catch (\Exception) {
                $salaryTotal = 0;
            }
        }

        $data['expenses_total'] = (int) round($salaryTotal);

        return $data;
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
            $data = app(WaterTankLevelService::class)->get(Carbon::parse($startDate), Carbon::parse($endDate), $fieldId);

            if (! empty($data)) {
                unset($data['tank_name']);

                $key = $tank->tank_name;
                $availabilityData[$key] = $data;
            }
        }

        return $availabilityData;
    }
}
