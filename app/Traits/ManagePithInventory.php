<?php

namespace App\Traits;

use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithProduction;

trait ManagePithInventory
{
    public function fetchInventoryData($productionId, $teamId)
    {
        $production = PithProduction::find($productionId);
        $productionShiftDate = $production->shift_date;
        $productionLoadsTotal = $production->cft_produced;

        $pithLocations = PithLocation::leftJoin('pulse_pith_inventory', 'pulse_pith_locations.id', '=', 'pulse_pith_inventory.pith_location_id')
            ->leftJoin('pulse_pith_location_groups', 'pulse_pith_locations.group_id', '=', 'pulse_pith_location_groups.id')
            ->where('pulse_pith_locations.team_id', $teamId)
            ->where(function ($query) use ($productionId) {
                $query->where('pulse_pith_locations.is_active', true)
                    ->orWhere(function ($query) use ($productionId) {
                        $query->where('pulse_pith_locations.is_active', false)
                            ->where('pulse_pith_inventory.pith_production_id', $productionId)
                            ->groupBy('pulse_pith_locations.id')
                            ->havingRaw('SUM(pulse_pith_inventory.cft_offloaded) > 0');
                    });
            })
            ->select(
                'pulse_pith_locations.*',
                'pulse_pith_location_groups.name as group_name',
                'pulse_pith_location_groups.display_order as group_display_order'
            )
            ->distinct()
            ->orderBy('pulse_pith_locations.name', 'asc')
            ->get()
            ->toArray();

        $pithLoads = PithInventory::where('pith_production_id', $productionId)->get();

        $pithLocationLoads = [];
        foreach ($pithLocations as $pithLocation) {
            $pithLocationLoad = $pithLoads->firstWhere('pith_location_id', $pithLocation['id']);
            $cftVal = $pithLocationLoad ? $pithLocationLoad->cft_offloaded : null;
            $pithLocationLoads[$pithLocation['id']] = [
                'cft_offloaded' => ($cftVal !== null && (float) $cftVal != 0) ? (float) $cftVal : '',
                'is_archived' => $pithLocationLoad->is_archived ?? 0,
            ];
        }

        return compact('production', 'productionShiftDate', 'productionLoadsTotal', 'pithLocations', 'pithLocationLoads');
    }

