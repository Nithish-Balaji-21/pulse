<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithWatering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Watering cycles logged against a pith location from the mobile app.
 *
 * Only the current (open) batch may be changed. The app undoes a delete by calling store()
 * again, which always writes to the current batch — so editing or deleting a closed batch's
 * watering would let an undo quietly move it into the open one.
 */
class PithWateringController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! Gate::allows('pith_watering.create')) {
            return response()->json(['message' => 'You are not authorized to add watering.'], 403);
        }

        $teamId = $request->user()->activeTeam()->id;

        $validatedData = $request->validate([
            'pith_location_id' => [
                'required',
                'integer',
                Rule::exists('pulse_pith_locations', 'id')->where('team_id', $teamId),
            ],
            ...$this->wateringRules(),
        ], [
            'pith_location_id.required' => 'Location is required.',
            'pith_location_id.exists' => 'Location not found.',
            ...$this->wateringMessages(),
        ]);

        $watering = PithWatering::create([
            'pith_location_id' => $validatedData['pith_location_id'],
            'date' => $validatedData['date'],
            'litres' => $validatedData['litres'],
        ]);

        return response()->json([
            'message' => 'Watering added successfully',
            'watering' => $watering->toApiArray(),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! Gate::allows('pith_watering.edit')) {
            return response()->json(['message' => 'You are not authorized to update watering.'], 403);
        }

        $watering = $this->findForTeam($request, $id);

        if (! $watering) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        if ($watering->isArchived()) {
            return $this->closedBatchResponse();
        }

        $validatedData = $request->validate($this->wateringRules(), $this->wateringMessages());

        $watering->update([
            'date' => $validatedData['date'],
            'litres' => $validatedData['litres'],
        ]);

        return response()->json([
            'message' => 'Watering updated successfully',
            'watering' => $watering->toApiArray(),
        ], 200);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! Gate::allows('pith_watering.delete')) {
            return response()->json(['message' => 'You are not authorized to delete watering.'], 403);
        }

        $watering = $this->findForTeam($request, $id);

        if (! $watering) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        if ($watering->isArchived()) {
            return $this->closedBatchResponse();
        }

        $watering->delete();

        return response()->json(['message' => 'Watering deleted successfully'], 200);
    }

    private function findForTeam(Request $request, string $id): ?PithWatering
    {
        $teamId = $request->user()->activeTeam()->id;

        return PithWatering::whereKey($id)
            ->whereHas('pithLocation', fn ($query) => $query->where('team_id', $teamId))
            ->first();
    }

    private function closedBatchResponse(): JsonResponse
    {
        return response()->json([
            'message' => "This watering belongs to a closed batch and can't be changed.",
        ], 422);
    }

    /**
     * @return array<string, list<string>>
     */
    private function wateringRules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'litres' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function wateringMessages(): array
    {
        return [
            'date.required' => 'Date is required.',
            'date.date_format' => 'Date must be in YYYY-MM-DD format.',
            'litres.required' => 'Litres is required.',
            'litres.integer' => 'Litres must be a whole number.',
            'litres.min' => 'Litres must be at least 1.',
            'litres.max' => 'Litres cannot exceed 999999.',
        ];
    }
}
