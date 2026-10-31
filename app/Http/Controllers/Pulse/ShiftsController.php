<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveShiftScheduleRequest;
use App\Models\Pulse\Shift;
use App\Services\Pulse\ShiftScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ShiftsController extends Controller
{
    public function __construct(protected ShiftScheduleService $scheduleService) {}

    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $anchor = Shift::anchorForTeam($teamId);

        $shifts = Shift::where('team_id', $teamId)
            ->select('id', 'team_id', 'name', 'start_time', 'end_time', 'is_active', 'created_at')
            ->orderedByOffset($anchor)
            ->get()
            ->map(fn (Shift $shift) => $this->presentShift($shift, $anchor));

        return response()->json([
            'shifts' => $shifts,
            'shift_day_anchor' => $anchor,
            'gap_message' => $this->formatGapMessage($this->calculateShiftGaps($teamId)),
        ], 200);
    }

    /**
     * Save the whole schedule (up to 3 shifts) in one atomic call. Shifts missing
     * from the payload are deactivated, never deleted.
     */
    public function saveSchedule(SaveShiftScheduleRequest $request, $teamId = null)
    {
        $teamId = $teamId ?? $request->teamId();

        $shifts = $this->scheduleService->saveSchedule($teamId, $request->validated()['shifts'], Auth::id());

        $anchor = Shift::anchorForTeam($teamId);

        return response()->json([
            'message' => 'Shift schedule saved successfully',
            'shifts' => $shifts->map(fn (Shift $shift) => $this->presentShift($shift, $anchor)),
            'gap_message' => $this->formatGapMessage($this->calculateShiftGaps($teamId)),
        ], 200);
    }

    /**
     * Change the team's shift-day anchor. Rejected with 422 when any active shift
     * would cross the new day boundary.
     */
    public function updateAnchor(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('shift_settings.edit')) {
            return response()->json(['error' => 'You are not authorized to update the shift day anchor.'], 403);
        }

        $validatedData = $request->validate([
            'shift_day_anchor' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
        ], [
            'shift_day_anchor.regex' => 'The shift day anchor must be a valid HH:MM time.',
        ]);

        $this->scheduleService->updateAnchor($teamId, $validatedData['shift_day_anchor'], Auth::id());

        return response()->json([
            'message' => 'Shift day anchor updated successfully',
            'shift_day_anchor' => Shift::anchorForTeam($teamId),
        ], 200);
    }

    public function store(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('shift_settings.create')) {
            return response()->json(['error' => 'You are not authorized to add shifts.'], 403);
        }

        $validatedData = $request->validate([
            'name' => 'required|string|max:50',
            'start_time' => 'required',
            'end_time' => 'required',
        ], [
            'name.required' => 'The shift name field is required.',
            'start_time.required' => 'The start time field is required.',
            'end_time.required' => 'The end time field is required.',
        ]);

        $draft = $this->activeDraft($teamId);
        $draft[] = [
            'id' => null,
            'name' => $validatedData['name'],
            'start_time' => $validatedData['start_time'],
            'end_time' => $validatedData['end_time'],
        ];

        if ($error = $this->firstScheduleError($teamId, $draft)) {
            return response()->json(['error' => $error], 422);
        }

        $this->scheduleService->saveSchedule($teamId, $draft, Auth::id());

        return response()->json([
            'message' => $this->formatGapMessage($this->calculateShiftGaps($teamId)),
        ], 201);
    }

    public function update(Request $request, $id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('shift_settings.edit')) {
            return response()->json(['error' => 'You are not authorized to update shifts.'], 403);
        }

        $shift = Shift::where('id', $id)->where('team_id', $teamId)->first();
        if (! $shift) {
            return response()->json(['error' => 'Shift not found'], 404);
        }

        $validatedData = $request->validate([
            'name' => 'required|string|max:50',
            'start_time' => 'required',
            'end_time' => 'required',
        ], [
            'name.required' => 'The shift name field is required.',
            'start_time.required' => 'The start time field is required.',
            'end_time.required' => 'The end time field is required.',
        ]);

        $updated = [
            'id' => $shift->id,
            'name' => $validatedData['name'],
            'start_time' => $validatedData['start_time'],
            'end_time' => $validatedData['end_time'],
        ];

        $draft = collect($this->activeDraft($teamId))
            ->reject(fn (array $row) => $row['id'] === $shift->id)
            ->push($updated)
            ->values()
            ->all();

        if ($error = $this->firstScheduleError($teamId, $draft)) {
            return response()->json(['error' => $error], 422);
        }

        $shift->update([
            'name' => trim($validatedData['name']),
            'start_time' => $this->scheduleService->normalizeTime($validatedData['start_time']),
            'end_time' => $this->scheduleService->normalizeTime($validatedData['end_time']),
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => $this->formatGapMessage($this->calculateShiftGaps($teamId)),
        ], 200);
    }

    public function edit($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('shift_settings.edit')) {
            return response()->json(['error' => 'You are not authorized to edit shifts.'], 403);
        }

        $shift = Shift::where('id', $id)->where('team_id', $teamId)->first();
        if (! $shift) {
            return response()->json(['error' => 'Shift not found'], 404);
        }

        return response()->json($this->presentShift($shift, Shift::anchorForTeam($teamId)), 200);
    }

    public function destroy($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('shift_settings.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete pulse_shifts.'], 403);
        }

        $shift = Shift::where('id', $id)->where('team_id', $teamId)->first();
        if (! $shift) {
            return response()->json(['error' => 'Shift not found'], 404);
        }

        $shift->delete();

        return response()->json(['message' => 'Shift deleted successfully'], 200);
    }

    public function toggleActive($id)
    {
        if (! Gate::allows('shift_settings.edit')) {
            return response()->json(['error' => 'You are not authorized to toggle active status.'], 403);
        }

        $shift = Shift::findOrFail($id);

        if (! $shift->is_active) {
            $draft = collect($this->activeDraft($shift->team_id))
                ->push([
                    'id' => $shift->id,
                    'name' => $shift->name,
                    'start_time' => $shift->start_time,
                    'end_time' => $shift->end_time,
                ])
                ->values()
                ->all();

            if ($error = $this->firstScheduleError($shift->team_id, $draft)) {
                return response()->json(['error' => $error], 422);
            }
        }

        $shift->is_active = ! $shift->is_active;
        $shift->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Shift status updated successfully.',
            'is_active' => $shift->is_active,
        ]);
    }

    public function calculateShiftGaps($teamId)
    {
        return $this->scheduleService->gapMinutes($teamId) / 60;
    }

    public function formatGapMessage($gapHours)
    {
        if ($gapHours <= (1 / 3600)) {
            return '';
        }

        $hours = floor($gapHours);
        $message = 'Shift Gap: ';

        if ($hours > 0) {
            $message .= $hours.' hours';
        }

        $minutes = round(($gapHours - $hours) * 60);

        if ($minutes > 0) {
            $message .= ($hours > 0 ? ' ' : '').$minutes.' mins';
        }

        return $message;
    }

    /**
     * Shift payload with next-day flags derived from the anchor (kept for
     * API consumers that still expect starts_next_day/ends_next_day).
     *
     * @return array<string, mixed>
     */
    protected function presentShift(Shift $shift, string $anchor): array
    {
        return [
            'id' => $shift->id,
            'team_id' => $shift->team_id,
            'name' => $shift->name,
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
            'starts_next_day' => (int) $shift->startsNextDay($anchor),
            'ends_next_day' => (int) $shift->endsNextDay($anchor),
            'is_active' => $shift->is_active,
            'created_at' => $shift->created_at,
        ];
    }

    /**
     * The team's active shifts in the schedule-payload shape.
     *
     * @return array<int, array{id: int, name: string, start_time: string, end_time: string}>
     */
    protected function activeDraft(int $teamId): array
    {
        return Shift::activeForTeam($teamId)
            ->orderedByOffset(Shift::anchorForTeam($teamId))
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'name' => $shift->name,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
            ])
            ->all();
    }

    protected function firstScheduleError(int $teamId, array $draft): ?string
    {
        $errors = $this->scheduleService->validateSchedule($draft, Shift::anchorForTeam($teamId), $teamId);

        return $errors === [] ? null : reset($errors);
    }
}
