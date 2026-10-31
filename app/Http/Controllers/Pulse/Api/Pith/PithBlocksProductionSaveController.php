<?php

namespace App\Http\Controllers\Pulse\Api\Pith;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithBlocksProduction;
use App\Models\Pulse\Shift;
use App\Models\Staff;
use App\Traits\ChecksProductionOverlap;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PithBlocksProductionSaveController extends Controller
{
    use ChecksProductionOverlap;

    public function add()
    {
        $teamId = Auth::user()->currentTeam->id;
        if (! $teamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        $policy = PithBlocksProduction::entryPolicyFor($teamId);

        return response()->json(array_merge([
            'staffs' => Staff::select('id', 'staff_name')->where('team_id', $teamId)->where('is_active', 1)->orderBy('staff_name')->get(),
            'shift' => ['start_time' => Shift::anchorForTeam($teamId), 'end_time' => Shift::anchorForTeam($teamId)],
            'min_editable_date' => $policy->minShiftDate(),
            'max_create_date' => $policy->maxCreateShiftDate(),
        ], $policy->toApiMetadata()));
    }

    public function store(Request $request)
    {
        $teamId = Auth::user()->currentTeam->id;
        $deviceId = DB::table('pulse_devices')->where('team_id', $teamId)->where('module', 'pith')->value('device_id');

        if (! $deviceId) {
            return response()->json(['error' => 'No active device found for the current team'], 400);
        }

        if (! Gate::allows('pith_blocks.create')) {
            return response()->json(['error' => 'You are not authorized to add pith production.'], 403);
        }

        $policy = PithBlocksProduction::entryPolicyFor($teamId);

        try {
            $shift_date = Carbon::parse($request->input('date'))->format('Y-m-d');
            $anchor = Shift::anchorForTeam($teamId);
            $start = Carbon::parse($shift_date)->setTimeFromTimeString($anchor);
            $end = $start->copy()->addDay();

            $start_time = $request->filled('start_time') ? Carbon::parse($request->input('start_time'))->format('Y-m-d H:i:s') : $start->toDateTimeString();
            $end_time = $request->filled('end_time') ? Carbon::parse($request->input('end_time'))->format('Y-m-d H:i:s') : $end->toDateTimeString();

            if (Carbon::parse($end_time)->lessThan(Carbon::parse($start_time))) {
                $end_time = Carbon::parse($end_time)->addDay()->format('Y-m-d H:i:s');
            }

            $request->merge(['shift_date' => $shift_date, 'start_time' => $start_time, 'end_time' => $end_time]);

            $validated = $request->validate([
                'shift_date' => 'required|date_format:Y-m-d|after_or_equal:'.$policy->minShiftDate().'|before_or_equal:'.$policy->maxCreateShiftDate(),
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
                'staff_id' => 'nullable|integer|exists:staffs,id',
                'blocks_produced' => 'required|integer|min:0',
                'sold' => 'nullable|integer|min:0',
                'correction' => 'nullable|integer',
                'correction_comment' => 'nullable|string|max:150',
                'blocks_in_stock' => 'nullable|integer|min:0',
            ]);

            if (! $policy->canCreate($validated['shift_date'])) {
                return response()->json(['error' => 'Cannot create production records outside the allowed entry window.'], 403);
            }

            if (PithBlocksProduction::where('team_id', $teamId)->whereDate('shift_date', $validated['shift_date'])->exists()) {
                return response()->json(['error' => 'Pith Blocks production already exists for this date.'], 422);
            }

            if ($this->productionOverlaps(PithBlocksProduction::class, $teamId, $deviceId, $validated['start_time'], $validated['end_time'])) {
                return response()->json(['error' => 'Shift timings overlap with an existing record.'], 422);
            }

            $payload = [
                'team_id' => $teamId,
                'device_id' => $deviceId,
                'shift_date' => $validated['shift_date'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'staff_id' => $validated['staff_id'] ?? null,
                'blocks_produced' => $validated['blocks_produced'],
            ];

            foreach (['sold', 'correction', 'correction_comment'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            PithBlocksProduction::create($payload);

            if ($request->filled('blocks_in_stock')) {
                PithBlocksProduction::recordStockTake($teamId, $validated['shift_date'], (int) $validated['blocks_in_stock'], 'Stock-take from production entry');
            }

            return response()->json(['message' => 'Pith blocks production saved successfully.'], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while creating the record: '.$e->getMessage()], 500);
        }
    }

    public function edit($id)
    {
        $teamId = Auth::user()->currentTeam->id;
        $production = PithBlocksProduction::where('id', $id)->where('team_id', $teamId)->with(['staff:id,staff_name'])->first();

        if (! $production) {
            return response()->json(['error' => 'Production record not found'], 404);
        }

        $policy = PithBlocksProduction::entryPolicyFor($teamId);

        return response()->json(array_merge([
            'production' => [
                'id' => $production->id,
                'date' => Carbon::parse($production->shift_date)->format(config('app.date_format')),
                'staff' => ['id' => optional($production->staff)->id ?? '', 'staff_name' => optional($production->staff)->staff_name ?? ''],
                'shift' => [
                    'id' => null,
                    'start_time' => $production->start_time ? Carbon::parse($production->start_time)->format(config('app.date_time_format')) : null,
                    'end_time' => $production->end_time ? Carbon::parse($production->end_time)->format(config('app.date_time_format')) : null,
                ],
                'blocks_produced' => (int) ($production->blocks_produced ?? 0),
                'blocks_in_stock' => (int) ($production->blocks_in_stock ?? 0),
                'sold' => $production->sold,
                'correction' => (int) ($production->correction ?? 0),
                'correction_comment' => $production->correction_comment,
            ],
            'staffs' => Staff::select('id', 'staff_name')->where('team_id', $teamId)->where('is_active', 1)->orderBy('staff_name')->get(),
            'shift' => ['start_time' => Shift::anchorForTeam($teamId), 'end_time' => Shift::anchorForTeam($teamId)],
            'min_editable_date' => $policy->minShiftDate(),
            'max_create_date' => $policy->maxCreateShiftDate(),
        ], $policy->toApiMetadata()));
    }

    public function update(Request $request, $id)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        if (! Gate::allows('pith_blocks.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pith production.'], 403);
        }

        $production = PithBlocksProduction::where('id', $id)->where('team_id', $currentTeamId)->first();
        if (! $production) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        if (! $production->canEditRecord()) {
            return response()->json(['error' => 'This pith blocks record is locked or outside the allowed edit window.'], 403);
        }

        $policy = PithBlocksProduction::entryPolicyFor($currentTeamId);

        try {
            $shift_date = Carbon::createFromFormat(config('app.date_format'), $request->input('date'))->format('Y-m-d');
            $anchor = Shift::anchorForTeam($currentTeamId);
            $defaultStart = Carbon::parse($shift_date)->setTimeFromTimeString($anchor);
            $defaultEnd = $defaultStart->copy()->addDay();

            $start_time = $request->filled('start_time') ? Carbon::createFromFormat(config('app.date_time_format'), $request->input('start_time'))->format('Y-m-d H:i:s') : $defaultStart->toDateTimeString();
            $end_time = $request->filled('end_time') ? Carbon::createFromFormat(config('app.date_time_format'), $request->input('end_time'))->format('Y-m-d H:i:s') : $defaultEnd->toDateTimeString();

            if (Carbon::parse($end_time)->lessThan(Carbon::parse($start_time))) {
                $end_time = Carbon::parse($end_time)->addDay()->format('Y-m-d H:i:s');
            }

            $request->merge(['shift_date' => $shift_date, 'start_time' => $start_time, 'end_time' => $end_time]);

            $validated = $request->validate([
                'shift_date' => 'required|date_format:Y-m-d|after_or_equal:'.$policy->minShiftDate().'|before_or_equal:'.$policy->liveShiftDay(),
                'staff_id' => 'nullable|exists:staffs,id',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
                'blocks_produced' => 'required|integer|min:0',
                'sold' => 'nullable|integer|min:0',
                'correction' => 'nullable|integer',
                'correction_comment' => 'nullable|string|max:150',
                'blocks_in_stock' => 'nullable|integer|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        if (! $policy->canEdit($validated['shift_date'])) {
            return response()->json(['error' => 'Cannot move production records outside the allowed edit window.'], 403);
        }

        if (PithBlocksProduction::where('team_id', $currentTeamId)->whereDate('shift_date', $validated['shift_date'])->where('id', '!=', $production->id)->exists()) {
            return response()->json(['error' => 'Pith Blocks production already exists for this date.'], 422);
        }

        if ($this->productionOverlaps(PithBlocksProduction::class, $currentTeamId, $production->device_id, $validated['start_time'], $validated['end_time'], $production->id)) {
            return response()->json(['error' => 'Shift timings overlap with an existing record.'], 422);
        }

        $payload = [
            'shift_date' => $validated['shift_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'staff_id' => $validated['staff_id'] ?? null,
            'blocks_produced' => $validated['blocks_produced'],
        ];

        foreach (['sold', 'correction', 'correction_comment'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        $production->update($payload);

        if ($request->filled('blocks_in_stock')) {
            PithBlocksProduction::recordStockTake($currentTeamId, $validated['shift_date'], (int) $validated['blocks_in_stock'], 'Stock-take from production entry');
        }

        return response()->json(['message' => 'Pith blocks production updated successfully.'], 200);
    }
}
