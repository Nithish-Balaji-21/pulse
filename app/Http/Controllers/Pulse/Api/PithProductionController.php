<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Models\Pulse\DeviceDataState;
use App\Models\Pulse\PithProduction;
use App\Models\Pulse\Shift;
use App\Models\Settings;
use App\Models\Staff;
use App\Traits\ChecksProductionOverlap;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PithProductionController extends Controller
{
    use ChecksProductionOverlap;

    public function index(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        // Query for PithProduction with Staff
        $query = PithProduction::where('team_id', $currentTeamId)
            ->with(['staff:id,staff_name', 'device:device_id', 'shift:id,name'])
            ->orderBy('shift_date', 'desc');

        $shiftId = $request->input('shift_id');
        if ($shiftId) {
            $query->whereHas('shift', function ($q) use ($shiftId) {
                $q->where('id', $shiftId);
            });
        }

        $searchQuery = $request->input('search');
        if ($searchQuery) {
            $query->where(function ($q) use ($searchQuery) {
                $q->whereHas('staff', function ($staffQuery) use ($searchQuery) {
                    $staffQuery->where('staff_name', 'LIKE', "%$searchQuery%");
                })
                    ->orWhereRaw("strftime('%d.%m.%Y', shift_date) LIKE ?", ["%$searchQuery%"]);
            });
        }
        // Apply additional filters if present in the request
        $query = $this->applyFilters($query, $request);
        $productions = $query->paginate(10);
        $transformProduction = $productions->map(function ($production) {
            return [
                'id' => $production->id,
                'shift_date' => Carbon::parse($production->shift_date)->format(config('app.date_format')),
                'start_time' => Carbon::parse($production->start_time)->format(config('app.time_format')),
                'end_time' => Carbon::parse($production->end_time)->format(config('app.time_format')),
                'staff' => [
                    'id' => $production->staff?->id ?? '',
                    'staff_name' => $production->staff?->staff_name ?? '',
                ],
                'shift' => [
                    'name' => $production->shift?->name ?? '',
                ],
                'device' => [
                    'device_id' => $production->device?->device_id ?? null,
                ],
                'loads_produced' => $production->cft_produced ?? 0,
                'load_hrs' => $production->load_hrs ?? 0,
                'inventory' => $production->getTotalPithLoadsAttribute() ?? 0,
                'eb_units' => round($production->eb_units ?? 0, 1),
                'eb_amount' => round($production->eb_price ?? 0, 2),
                'loads_expected' => round($production->cft_expected ?? 0, 1),
            ];
        });

        $productions->setCollection($transformProduction);

        $shifts = Shift::where('team_id', $currentTeamId)
            ->where('is_active', 1)
            ->select('id', 'name')
            ->get();

        $data = [
            'productions' => $productions,
            'search_query' => $searchQuery,
            'shift_id' => $shiftId,
            'shifts' => $shifts,
        ];

        return response()->json($data);
    }

    // for creating the filtering
    public function applyFilters($query, $request)
    {
        // Date Range Filter
        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $query->whereBetween('shift_date', [$startDate, $endDate]);
        }

        // Load Filter for Ranges
        if ($request->has('loads')) {
            $loads = $request->input('loads');

            // Check which load range was selected
            switch ($loads) {
                case '0-3':
                    $query->whereBetween('cft_produced', [0, 3]);
                    break;

                case '3-6':
                    $query->whereBetween('cft_produced', [3, 6]);
                    break;

                case '6-10':
                    $query->whereBetween('cft_produced', [6, 10]);
                    break;

                case '10+':
                    $query->where('cft_produced', '>=', 10);
                    break;
            }
        }

        // Staff Name Filter
        if ($request->has('staff_name')) {
            $staffName = $request->input('staff_name');
            $query->whereHas('staff', function ($q) use ($staffName) {
                $q->where('staff_name', 'like', '%'.$staffName.'%');
            });
        }

        return $query;
    }

    // Reusable function to calculate start and end times based on the shift
    private function calculateShiftTimes(Shift $shift, $shiftDate)
    {
        [$start, $end] = $shift->windowOn(Carbon::parse($shiftDate)->toDateString());

        return [
            'start_time' => $start->toDateTimeString(),
            'end_time' => $end->toDateTimeString(),
        ];
    }

    // Create a new pith production record
    public function store(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        if (! Gate::allows('pith_production.create')) {
            return response()->json(['error' => 'You are not authorized to add pith production.'], 403);
        }

        $policy = PithProduction::entryPolicyFor($currentTeamId);

        try {
            $shift_date = Carbon::createFromFormat(config('app.date_format'), $request->input('shift_date'))->format('Y-m-d');
            $start_time = Carbon::createFromFormat(config('app.date_time_format'), $request->input('start_time'))->format('Y-m-d H:i:s');
            $end_time = Carbon::createFromFormat(config('app.date_time_format'), $request->input('end_time'))->format('Y-m-d H:i:s');

            if (Carbon::parse($end_time)->lessThan(Carbon::parse($start_time))) {
                $end_time = Carbon::parse($end_time)->addDay()->format('Y-m-d H:i:s');
            }
            // Add converted shift_date and times to the request
            $request->merge([
                'shift_date' => $shift_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
            ]);

            // Validate the request data
            $validated = $request->validate([
                'shift_date' => 'required|date_format:Y-m-d|after_or_equal:'.$policy->minShiftDate(),
                'shift_id' => 'required|string|exists:pulse_shifts,id',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
                'staff_id' => 'required|string|exists:staffs,id',
                'loads_produced' => 'nullable|numeric|min:0|max:6000',
                'load_hrs' => 'nullable',
            ], [
                'shift_name.required' => 'Please select a shift.',
                'start_time.required' => 'Please enter start time.',
                'end_time.required' => 'Please enter end time.',
                'staff_name.required' => 'Please select a staff.',
                'loads_produced.integer' => 'Produced must be an integer.',
            ]);

            if (! $policy->canCreate($validated['shift_date'])) {
                return response()->json([
                    'error' => 'Cannot create production records outside the allowed entry window.',
                ], 403);
            }

            $device = Device::where('team_id', $currentTeamId)
                ->where('module', 'pith')
                ->first();

            // Check for shift overlap
            if ($this->checkOverlap($currentTeamId, $device->device_id ?? null, $validated['start_time'], $validated['end_time'])) {
                return response()->json(['error' => 'Shift timings overlap with an existing shift.'], 422);
            }

            // Calculate EB units
            $eb = $this->getEb($start_time, $end_time)->getData();

            $shift = Shift::find(['id' => $validated['shift_id']])->first();
            $shiftName = $shift ? $shift->name : null;

            // Create the production record
            $production = PithProduction::create([
                'device_id' => $device ? $device->device_id : null,
                'team_id' => $currentTeamId,
                'shift_date' => $validated['shift_date'],
                'shift_id' => $validated['shift_id'],
                'shift_name' => $shiftName,
                'staff_id' => $validated['staff_id'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'cft_produced' => $validated['loads_produced'],
                'cft_expected' => 0.0,
                'load_hrs' => $validated['load_hrs'],
                'eb_units' => $eb->ebUnits,
                'eb_price' => $eb->ebAmount,
            ]);

            return response()->json([
                'message' => 'Production created successfully',
                'data' => $production,
            ], 201);
        } catch (ValidationException $e) {
            Log::error('Validation error', ['errors' => $e->errors()]);

            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('An error occurred', ['exception' => $e->getMessage()]);

            return response()->json(['error' => 'An error occurred while creating the production record.'], 500);
        }
    }

    // Update an existing pith production record
    public function update(Request $request, $id)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        if (! Gate::allows('pith_production.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pith production.'], 403);
        }

        $production = PithProduction::where('id', $id)->where('team_id', $currentTeamId)->firstOrFail();

        if (! $production->canEditRecord()) {
            return response()->json([
                'error' => 'This production record is locked or outside the allowed edit window.',
            ], 403);
        }

        $policy = PithProduction::entryPolicyFor($currentTeamId);

        try {
            $shift_date = Carbon::createFromFormat(config('app.date_format'), $request->input('shift_date'))->format('Y-m-d');
            $start_time = Carbon::createFromFormat(config('app.date_time_format'), $request->input('start_time'))->format('Y-m-d H:i:s');
            $end_time = Carbon::createFromFormat(config('app.date_time_format'), $request->input('end_time'))->format('Y-m-d H:i:s');

            // Add converted times and date to the request
            $request->merge([
                'shift_date' => $shift_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
            ]);

            // Validate the request
            $validated = $request->validate([
                'shift_date' => 'required|date|date_format:Y-m-d|after_or_equal:'.$policy->minShiftDate(),
                'shift_id' => 'required|string|exists:pulse_shifts,id',
                'staff_id' => 'required|string|exists:staffs,id',
                'start_time' => 'nullable|date_format:Y-m-d H:i:s',
                'end_time' => 'nullable|date_format:Y-m-d H:i:s|after:start_time',
                'loads_produced' => 'nullable|numeric|min:0|max:6000',
                'load_hrs' => 'nullable',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        if (! $policy->canEdit($validated['shift_date'])) {
            return response()->json([
                'error' => 'Cannot move production record outside the allowed edit window.',
            ], 403);
        }

        // Find device, shift, and user IDs based on the names
        $shift = Shift::where('id', $validated['shift_id'])->firstOrFail();

        $currentInventory = $production->getTotalPithLoadsAttribute() ?? 0;
        $roundedInventory = round($currentInventory, 2);

        $newLoadsProduced = $validated['loads_produced'] ?? null;

        // Ensure the updated loads_produced value is not less than the existing inventory
        if ($newLoadsProduced !== null && $newLoadsProduced < $roundedInventory) {
            throw ValidationException::withMessages([
                'loads_produced' => 'Produced ('.$newLoadsProduced.') cannot be less than current inventory ('.$roundedInventory.').',
            ]);
        }

        // Calculate shift times if both start_time and end_time are provided
        if ($validated['start_time'] && $validated['end_time']) {
            $shiftTimes = $this->calculateShiftTimes($shift, $validated['shift_date']);
            $validated['start_time'] = $shiftTimes['start_time'];
            $validated['end_time'] = $shiftTimes['end_time'];

            // Check for overlaps, excluding the current record
            if ($this->checkOverlap($currentTeamId, $production->device_id, $validated['start_time'], $validated['end_time'], $production->id)) {
                return response()->json(['error' => 'Shift timings overlap with an existing shift.'], 422);
            }
        } else {
            // If times are not being changed, use existing values
            $validated['start_time'] = $production->start_time;
            $validated['end_time'] = $production->end_time;
        }

        $eb = $this->getEb($start_time, $end_time)->getData();

        // Update production data
        $production->update([
            'shift_date' => $validated['shift_date'],
            'shift_id' => $validated['shift_id'],
            'staff_id' => $validated['staff_id'],
            'shift_name' => $shift->name,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'cft_produced' => $validated['loads_produced'],
            'eb_units' => $eb->ebUnits,
            'eb_price' => $eb->ebAmount,
            'load_hrs' => $validated['load_hrs'],
        ]);

        return response()->json(['message' => 'Pith production updated successfully'], 200);
    }

    public function edit($id)
    {
        $teamId = Auth::user()->currentTeam->id;
        if (! $teamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        if (! Gate::allows('pith_production.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pith production.'], 403);
        }

        $production = PithProduction::where('id', $id)
            ->where('team_id', $teamId)
            ->with(['staff:id,staff_name', 'shift:id,name', 'device:device_id'])
            ->first();
        if (! $production) {
            return response()->json(['error' => 'Production record not found'], 404);
        }

        $shiftsAndDevices = self::getShiftsDevicesStaffs($teamId);
        $policy = PithProduction::entryPolicyFor($teamId);

        return response()->json(array_merge([
            'production' => [
                'id' => $production->id,
                'shift_date' => Carbon::parse($production->shift_date)->format(config('app.date_format')),
                'start_time' => Carbon::parse($production->start_time)->format(config('app.date_time_format')),
                'end_time' => Carbon::parse($production->end_time)->format(config('app.date_time_format')),
                'staff' => [
                    'id' => optional($production->staff)->id ?? '',
                    'staff_name' => optional($production->staff)->staff_name ?? '',
                ],
                'shift' => [
                    'name' => $production->shift->name,
                ],
                'device' => [
                    'device_id' => $production->device?->device_id ?? null,
                ],
                'loads_produced' => $production->loads_produced,
                'current_inventory' => $production->getTotalPithLoadsAttribute() ?? 0,
                'load_hrs' => $production->load_hrs ?? 0,
                'eb_units' => $production->eb_units,
                'eb_amount' => $production->eb_price,
            ],
            'units' => $shiftsAndDevices,
            'min_editable_date' => $policy->minShiftDate(),
            'max_create_date' => $policy->maxCreateShiftDate(),
        ], $policy->toApiMetadata()));
    }

    // Delete a pith production record
    public function destroy($id)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        if (! Gate::allows('pith_production.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete pith production.'], 403);
        }

        // Fetch the production record
        $production = PithProduction::where('id', $id)
            ->where('team_id', $currentTeamId)
            ->first();

        if (! $production) {
            return response()->json([
                'error' => 'Pith production ID does not exist or not authorized.',
            ], 404);
        }

        if (! $production->canDeleteRecord()) {
            return response()->json([
                'error' => 'This production record is locked or outside the allowed delete window.',
            ], 403);
        }

        // Delete the production record
        $production->delete();

        return response()->json(['message' => 'Pith production deleted successfully.'], 200);
    }

    public function checkOverlap($teamId, $deviceId, $startTime, $endTime, $ignoreId = null)
    {
        return $this->productionOverlaps(PithProduction::class, $teamId, $deviceId, $startTime, $endTime, $ignoreId);
    }

    // Generate a report based on device states
    public function generateReport($PithProductionId, Request $request)
    {
        $pithProduction = PithProduction::find($PithProductionId);
        if (! $pithProduction) {
            return response()->json(['message' => 'Pith Production record not found'], 404);
        }

        // If no device_id (manual entry, no device), return empty report
        if (! $pithProduction->device_id) {
            return response()->json([
                'efficient' => ['state' => 'efficient', 'total_duration' => '0h 0m', 'state_count' => 0, 'records' => []],
                'idle' => ['state' => 'idle', 'total_duration' => '0h 0m', 'state_count' => 0, 'records' => []],
                'off' => ['state' => 'off', 'total_duration' => '0h 0m', 'state_count' => 0, 'records' => []],
                'overload' => ['state' => 'overload', 'total_duration' => '0h 0m', 'state_count' => 0, 'records' => []],
                'shutdown' => ['state' => 'shutdown', 'total_duration' => '0h 0m', 'state_count' => 0, 'records' => []],
            ]);
        }

        $stateSummary = DeviceDataState::select(
            'state',
            DB::raw('SUM(duration) as total_duration'),
            DB::raw('COUNT(*) as state_count')
        )
            ->where('device_id', $pithProduction->device_id)
            ->where('start_time', '>=', $pithProduction->start_time)
            ->where('end_time', '<=', $pithProduction->end_time)
            ->groupBy('state')
            ->get()
            ->keyBy('state')
            ->toArray();

        $reportData = [];
        $reportData['efficient'] = [
            'state' => 'efficient',
            'total_duration' => $this->formatDuration($stateSummary['efficient']['total_duration'] ?? 0),
            'state_count' => $stateSummary['efficient']['state_count'] ?? 0,
            'records' => DeviceDataState::select('start_time', 'end_time', 'duration')
                ->where('device_id', $pithProduction->device_id)
                ->where('start_time', '>=', $pithProduction->start_time)
                ->where('end_time', '<=', $pithProduction->end_time)
                ->where('state', 'efficient')
                ->orderBy('duration', 'desc')
                ->get()
                ->map(function ($record) {
                    $record->start_time = Carbon::parse($record->start_time)->format('H:i:s');
                    $record->end_time = Carbon::parse($record->end_time)->format('H:i:s');
                    $record->duration = $this->formatDuration($record->duration);

                    return $record;
                })
                ->toArray(),
        ];

        $desiredState = $request->query('state', 'efficient');
        if ($desiredState !== 'efficient') {
            $reportData[$desiredState] = [
                'state' => $desiredState,
                'total_duration' => $this->formatDuration($stateSummary[$desiredState]['total_duration'] ?? 0),
                'state_count' => $stateSummary[$desiredState]['state_count'] ?? 0,
                'records' => DeviceDataState::select('start_time', 'end_time', 'duration')
                    ->where('device_id', $pithProduction->device_id)
                    ->where('start_time', '>=', $pithProduction->start_time)
                    ->where('end_time', '<=', $pithProduction->end_time)
                    ->where('state', $desiredState)
                    ->orderBy('duration', 'desc')
                    ->get()
                    ->map(function ($record) {
                        $record->start_time = Carbon::parse($record->start_time)->format('H:i:s');
                        $record->end_time = Carbon::parse($record->end_time)->format('H:i:s');
                        $record->duration = $this->formatDuration($record->duration);

                        return $record;
                    })
                    ->toArray(),
            ];

            unset($reportData['efficient']);
        }

        $reportData['stateSummary'] = array_map(function ($state) {
            $state['total_duration'] = $this->formatDuration($state['total_duration']);

            return $state;
        }, $stateSummary);

        return response()->json([
            'message' => 'Report generated successfully',
            'report' => $reportData,
        ]);
    }

    private function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    public function add()
    {
        $teamId = Auth::user()->currentTeam->id;
        if (! $teamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        if (! Gate::allows('pith_production.create')) {
            return response()->json(['error' => 'You are not authorized to add pith production.'], 403);
        }

        $shiftsAndDevices = self::getShiftsDevicesStaffs($teamId);
        $policy = PithProduction::entryPolicyFor($teamId);

        return response()->json(array_merge($shiftsAndDevices, $policy->toApiMetadata(), [
            'min_editable_date' => $policy->minShiftDate(),
            'max_create_date' => $policy->maxCreateShiftDate(),
        ]));
    }

    protected function getShiftsDevicesStaffs($teamId)
    {
        // Fetch shifts with name, start_time, and end_time
        $anchor = Shift::anchorForTeam($teamId);
        $shifts = Shift::where('team_id', $teamId)
            ->get(['id', 'name', 'start_time', 'end_time'])
            ->map(function ($shift) use ($anchor) {
                return [
                    'id' => $shift->id,
                    'name' => $shift->name,
                    'start_time' => Carbon::parse($shift->start_time)->format(config('app.time_format')),
                    'end_time' => Carbon::parse($shift->end_time)->format(config('app.time_format')),
                    'starts_next_day' => (int) $shift->startsNextDay($anchor),
                ];
            });

        $devices = Device::select('device_id', 'device_id')
            ->where('team_id', $teamId)
            ->where('module', 'pith')
            ->get();

        $staffs = Staff::select('id', 'staff_name')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('staff_name', 'asc')
            ->get();

        $setting = Settings::where('team_id', $teamId)
            ->where('key', 'PRODUCTION_UNIT')
            ->first();

        $defaultProductionUnit = $setting ? $setting->value : 'loads';

        $cftPerLoad = ($setting && $defaultProductionUnit === 'cft')
            ? $setting->value_remark
            : null;

        return [
            'shifts' => $shifts,
            'devices' => $devices,
            'staff' => $staffs,
            'default_production_unit' => $defaultProductionUnit,
            'cft_per_load' => $cftPerLoad,
        ];
    }

    public function getEb($start_time = null, $end_time = null)
    {
        $startTime = $start_time ?? request('start_time');
        $endTime = $end_time ?? request('end_time');

        // Validate or process the $start_date as needed
        if (! $startTime || ! $endTime) {
            return response()->json(['error' => 'Start and End times are required'], 400);
        }

        // Parse and format the start and end times to Y-m-d H:i:s
        try {
            $shiftStart = $start_time
                            ? Carbon::parse($start_time)->format('Y-m-d H:i:s')
                            : Carbon::createFromFormat(config('app.date_time_format'), $startTime)->format('Y-m-d H:i:s');

            $shiftEnd = $end_time
                            ? Carbon::parse($end_time)->format('Y-m-d H:i:s')
                            : Carbon::createFromFormat(config('app.date_time_format'), $endTime)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid date format provided'], 400);
        }

        $teamId = Auth::user()->currentTeam->id;
        if (! $teamId) {
            return response()->json(['error' => 'No current team found.'], 404);
        }

        // Find the device by team_id and module
        $device = Device::where('team_id', $teamId)->where('module', 'pith')->first();

        // If no device found, return default values
        if (! $device) {
            return response()->json([
                'ebUnits' => 0,
                'ebAmount' => 0,
            ]);
        }

        $ebUnits = DB::table('pulse_summary_power_states')
            ->where('device_id', $device->device_id)
            ->where(function ($query) use ($shiftStart, $shiftEnd) {
                $query->whereBetween('start_time', [$shiftStart, $shiftEnd])
                    ->orWhereBetween('end_time', [$shiftStart, $shiftEnd])
                    ->orWhere(function ($query) use ($shiftStart, $shiftEnd) {
                        $query->where('start_time', '<', $shiftStart)
                            ->where('end_time', '>', $shiftEnd);
                    });
            })
            ->get()
            ->map(function ($record) use ($shiftStart, $shiftEnd) {
                $overlapStart = max(strtotime($record->start_time), strtotime($shiftStart));
                $overlapEnd = min(strtotime($record->end_time), strtotime($shiftEnd));
                $overlapDuration = max(0, $overlapEnd - $overlapStart);
                $totalDuration = strtotime($record->end_time) - strtotime($record->start_time);
                $recordEbUnits = is_numeric($record->eb_units) ? (float) $record->eb_units : 0;
                $proportionalUnits = ($totalDuration > 0)
                    ? ($overlapDuration / $totalDuration) * $recordEbUnits
                    : 0;

                return $proportionalUnits;
            })
            ->sum();

        $ebPrice = DB::table('pulse_settings')
            ->where('key', 'RS_PER_EB_UNIT')
            ->where('team_id', $teamId)
            ->value('value');

        return response()->json([
            'ebUnits' => round($ebUnits, 1),
            'ebAmount' => round($ebUnits * $ebPrice, 1),
        ]);
    }

    // Fetch existing shifts for the selected date from the database
    public function getExistingProductions()
    {
        // Convert the request parameter 'shift_date' to Y-m-d format
        $shiftDate = request('shift_date');
        try {
            $formattedShiftDate = Carbon::createFromFormat(config('app.date_format'), $shiftDate)->format('Y-m-d');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid shift date format'], 400);
        }

        // Query existing productions with related data
        $existingProductions = PithProduction::where('team_id', Auth::user()->currentTeam->id)
            ->where('shift_date', $formattedShiftDate)
            ->orderBy('start_time', 'asc')
            ->with(['shift:id,name', 'staff:id,staff_name'])
            ->get();

        // Map the result to include the required columns
        $result = $existingProductions->map(function ($production) {
            return [
                'shift_id' => $production->shift->id,
                'shift_name' => $production->shift->name,
                'start_time' => $production->start_time,
                'end_time' => $production->end_time,
                'staff_name' => optional($production->staff)->staff_name,
                'loads_produced' => $production->loads_produced,
            ];
        });

        return response()->json($result);
    }
}
