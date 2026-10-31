<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pulse\Api\Concerns\AuthorizesGeofences;
use App\Models\Pulse\GeofenceLocation;
use App\Support\Pulse\GeofenceBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class GeofenceLocationController extends Controller
{
    use AuthorizesGeofences;

    /**
     * All geofence locations for the factory (owner/admin).
     */
    public function index(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanViewGeofences($request->user());

        $locations = GeofenceLocation::query()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'max_boundaries' => $this->maxBoundaries(),
            'used_boundaries' => $locations->count(),
            'data' => $locations->map(fn (GeofenceLocation $location) => $location->toApiArray()),
        ]);
    }

    public function store(Request $request, $team): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanCreateGeofences($request->user());

        if ($this->usedBoundaryCount($teamId) >= $this->maxBoundaries()) {
            throw ValidationException::withMessages([
                'name' => ['This factory has reached its maximum of '.$this->maxBoundaries().' geofence boundaries.'],
            ]);
        }

        $validated = $this->validateLocation($request);
        $geometry = $this->geometryFromRequest($validated);

        $location = GeofenceLocation::create([
            'team_id' => $teamId,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'status' => $validated['status'] ?? GeofenceLocation::STATUS_ACTIVE,
            ...$geometry,
        ]);

        return response()->json([
            'success' => true,
            'max_boundaries' => $this->maxBoundaries(),
            'used_boundaries' => $this->usedBoundaryCount($teamId),
            'data' => $location->fresh()->toApiArray(),
        ], 201);
    }

    public function show(Request $request, $team, $location): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanViewGeofences($request->user());

        $record = $this->findLocation($teamId, (int) $location);
        if (! $record) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'data' => $record->toApiArray(),
        ]);
    }

    public function update(Request $request, $team, $location): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanEditGeofences($request->user());

        $record = $this->findLocation($teamId, (int) $location);
        if (! $record) {
            return $this->notFound();
        }

        $validated = $this->validateLocation($request, updating: true);
        $payload = [
            'name' => $validated['name'] ?? $record->name,
            'type' => $validated['type'] ?? $record->type,
            'status' => $validated['status'] ?? $record->status,
            'boundary_type' => $validated['boundary_type'] ?? $record->boundary_type,
            'latitude' => $validated['latitude'] ?? $record->latitude,
            'longitude' => $validated['longitude'] ?? $record->longitude,
            'radius' => $validated['radius'] ?? $record->radius,
            'polygon_coordinates' => array_key_exists('polygon_coordinates', $validated)
                ? $validated['polygon_coordinates']
                : $record->polygon_coordinates,
        ];
        $geometry = $this->geometryFromRequest($payload);

        $record->update([
            'name' => $payload['name'],
            'type' => $payload['type'],
            'status' => $payload['status'],
            ...$geometry,
        ]);

        return response()->json([
            'success' => true,
            'data' => $record->fresh()->toApiArray(),
        ]);
    }

    public function deactivate(Request $request, $team, $location): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanEditGeofences($request->user());

        $record = $this->findLocation($teamId, (int) $location);
        if (! $record) {
            return $this->notFound();
        }

        $record->update(['status' => GeofenceLocation::STATUS_INACTIVE]);

        return response()->json([
            'success' => true,
            'data' => $record->fresh()->toApiArray(),
        ]);
    }

    public function destroy(Request $request, $team, $location): JsonResponse
    {
        $teamId = $this->assertTeam($team);
        $this->assertGeofencingEnabled();
        $this->assertCanDeleteGeofences($request->user());

        $record = $this->findLocation($teamId, (int) $location);
        if (! $record) {
            return $this->notFound();
        }

        $record->delete();

        return response()->json([
            'success' => true,
            'deleted' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateLocation(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'type' => [$required, 'string', Rule::in(GeofenceLocation::TYPES)],
            'boundary_type' => [$required, 'string', Rule::in([GeofenceBoundary::TYPE_CIRCLE, GeofenceBoundary::TYPE_POLYGON])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:'.GeofenceBoundary::MIN_CIRCLE_RADIUS_METERS],
            'polygon_coordinates' => ['nullable', 'array'],
            'polygon_coordinates.*.lat' => ['required_with:polygon_coordinates', 'numeric', 'between:-90,90'],
            'polygon_coordinates.*.lng' => ['required_with:polygon_coordinates', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'string', Rule::in([GeofenceLocation::STATUS_ACTIVE, GeofenceLocation::STATUS_INACTIVE])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function geometryFromRequest(array $input): array
    {
        try {
            return GeofenceBoundary::normalize($input);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'boundary_type' => [$e->getMessage()],
            ]);
        }
    }

    private function findLocation(int $teamId, int $id): ?GeofenceLocation
    {
        return GeofenceLocation::query()
            ->where('team_id', $teamId)
            ->where('id', $id)
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Geofence location not found for this factory.',
        ], 404);
    }
}
