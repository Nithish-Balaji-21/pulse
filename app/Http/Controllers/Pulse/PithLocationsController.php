<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class PithLocationsController extends Controller
{
    public static function normalizeLowStockAlertValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    /**
     * Longest a location name may be. Short names stay readable in the inventory
     * accordion and the load-entry modal, where the group already carries the site.
     */
    public const NAME_MAX_LENGTH = 20;

    /**
     * Reduce a name to the form used for near-duplicate detection: lowercase,
     * alphanumerics only, with any trailing sequence number dropped. This is what
     * makes "Alam East 2" collide with "alam east", and "New1" with "new 2".
     */
    public static function normalizeNameForComparison(string $name): string
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));

        return preg_replace('/\d+$/', '', $normalized);
    }

    /**
     * Name of an existing location in the same group that is confusingly close to
     * the candidate, or null when the name is distinct enough. Scoped to the group
     * because "East" under Alam and "East" under Hexagro are not a clash.
     */
    public static function findSimilarName(string $name, int|string $teamId, ?int $ignoreId = null, ?int $groupId = null): ?string
    {
        $candidate = self::normalizeNameForComparison($name);

        if ($candidate === '') {
            return null;
        }

        return PithLocation::where('team_id', $teamId)
            ->when($groupId, fn ($query) => $query->where('group_id', $groupId))
            ->when($groupId === null, fn ($query) => $query->whereNull('group_id'))
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->pluck('name')
            ->first(fn ($existing) => self::normalizeNameForComparison($existing) === $candidate);
    }

    /**
     * Unique rule for a location name within its group. Ungrouped locations are
     * treated as their own bucket rather than escaping the check on NULL.
     */
    private static function uniqueNameRule(int|string $teamId, ?int $groupId, ?int $ignoreId = null): Unique
    {
        return Rule::unique('pulse_pith_locations', 'name')
            ->where(fn ($query) => $query
                ->where('team_id', $teamId)
                ->when($groupId === null, fn ($q) => $q->whereNull('group_id'), fn ($q) => $q->where('group_id', $groupId)))
            ->when((bool) $ignoreId, fn (Unique $rule) => $rule->ignore($ignoreId));
    }

    /**
     * Resolve a submitted group selection to a valid group id owned by the team,
     * or null when the location should stay ungrouped.
     */
    public static function normalizeGroupId(mixed $value, int|string $teamId): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        $exists = PithLocationGroup::where('id', (int) $value)
            ->where('team_id', $teamId)
            ->exists();

        return $exists ? (int) $value : null;
    }

    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        // Fetch locations specific to the current team
        $pithLocations = PithLocation::where('pulse_pith_locations.team_id', $teamId)
            ->leftJoin('pulse_pith_location_groups', 'pulse_pith_locations.group_id', '=', 'pulse_pith_location_groups.id')
            ->leftJoin('pulse_pith_inventory', function ($join) {
                $join->on('pulse_pith_inventory.pith_location_id', '=', 'pulse_pith_locations.id')
                    ->where('pulse_pith_inventory.is_archived', 0);
            })
            ->groupBy(
                'pulse_pith_locations.id',
                'pulse_pith_locations.team_id',
                'pulse_pith_locations.group_id',
                'pulse_pith_locations.name',
                'pulse_pith_locations.is_active',
                'pulse_pith_locations.low_stock_alert_value',
                'pulse_pith_location_groups.name',
                'pulse_pith_location_groups.display_order'
            )
            ->orderByRaw('pulse_pith_location_groups.display_order IS NULL, pulse_pith_location_groups.display_order')
            ->orderByRaw('pulse_pith_location_groups.name IS NULL, pulse_pith_location_groups.name')
            ->orderBy('pulse_pith_locations.name')
            ->select(
                'pulse_pith_locations.id',
                'pulse_pith_locations.team_id',
                'pulse_pith_locations.group_id',
                'pulse_pith_locations.name',
                'pulse_pith_locations.is_active',
                'pulse_pith_locations.low_stock_alert_value',
                'pulse_pith_location_groups.name as group_name',
                DB::raw('ROUND(COALESCE(SUM(pulse_pith_inventory.cft_offloaded), 0) - COALESCE(SUM(pulse_pith_inventory.taken_cft_calculated), 0), 2) as remaining_cft')
            )
            ->get();

        return response()->json($pithLocations, 200);
    }

    public function store(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_locations.create')) {
            return response()->json(['error' => 'You are not authorized to add pith locations.'], 403);
        }

        $groupId = self::normalizeGroupId($request->input('group_id'), $teamId);

        try {
            $validatedData = $request->validate([
                'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH, self::uniqueNameRule($teamId, $groupId)],
                'low_stock_alert_value' => 'nullable|integer|min:0',
                'group_id' => 'nullable|integer',
            ], [
                'name.required' => 'The name field is required.',
                'name.unique' => 'This name already exists in this group.',
            ]);

            PithLocation::Create([
                'team_id' => $teamId,
                'name' => $validatedData['name'],
                'group_id' => $groupId,
                'low_stock_alert_value' => self::normalizeLowStockAlertValue(
                    $validatedData['low_stock_alert_value'] ?? $request->input('low_stock_alert_value')
                ),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['message' => 'Pith location created successfully'], 201);
    }

    public function update(Request $request, $id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_locations.edit')) {
            return response()->json(['error' => 'You are not authorized to update pith locations.'], 403);
        }

        $pithLocations = PithLocation::where('id', $id)
            ->where('team_id', $teamId);

        $groupId = self::normalizeGroupId($request->input('group_id'), $teamId);

        try {
            $validatedData = $request->validate([
                'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH, self::uniqueNameRule($teamId, $groupId, (int) $id)],
                'low_stock_alert_value' => 'nullable|integer|min:0',
                'group_id' => 'nullable|integer',
            ], [
                'name.required' => 'The name field is required.',
                'name.unique' => 'This name exists already in this group.',
            ]);

            $pithLocations->update([
                'name' => $validatedData['name'],
                'group_id' => $groupId,
                'low_stock_alert_value' => self::normalizeLowStockAlertValue(
                    $validatedData['low_stock_alert_value'] ?? $request->input('low_stock_alert_value')
                ),
            ]);

            return response()->json(['message' => 'Pith location updated successfully'], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    public function edit($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_locations.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pith locations.'], 403);
        }

        $pithLocation = PithLocation::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $pithLocation) {
            return response()->json(['message' => 'Location does not exist'], 404);
        }

        return response()->json($pithLocation, 200);
    }

    public function destroy($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_locations.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete pith locations.'], 403);
        }

        $pithLocation = PithLocation::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $pithLocation) {
            return response()->json(['message' => 'Location does not exist'], 404);
        }

        $hasPithLoads = $pithLocation->pithLoads()->exists();
        if ($hasPithLoads) {
            return response()->json(['message' => 'Cannot delete location because it is referenced in pith loads'], 401);
        }

        $pithLocation->delete();

        return response()->json(['message' => 'Location deleted successfully'], 200);
    }

    public function toggleActive($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_locations.edit')) {
            return response()->json(['error' => 'You are not authorized to toggle active status.'], 403);
        }

        $pithLocation = PithLocation::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $pithLocation) {
            return response()->json(['message' => 'Location does not exist'], 404);
        }

        // A location holding stock must be emptied before it can be hidden, otherwise
        // its remaining cft drops out of the active inventory view. Reactivating is
        // always allowed.
        if ($pithLocation->is_active) {
            $remainingCft = $pithLocation->remainingStockCft();

            if ($remainingCft > 0) {
                return response()->json([
                    'message' => 'Cannot deactivate this location while it still holds stock. Empty it first.',
                    'remaining_cft' => $remainingCft,
                ], 422);
            }
        }

        $pithLocation->is_active = ! $pithLocation->is_active;
        $pithLocation->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Location status updated successfully.',
            'is_active' => $pithLocation->is_active,
        ], 200);
    }
}
