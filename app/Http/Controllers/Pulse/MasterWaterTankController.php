<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Models\Pulse\MasterWaterTank;
use App\Services\Pulse\WaterTankNotificationPreferenceSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterWaterTankController extends Controller
{
    /** List all tanks for the current team */
    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        // Get all device IDs belonging to the current team
        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        // Fetch tanks linked to these devices
        $tanks = MasterWaterTank::select(
            'id',
            'device_id',
            'field_id',
            'tank_name',
            'capacity_litres',
            'low_alert_percent',
            'overflow_alert_percent'
        )
            ->whereIn('device_id', $deviceIds)
            ->get();

        return response()->json($tanks, 200);
    }

    /** Store a new tank */
    public function store(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Auth::user()->isSuperadmin()) {
            return response()->json(['error' => 'Only Superadmin can add a water tank.'], 403);
        }

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to add a water tank.'], 403);
        }

        $validator = Validator::make($request->all(), $this->rules($request, $teamId));

        if ($validator->fails()) {
            // Return all errors
            return response()->json(['error' => $validator->errors()], 422);
        }

        $tank = MasterWaterTank::create($validator->validated());
        WaterTankNotificationPreferenceSync::ensureForTeam((int) $teamId);

        return response()->json($tank, 201);
    }

    /** Get single tank for edit */
    public function edit($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to edit water tank.'], 403);
        }

        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        $tank = MasterWaterTank::whereIn('device_id', $deviceIds)->find($id);

        if (! $tank) {
            return response()->json(['error' => 'Tank not found'], 404);
        }

        return response()->json($tank, 200);
    }

    /** Update tank */
    public function update(Request $request, $id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to update water tank.'], 403);
        }

        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        $tank = MasterWaterTank::whereIn('device_id', $deviceIds)->find($id);

        if (! $tank) {
            return response()->json(['error' => 'Tank not found'], 404);
        }

        $payload = $request->all();
        if (! Auth::user()->isSuperadmin()) {
            $payload['field_id'] = $tank->field_id;
            $payload['tank_name'] = $tank->tank_name;
        }

        $validator = Validator::make($payload, $this->rules(new Request($payload), $teamId, $tank));

        if ($validator->fails()) {
            // Return all errors
            return response()->json(['error' => $validator->errors()], 422);
        }

        $tank->update($validator->validated());

        return response()->json($tank, 200);
    }

    /** Delete tank */
    public function destroy($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Auth::user()->isSuperadmin()) {
            return response()->json(['error' => 'Only Superadmin can delete water tank.'], 403);
        }

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to delete water tank.'], 403);
        }

        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        $tank = MasterWaterTank::whereIn('device_id', $deviceIds)->find($id);

        if (! $tank) {
            return response()->json(['error' => 'Tank not found'], 404);
        }

        $tank->delete();
        WaterTankNotificationPreferenceSync::removeForTeamIfNoTanks((int) $teamId);

        return response()->json(['message' => 'Tank deleted successfully'], 200);
    }

    private function rules(Request $request, $teamId, ?MasterWaterTank $tank = null): array
    {
        return [
            'device_id' => [
                'required',
                Rule::exists('pulse_devices', 'device_id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'field_id' => [
                'required',
                Rule::in(['d29', 'd30']),
                function ($attribute, $value, $fail) use ($request, $tank) {
                    $query = MasterWaterTank::where('device_id', $request->device_id)
                        ->where('field_id', $value);

                    if ($tank) {
                        $query->where('id', '!=', $tank->id);
                    }

                    if ($value && $query->exists()) {
                        $fail('The field id has already been taken.');
                    }
                },
            ],
            'tank_name' => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($request, $tank) {
                    $query = MasterWaterTank::where('device_id', $request->device_id)
                        ->where('tank_name', $value);

                    if ($tank) {
                        $query->where('id', '!=', $tank->id);
                    }

                    if ($query->exists()) {
                        $fail('The tank name has already been taken for this device.');
                    }
                },
            ],
            'capacity_litres' => 'required|numeric|min:1',
            'low_alert_percent' => 'required|numeric|between:1,100',
            'overflow_alert_percent' => 'required|numeric|between:1,100|gt:low_alert_percent',
        ];
    }
}
