<?php

namespace App\Services\Pulse;

use App\Models\Pulse\PithLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the per-location Opening/Inward/Outward/Settled/Closing stock ledger for a
 * date range, grouped by PithLocationGroup, for the Pith Inventory export
 * (PDF/Image) and the Reports Hub.
 *
 * A location holds one batch at a time. A batch runs from the location's previous
 * closure (a pulse_pith_stock_depletions row) to its own closure; the open batch runs
 * from the last closure onwards. Each pulse_pith_inventory row names the closure that
 * archived it (pith_stock_depletion_id), or none while its batch is still open.
 *
 * A row counts on its "effective date": its own date clamped into its batch's window.
 * So a load back-dated before the previous closure counts from that closure's day, and
 * a location's balance on day D is exactly the running total of its open batch's
 * movement since the last closure on or before D:
 *
 *   balance(D)    = sum(in - out) of rows with effective date <= D whose batch was
 *                   still open at the end of D (closed after D, or not at all)
 *   opening_stock = balance(start - 1 day)
 *   closing_stock = balance(end)
 *   inward/outward = summed for effective date BETWEEN start AND end
 *   write_off_in_range = opening_stock + inward - outward - closing_stock
 *
 * A batch closed on D drops out of balance(D), but a load of the next batch entered
 * the same day already counts in it. write_off_in_range is the signed total the range's
 * closures settled: positive when stock was still on the books at closure, negative
 * when outward had overdrawn the batch. Balances may be negative — an overdrawn open
 * batch is shown as the system holds it until it is closed. Each closure in range is
 * listed with its batch's whole-life inward, outward and outward:inward ratio.
 *
 * The totals stored on the depletion rows are never used: on historical data they
 * drift from the loads they closed (see the loads-to-CFT migration), so a batch's
 * figures are always summed from its loads.
 *
 * watered_litres is the water logged at the location on dates inside the range,
 * whichever batch it belongs to — watering is an activity, not stock, so it has no
 * opening or closing figure.
 */
class PithInventoryExportService
{
    /** Locations with less than this much inward/outward movement (in cft) count as unchanged. */
    private const CHANGE_THRESHOLD = 0.005;

