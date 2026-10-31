<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithWatering;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PithInventoryController extends Controller
{
    /**
     * @param  int|null  $teamId  passed by callers that already know their team (the Livewire
     *                            inventory page); a Livewire update carries no {team} in its URL,
     *                            and the saved users.current_team_id may be another factory
     */
    public function index($includeChartData = false, ?int $teamId = null)
    {
        $includeChartData = filter_var($includeChartData, FILTER_VALIDATE_BOOLEAN);
        $teamId ??= Auth::user()->activeTeam()->id;

        // Query for pith_locations (fetch all locations)
        $locations = DB::table('pulse_pith_locations')
            ->leftJoin('pulse_pith_inventory', 'pulse_pith_locations.id', '=', 'pulse_pith_inventory.pith_location_id')
            ->leftJoin('pulse_pith_location_groups', 'pulse_pith_locations.group_id', '=', 'pulse_pith_location_groups.id')
            ->where('pulse_pith_locations.team_id', $teamId)
            ->where(function ($query) {
                $query->where('pulse_pith_locations.is_active', 1)
                    ->orWhere(function ($query) {
                        $query->where('pulse_pith_locations.is_active', 0)
                            ->whereRaw('(
                                        SELECT SUM(cft_offloaded)
                                        FROM pulse_pith_inventory
                                        WHERE pulse_pith_inventory.pith_location_id = pulse_pith_locations.id
                                          AND pulse_pith_inventory.is_archived = 0 ) > 0'
                            );
                    });
            })
            ->distinct()
            ->orderBy('pulse_pith_locations.name', 'asc')
            ->select(
                'pulse_pith_locations.*',
                'pulse_pith_location_groups.name as group_name',
                'pulse_pith_location_groups.display_order as group_display_order'
            )
            ->get();

        // Query for pulse_pith_inventory rows where is_archived = 0 (only non-archived loads)
        $nonArchivedLoads = DB::table('pulse_pith_inventory')
            ->leftJoin('pulse_pith_locations', 'pulse_pith_inventory.pith_location_id', '=', 'pulse_pith_locations.id')
            ->where('pulse_pith_inventory.is_archived', 0) // Only non-archived loads
            ->where('pulse_pith_locations.team_id', $teamId)
            ->select(
                'pulse_pith_locations.id as location_id',
                'pulse_pith_locations.name',
                'pulse_pith_inventory.id as pith_inventory_id',
                'pulse_pith_inventory.date',
                'pulse_pith_inventory.cft_offloaded as loads_offloaded',
                'pulse_pith_inventory.taken_cft_calculated as taken_loads_calculated'
            )
            ->get()
            ->groupBy('location_id');

        // Current-batch waterings only, newest first — a closed batch's waterings move to its depletion row
        $currentWaterings = PithWatering::currentBatch()
            ->whereIn('pith_location_id', $locations->pluck('id'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('pith_location_id');

        // Process and calculate totals for each location based on non-archived loads
        $locationsData = $locations->map(function ($location) use ($nonArchivedLoads, $currentWaterings, $includeChartData) {
            $records = $nonArchivedLoads->get($location->id) ?? collect(); // Get the non-archived records for the location, or an empty collection
            $totalLoadsOffloaded = $records->sum('loads_offloaded');
            $totalLoadsTaken = $records->sum('taken_loads_calculated');
            $remainingStock = max(0, $totalLoadsOffloaded - $totalLoadsTaken);

            $lastOffloadedRecord = $records->where('loads_offloaded', '>', 0)
                ->sortByDesc('date')
                ->first();

            $lastTakenRecord = $records->where('taken_loads_calculated', '>', 0)
                ->sortByDesc('date')
                ->first();

            $lastOffloadedDate = $lastOffloadedRecord ? $lastOffloadedRecord->date : null;
            $lastOffloadedQty = $lastOffloadedRecord ? $lastOffloadedRecord->loads_offloaded : 0;

            $lastTakenDate = $lastTakenRecord ? $lastTakenRecord->date : null;
            $lastTakenQty = $lastTakenRecord ? round($lastTakenRecord->taken_loads_calculated, 2) : 0;

            $waterings = $currentWaterings->get($location->id) ?? collect();
            $lastWatering = $waterings->first();

            $locationData = [
                'location_id' => $location->id,
                'name' => $location->name,
                'is_active' => (bool) $location->is_active,
                'group_id' => $location->group_id,
                'group_name' => $location->group_name,
                'group_display_order' => $location->group_display_order,
                'low_stock_alert_value' => $location->low_stock_alert_value,
                'totalLoadsOffloaded' => $totalLoadsOffloaded,
                'totalLoadsTaken' => round($totalLoadsTaken, 2),
                'remainingStock' => round($remainingStock, 2),
                // Unclamped: the Transfer Pith picker needs the true, possibly-negative figure.
                // Every other consumer keeps reading remainingStock, whose meaning is unchanged.
                'netStock' => round($totalLoadsOffloaded - $totalLoadsTaken, 2),
                'lastOffloadedDate' => $lastOffloadedDate,
                'lastOffloadedQty' => $lastOffloadedQty,
                'lastTakenDate' => $lastTakenDate,
                'lastTakenQty' => $lastTakenQty,
                'totalWaterLitres' => (int) $waterings->sum('litres'),
                'waterCycles' => $waterings->count(),
                'lastWateredDate' => $lastWatering?->date->format('Y-m-d'),
                'lastWateredLitres' => $lastWatering?->litres,
            ];
            // Include 'chartData' only if 'isChartData' is true
            if ($includeChartData) {
                $locationData['chartData'] = $records->map(function ($record) {
                    return [
                        'id' => $record->pith_inventory_id,
                        'date' => $record->date,
                        'loads_offloaded' => $record->loads_offloaded,
                        'taken_loads_calculated' => $record->taken_loads_calculated,
                    ];
                });
            }

            return $locationData;
        });

        $locationsData = $locationsData->filter(function ($location) {
            return (bool) ($location['is_active'] ?? true)
                || (float) ($location['totalLoadsOffloaded'] ?? 0) > 0;
        })->values();

        return response()->json($locationsData, 200);
    }
}