    /**
     * Fold $this->pithLocations into their groups for the inventory entry modal,
     * carrying the cft already assigned to each group in this production.
     * Ungrouped locations collect under id 0 and always sort last.
     *
     * @return array<int, array{id: int, name: string, is_ungrouped: bool, locations: array<int, array<string, mixed>>, assigned: float}>
     */
    public function groupedPithLocations(): array
    {
        $loads = collect($this->pithLocationLoads ?? []);

        return collect($this->pithLocations ?? [])
            ->groupBy(fn ($location) => (int) ($location['group_id'] ?? 0))
            ->map(function ($groupLocations, $groupId) use ($loads) {
                $groupId = (int) $groupId;

                $assigned = $groupLocations->sum(function ($location) use ($loads) {
                    $value = $loads->get($location['id'])['cft_offloaded'] ?? null;

                    return is_numeric($value) ? (float) $value : 0;
                });

                return [
                    'id' => $groupId,
                    'name' => $groupId === 0 ? __('Ungrouped') : ($groupLocations->first()['group_name'] ?? __('Ungrouped')),
                    'is_ungrouped' => $groupId === 0,
                    'display_order' => (int) ($groupLocations->first()['group_display_order'] ?? 0),
                    'locations' => $groupLocations->values()->all(),
                    'assigned' => $assigned,
                ];
            })
            ->sortBy([
                fn ($a, $b) => $a['is_ungrouped'] <=> $b['is_ungrouped'],
                fn ($a, $b) => $a['display_order'] <=> $b['display_order'],
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();
    }

    /**
     * The offload total this production will carry once $pithLocationLoads is applied.
     *
     * updateInventoryLoads() only writes the locations present in the submitted array and
     * leaves every other row untouched, so the figure worth validating is the end state:
     * submitted values for the locations in the form, persisted values for the rest.
     * Summing the submitted array alone under-counts a partial form, and reports a total
     * of zero — no conflict at all — when the form arrives empty.
     *
     * Archived rows are included, matching PithProduction::getTotalPithLoadsAttribute()
     * and the nightly system:check_errors reconciliation. Archiving marks pith as
     * consumed later, not as never offloaded.
     *
     * @param  array<int, array{cft_offloaded: mixed, is_archived: mixed}>  $pithLocationLoads
     */
    public function projectedInventoryTotal($productionId, array $pithLocationLoads): float
    {
        $submitted = [];

        foreach ($pithLocationLoads as $pithLocationId => $loadData) {
            // An entry flagged archived is skipped by updateInventoryLoads(), so its
            // persisted row survives and must be counted from the database instead.
            if (($loadData['is_archived'] ?? 0) == 1) {
                continue;
            }

            $value = $loadData['cft_offloaded'] ?? null;
            $submitted[(int) $pithLocationId] = is_numeric($value) ? round((float) $value) : 0.0;
        }

        // A production being created has nothing persisted to carry forward.
        if (empty($productionId)) {
            return (float) array_sum($submitted);
        }

        $untouched = PithInventory::where('pith_production_id', $productionId)
            ->when($submitted !== [], fn ($query) => $query->whereNotIn('pith_location_id', array_keys($submitted)))
            ->sum('cft_offloaded');

        return (float) $untouched + array_sum($submitted);
    }

    /**
     * The message to show when a production's offloads would exceed what it produced,
     * or null when the submission is consistent.
     *
     * Call this before writing the production row. The same comparison inside
     * updateInventoryLoads() runs after cft_produced has already been saved, so on its
     * own it reports the conflict while leaving it in the database.
     *
     * @param  array<int, array{cft_offloaded: mixed, is_archived: mixed}>  $pithLocationLoads
     */
    public function inventoryDistributionError($productionId, array $pithLocationLoads, $productionLoadsTotal): ?string
    {
        $projected = $this->projectedInventoryTotal($productionId, $pithLocationLoads);

        if ($projected > (float) $productionLoadsTotal) {
            return 'Error: Total distributed CFT ('.round($projected).') exceeds CFT produced ('.round((float) $productionLoadsTotal).'). Adjust the location loads first.';
        }

        return null;
    }

    public function updateInventoryLoads($productionId, $pithLocationLoads, $productionLoadsTotal, $productionShiftDate)
    {
        $pithLocationLoads = array_map(function ($load) {
            $val = $load['cft_offloaded'];
            if ($val === '' || $val === null) {
                $val = 0;
            }
            $val = (float) $val;
            $load['cft_offloaded'] = round($val);

            return $load;
        }, $pithLocationLoads);

        $totalDistributedLoads = array_sum(array_column($pithLocationLoads, 'cft_offloaded'));

        if ($totalDistributedLoads > $productionLoadsTotal) {
            return ['error' => 'Error: Total distributed CFT exceeds CFT produced.'];
        }

        foreach ($pithLocationLoads as $pithLocationId => $loadData) {
            if ($loadData['is_archived'] == 0) {
                if ($loadData['cft_offloaded'] == 0) {
                    PithInventory::where('pith_location_id', $pithLocationId)
                        ->where('pith_production_id', $productionId)
                        ->delete();
                } else {
                    PithInventory::updateOrCreate(
                        ['pith_production_id' => $productionId, 'pith_location_id' => $pithLocationId],
                        ['cft_offloaded' => $loadData['cft_offloaded'], 'date' => $productionShiftDate]
                    );
                }
            }
        }

        return ['success' => 'Inventory successfully updated'];
    }
}