    /**
     * @param  bool  $includeEmpty  keep dormant locations (nothing at either end, nothing moved) as zero rows
     */
    public function buildReport(int $teamId, Carbon $startDate, Carbon $endDate, bool $includeEmpty = false): array
    {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->startOfDay();

        $locations = PithLocation::query()
            ->leftJoin('pulse_pith_location_groups', 'pulse_pith_locations.group_id', '=', 'pulse_pith_location_groups.id')
            ->where('pulse_pith_locations.team_id', $teamId)
            ->select(
                'pulse_pith_locations.id',
                'pulse_pith_locations.name',
                'pulse_pith_locations.group_id',
                'pulse_pith_locations.is_active',
                'pulse_pith_location_groups.name as group_name',
                'pulse_pith_location_groups.display_order as group_display_order',
            )
            ->selectRaw('EXISTS (SELECT 1 FROM pulse_pith_inventory WHERE pulse_pith_inventory.pith_location_id = pulse_pith_locations.id AND pulse_pith_inventory.is_archived = 0) as holds_live_loads')
            ->orderBy('pulse_pith_locations.name')
            ->get();

        $locationIds = $locations->pluck('id')->all();

        if (empty($locationIds)) {
            return $this->emptyReport($teamId, $startDate, $endDate);
        }

        $start = $startDate->toDateString();
        $end = $endDate->toDateString();

        [$windows, $openBatchStarts] = $this->batchWindows($locationIds);
        $wateredRange = $this->sumWaterings($locationIds, $startDate, $endDate);

        $ledger = [];
        $batchTotals = [];
        foreach ($locationIds as $id) {
            $ledger[$id] = ['opening' => 0.0, 'inward' => 0.0, 'outward' => 0.0, 'closing' => 0.0];
        }

        foreach ($this->loadsUpTo($locationIds, $end) as $load) {
            $net = $load->inward - $load->outward;
            $depletionId = $load->pith_stock_depletion_id !== null ? (int) $load->pith_stock_depletion_id : null;

            if ($depletionId !== null && isset($windows[$depletionId])) {
                ['start' => $batchStart, 'end' => $batchEnd] = $windows[$depletionId];
                $batchTotals[$depletionId]['inward'] = ($batchTotals[$depletionId]['inward'] ?? 0) + $load->inward;
                $batchTotals[$depletionId]['outward'] = ($batchTotals[$depletionId]['outward'] ?? 0) + $load->outward;
            } elseif ((int) $load->is_archived === 0) {
                $batchStart = $openBatchStarts[$load->pith_location_id] ?? null;
                $batchEnd = null;
            } else {
                // Archived without a recorded closure (only possible on data the backfill
                // could not place): settle it on its own date.
                $batchStart = $batchEnd = $load->date;
            }

            $effectiveDate = $this->clampDate($load->date, $batchStart, $batchEnd);

            if ($effectiveDate > $end) {
                continue;
            }

            $row = &$ledger[$load->pith_location_id];

            if ($effectiveDate < $start) {
                // Standing at the end of the day before the range while its batch was still open.
                if ($batchEnd === null || $batchEnd >= $start) {
                    $row['opening'] += $net;
                }
            } else {
                $row['inward'] += $load->inward;
                $row['outward'] += $load->outward;
            }

            if ($batchEnd === null || $batchEnd > $end) {
                $row['closing'] += $net;
            }

            unset($row);
        }

        $closures = $this->closuresInRange($windows, $batchTotals, $start, $end);

        $figures = [];
        foreach ($locationIds as $id) {
            ['opening' => $opening, 'inward' => $inward, 'outward' => $outward, 'closing' => $closing] = $ledger[$id];

            // Whatever the range's movement doesn't account for was settled by a closure inside the range.
            $writeOffRangeAmount = $opening + $inward - $outward - $closing;

            $figures[$id] = [
                'opening_stock' => $this->roundValue($opening),
                'inward' => $this->roundValue($inward),
                'outward' => $this->roundValue($outward),
                'closing_stock' => $this->roundValue($closing),
                'write_off_in_range' => $this->roundValue($writeOffRangeAmount),
                'watered_litres' => (int) ($wateredRange[$id] ?? 0),
                'was_closed_in_range' => ! empty($closures[$id]),
                'closures' => $closures[$id] ?? [],
            ];
        }

        return $this->assembleGroups($teamId, $startDate, $endDate, $locations, $figures, $includeEmpty);
    }

    /**
     * Each closure's batch window, plus the day each location's open batch started on.
     * A batch starts on the day of the location's previous closure (null for its first
     * batch) and ends on its own closure day. Closures on one day fall in id order.
     *
     * @param  array<int, int>  $locationIds
     * @return array{0: array<int, array{location_id: int, start: string|null, end: string}>, 1: array<int, string>}
     */
    private function batchWindows(array $locationIds): array
    {
        $depletions = DB::table('pulse_pith_stock_depletions')
            ->whereIn('pith_location_id', $locationIds)
            ->orderBy('depletion_date')
            ->orderBy('id')
            ->get(['id', 'pith_location_id', 'depletion_date']);

        $windows = [];
        $previousClosure = [];

        foreach ($depletions as $depletion) {
            $locationId = (int) $depletion->pith_location_id;
            $closedOn = Carbon::parse($depletion->depletion_date)->toDateString();

            $windows[(int) $depletion->id] = [
                'location_id' => $locationId,
                'start' => $previousClosure[$locationId] ?? null,
                'end' => $closedOn,
            ];

            $previousClosure[$locationId] = $closedOn;
        }

        return [$windows, $previousClosure];
    }

    /**
     * Every row that can land on or before $end: those dated up to it, plus any row of a
     * batch closed by then (a row dated after its own closure counts on the closure day).
     *
     * @param  array<int, int>  $locationIds
     * @return Collection<int, object{pith_location_id:int, date:string, is_archived:int, pith_stock_depletion_id:int|null, inward:float, outward:float}>
     */
    private function loadsUpTo(array $locationIds, string $end): Collection
    {
        return DB::table('pulse_pith_inventory as i')
            ->leftJoin('pulse_pith_stock_depletions as d', 'd.id', '=', 'i.pith_stock_depletion_id')
            ->whereIn('i.pith_location_id', $locationIds)
            ->where(function ($query) use ($end) {
                $query->where('i.date', '<=', $end)
                    ->orWhere('d.depletion_date', '<=', $end);
            })
            ->selectRaw('i.pith_location_id, DATE(i.date) as date, i.is_archived, i.pith_stock_depletion_id, COALESCE(i.cft_offloaded, 0) as inward, COALESCE(i.taken_cft_calculated, 0) as outward')
            ->get()
            ->map(function ($load) {
                $load->pith_location_id = (int) $load->pith_location_id;
                $load->inward = (float) $load->inward;
                $load->outward = (float) $load->outward;

                return $load;
            });
    }

    /**
     * The closures inside the range per location, oldest first, each with its batch's
     * whole-life inward/outward and what it settled (positive: stock cleared from the
     * books; negative: outward had overdrawn the batch).
     *
     * @param  array<int, array{location_id: int, start: string|null, end: string}>  $windows
     * @param  array<int, array{inward?: float, outward?: float}>  $batchTotals
     * @return array<int, list<array{date: string, inward: float, outward: float, outward_ratio: float|null, settled: float}>>
     */
    private function closuresInRange(array $windows, array $batchTotals, string $start, string $end): array
    {
        $closures = [];

        foreach ($windows as $depletionId => $window) {
            if ($window['end'] < $start || $window['end'] > $end) {
                continue;
            }

            $inward = (float) ($batchTotals[$depletionId]['inward'] ?? 0);
            $outward = (float) ($batchTotals[$depletionId]['outward'] ?? 0);

            $closures[$window['location_id']][] = [
                'date' => $window['end'],
                'inward' => $this->roundValue($inward),
                'outward' => $this->roundValue($outward),
                'outward_ratio' => $inward > self::CHANGE_THRESHOLD ? $this->roundValue($outward / $inward * 100) : null,
                'settled' => $this->roundValue($inward - $outward),
            ];
        }

        return $closures;
    }

    private function clampDate(string $date, ?string $from, ?string $to): string
    {
        if ($from !== null && $date < $from) {
            return $from;
        }

        if ($to !== null && $date > $to) {
            return $to;
        }

        return $date;
    }

    /**
     * Litres logged per location on dates inside the range, across every batch.
     *
     * @param  array<int, int>  $locationIds
     * @return Collection<int, int>
     */
    private function sumWaterings(array $locationIds, Carbon $from, Carbon $to): Collection
    {
        return DB::table('pulse_pith_waterings')
            ->whereIn('pith_location_id', $locationIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('pith_location_id, SUM(litres) as litres')
            ->groupBy('pith_location_id')
            ->pluck('litres', 'pith_location_id');
    }

    /**
     * Folds locations into their groups (mirroring the grouping/sort convention used by
     * App\Livewire\Pulse\Pith\PithInventory::buildGroups() and PithHistory::locationGroups()),
     * keeping every reportable location visible — including ones with no inward/outward
     * movement in range — and flagging each with `has_movement` so the UI can highlight the
     * ones that changed rather than hiding the ones that didn't.
     *
     * Two kinds of location are dropped rather than listed as empty rows: deactivated ones
     * with nothing to account for in the range (see isReportable()) and dormant ones — empty
     * at both ends of the range with nothing moving in between. Neither drop can lose real stock, so the rows
     * and the totals still agree. A group left with no rows disappears too. $includeEmpty
     * keeps the dormant ones as zero rows; a deactivated, emptied location stays out.
     */
    private function assembleGroups(int $teamId, Carbon $startDate, Carbon $endDate, Collection $locations, array $ledger, bool $includeEmpty = false): array
    {
        $groups = $locations
            ->groupBy(fn ($location) => (int) ($location->group_id ?? 0))
            ->map(function ($groupLocations, $groupId) use ($ledger, $includeEmpty) {
                $groupId = (int) $groupId;
                $first = $groupLocations->first();

                $shownLocations = [];
                $subtotal = $this->zeroTotals();

                foreach ($groupLocations as $location) {
                    $figures = $ledger[$location->id] ?? $this->zeroTotals();

                    $hasMovement = abs($figures['inward']) > self::CHANGE_THRESHOLD
                        || abs($figures['outward']) > self::CHANGE_THRESHOLD;

                    if (! $this->isReportable($location, $hasMovement, $figures) || (! $includeEmpty && $this->isDormant($figures))) {
                        continue;
                    }

                    foreach ($subtotal as $key => $value) {
                        $subtotal[$key] = $value + $figures[$key];
                    }

                    $shownLocations[] = array_merge(
                        ['id' => $location->id, 'name' => $location->name, 'has_movement' => $hasMovement],
                        $figures
                    );
                }

                return [
                    'id' => $groupId,
                    'name' => $groupId === 0 ? __('Ungrouped') : ($first->group_name ?? __('Ungrouped')),
                    'is_ungrouped' => $groupId === 0,
                    'display_order' => (int) ($first->group_display_order ?? 0),
                    'location_count' => count($shownLocations),
                    'locations' => $shownLocations,
                    'subtotal' => $this->roundTotals($subtotal),
                ];
            })
            ->filter(fn ($group) => $group['location_count'] > 0)
            ->sortBy([
                fn ($a, $b) => $a['is_ungrouped'] <=> $b['is_ungrouped'],
                fn ($a, $b) => $a['display_order'] <=> $b['display_order'],
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values();

        $grandTotal = $this->zeroTotals();
        foreach ($groups as $group) {
            foreach ($grandTotal as $key => $value) {
                $grandTotal[$key] = $value + $group['subtotal'][$key];
            }
        }

        return [
            'team_id' => $teamId,
            'module' => 'pith',
            'inward_label' => 'Inward',
            'outward_label' => 'Outward',
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'generated_at' => now(),
            'include_empty' => $includeEmpty,
            'groups' => $groups->all(),
            'grand_total' => $this->roundTotals($grandTotal),
        ];
    }

    /**
     * A deactivated location only stays on the report while it still has something real to
     * account for: live (non-archived) loads sitting there, inward/outward movement or a
     * closure inside the reported range, or stock at either end of it. Dropping one that
     * held stock at the start would lose that opening from the totals.
     *
     * @param  array<string, mixed>  $figures
     */
    private function isReportable(object $location, bool $hasMovement, array $figures): bool
    {
        return (bool) $location->is_active
            || (bool) $location->holds_live_loads
            || $hasMovement
            || $figures['was_closed_in_range']
            || abs((float) $figures['opening_stock']) > self::CHANGE_THRESHOLD
            || abs((float) $figures['closing_stock']) > self::CHANGE_THRESHOLD;
    }

    /**
     * A dormant location held nothing at either end of the range and saw nothing move in
     * between — no stock, no watering and no closure — so its row would be all zeroes. Note this is
     * checked on the rounded ledger figures, which is what the reader would have seen.
     *
     * @param  array<string, float|bool>  $figures
     */
    private function isDormant(array $figures): bool
    {
        if ($figures['was_closed_in_range']) {
            return false;
        }

        foreach (['opening_stock', 'inward', 'outward', 'closing_stock', 'watered_litres'] as $key) {
            if (abs((float) $figures[$key]) > self::CHANGE_THRESHOLD) {
                return false;
            }
        }

        return true;
    }

    private function emptyReport(int $teamId, Carbon $startDate, Carbon $endDate): array
    {
        return [
            'team_id' => $teamId,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'generated_at' => now(),
            'groups' => [],
            'grand_total' => $this->zeroTotals(),
        ];
    }

    /** @return array<string, float> */
    private function zeroTotals(): array
    {
        return [
            'opening_stock' => 0.0,
            'inward' => 0.0,
            'outward' => 0.0,
            'closing_stock' => 0.0,
            'write_off_in_range' => 0.0,
            'watered_litres' => 0,
        ];
    }

    /**
     * @param  array<string, float>  $totals
     * @return array<string, float>
     */
    private function roundTotals(array $totals): array
    {
        $rounded = array_map(fn ($v) => $this->roundValue((float) $v), $totals);
        $rounded['watered_litres'] = (int) ($totals['watered_litres'] ?? 0);

        return $rounded;
    }

    /**
     * Rounds to 2 decimal places and collapses -0.0 to 0.0. Opposite-signed
     * sums that nearly cancel out (e.g. a location's opening stock against its
     * own closure write-off) can leave a negative-zero float. PHP's
     * json_encode(-0.0) emits the literal "-0", but JavaScript's
     * JSON.stringify(-0) emits "0" — so once that value round-trips through
     * the browser, Livewire's snapshot checksum no longer matches what the
     * server recomputes, and every subsequent request in the export modal
     * fails with a 419 (CorruptComponentPayloadException).
     */
    private function roundValue(float $value): float
    {
        $rounded = round($value, 2);

        return $rounded === 0.0 ? 0.0 : $rounded;
    }
}
