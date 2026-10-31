<?php

namespace App\Services\Pulse;

use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithStockDepletion;
use App\Models\Pulse\PithWatering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A pith location's stock as numbered batches, for the location panel on the inventory page.
 *
 * A batch runs from a location's first entry until its stock is closed. Closed batches are the
 * rows of pulse_pith_stock_depletions, numbered 1..n in the order they were closed; the open one
 * is batch n + 1. A closed batch's loads are the location's rows inside the depletion's
 * first/last inventory id range (the same rule PithLocationLoadsController::stockDepletionHistory
 * uses) and its waterings carry the depletion id. Totals are summed from those loads rather than
 * read off the depletion row, whose stored figures drift from the loads on historical data (see
 * PithInventoryExportService).
 *
 * A transfer's two rows show as "Transfer In/Out" entries naming the other location; they are
 * never editable as plain stock. The transfer as a whole is edited or removed from its source
 * location only — the destination's "Transfer In" row is read-only — and only while both rows
 * are still in open batches.
 *
 * @phpstan-type TransferInfo array{id: int, direction: 'in'|'out', peer_location_name: string|null, note: string|null, changeable: bool}
 * @phpstan-type Entry array{key: string, id: int, kind: 'in'|'out'|'water', date: string, qty: float|int, source: 'production'|'manual'|'transfer', editable: bool, transfer: TransferInfo|null}
 * @phpstan-type Batch array{number: int, depletion_id: int|null, is_current: bool, start_date: string|null, end_date: string|null, inward: float, outward: float, stock: float, water_litres: int, water_cycles: int}
 */
class PithLocationLedgerService
{
    private const TRANSFER_RELATIONS = ['transfer.fromLocation', 'transfer.toLocation', 'transfer.loads'];

    /**
     * @return Batch&array{entries: list<Entry>}
     */
    public function currentBatch(PithLocation $location): array
    {
        $loads = PithInventory::where('pith_location_id', $location->id)
            ->where('is_archived', 0)
            ->with(self::TRANSFER_RELATIONS)
            ->get();

        $waterings = $location->waterings()->currentBatch()->get();

        return $this->summarise(
            number: $this->closedBatchCount($location) + 1,
            depletion: null,
            loads: $loads,
            waterings: $waterings,
        ) + ['entries' => $this->entries($loads, $waterings, true)];
    }

    /**
     * The location's closed batches, newest first, without their entries.
     *
     * @return list<Batch>
     */
    public function pastBatches(PithLocation $location): array
    {
        $depletions = $this->depletions($location);

        if ($depletions->isEmpty()) {
            return [];
        }

        $archivedLoads = PithInventory::where('pith_location_id', $location->id)
            ->whereBetween('id', [(int) $depletions->min('first_pith_inventory_id'), (int) $depletions->max('last_pith_inventory_id')])
            ->get();

        $waterings = PithWatering::whereIn('pith_stock_depletion_id', $depletions->pluck('id'))
            ->get()
            ->groupBy('pith_stock_depletion_id');

        return $depletions
            ->map(fn (PithStockDepletion $depletion, int $index) => $this->summarise(
                number: $index + 1,
                depletion: $depletion,
                loads: $this->loadsOf($depletion, $archivedLoads),
                waterings: $waterings->get($depletion->id) ?? collect(),
            ))
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * One closed batch with its entries, or null when the depletion isn't this location's.
     *
     * @return (Batch&array{entries: list<Entry>})|null
     */
    public function pastBatch(PithLocation $location, int $depletionId): ?array
    {
        $depletions = $this->depletions($location);
        $index = $depletions->search(fn (PithStockDepletion $depletion) => $depletion->id === $depletionId);

        if ($index === false) {
            return null;
        }

        $depletion = $depletions[$index];
        $loads = $this->loadsOf($depletion, PithInventory::where('pith_location_id', $location->id)
            ->whereBetween('id', [(int) $depletion->first_pith_inventory_id, (int) $depletion->last_pith_inventory_id])
            ->with(self::TRANSFER_RELATIONS)
            ->get());
        $waterings = $depletion->waterings()->get();

        return $this->summarise($index + 1, $depletion, $loads, $waterings)
            + ['entries' => $this->entries($loads, $waterings, false)];
    }

    public function closedBatchCount(PithLocation $location): int
    {
        return $location->pithStockDepletions()->count();
    }

    /**
     * @return Collection<int, PithStockDepletion>
     */
    private function depletions(PithLocation $location): Collection
    {
        return $location->pithStockDepletions()->orderBy('id')->get()->values();
    }

    /**
     * @param  Collection<int, PithInventory>  $loads
     * @return Collection<int, PithInventory>
     */
    private function loadsOf(PithStockDepletion $depletion, Collection $loads): Collection
    {
        if ($depletion->first_pith_inventory_id === null || $depletion->last_pith_inventory_id === null) {
            return collect();
        }

        return $loads->filter(fn (PithInventory $load) => $load->id >= $depletion->first_pith_inventory_id
            && $load->id <= $depletion->last_pith_inventory_id)->values();
    }

    /**
     * @param  Collection<int, PithInventory>  $loads
     * @param  Collection<int, PithWatering>  $waterings
     * @return Batch
     */
    private function summarise(int $number, ?PithStockDepletion $depletion, Collection $loads, Collection $waterings): array
    {
        $inward = $loads->isEmpty() && $depletion
            ? (float) $depletion->total_cft_offloaded
            : (float) $loads->sum(fn (PithInventory $load) => (float) $load->cft_offloaded);
        $outward = $loads->isEmpty() && $depletion
            ? (float) $depletion->total_cft_taken_calculated
            : (float) $loads->sum(fn (PithInventory $load) => (float) $load->taken_cft_calculated);

        $dates = $loads->pluck('date')
            ->merge($waterings->map(fn (PithWatering $watering) => $watering->date->toDateString()))
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->sort()
            ->values();

        return [
            'number' => $number,
            'depletion_id' => $depletion?->id,
            'is_current' => $depletion === null,
            'start_date' => $dates->first() ?? ($depletion ? Carbon::parse($depletion->depletion_date)->toDateString() : null),
            'end_date' => $depletion ? Carbon::parse($depletion->depletion_date)->toDateString() : null,
            'inward' => round($inward, 2),
            'outward' => round($outward, 2),
            'stock' => round($inward - $outward, 2),
            'water_litres' => (int) $waterings->sum('litres'),
            'water_cycles' => $waterings->count(),
        ];
    }

    /**
     * One row per inward, outward and watering entry, newest first. A load carrying both an
     * inward and an outward figure (older data) shows as two rows that can't be edited, since
     * neither half is a whole record. Loads assigned from a production record are edited there.
     *
     * @param  Collection<int, PithInventory>  $loads
     * @param  Collection<int, PithWatering>  $waterings
     * @return list<Entry>
     */
    private function entries(Collection $loads, Collection $waterings, bool $isCurrent): array
    {
        $entries = [];

        foreach ($loads as $load) {
            $inward = (float) $load->cft_offloaded;
            $outward = (float) $load->taken_cft_calculated;
            $fromProduction = $load->pith_production_id !== null;
            $transfer = $this->transferInfo($load, $isCurrent);
            $editable = $isCurrent && ! $fromProduction && $transfer === null && ! ($inward > 0 && $outward > 0);
            $date = Carbon::parse($load->date)->toDateString();

            foreach (['in' => $inward, 'out' => $outward] as $kind => $qty) {
                if ($qty <= 0) {
                    continue;
                }

                $entries[] = [
                    'key' => "{$kind}-{$load->id}",
                    'id' => $load->id,
                    'kind' => $kind,
                    'date' => $date,
                    'qty' => round($qty, 2),
                    'source' => $fromProduction ? 'production' : ($transfer ? 'transfer' : 'manual'),
                    'editable' => $editable,
                    'transfer' => $transfer,
                ];
            }
        }

        foreach ($waterings as $watering) {
            $entries[] = [
                'key' => "water-{$watering->id}",
                'id' => $watering->id,
                'kind' => 'water',
                'date' => $watering->date->toDateString(),
                'qty' => $watering->litres,
                'source' => 'manual',
                'editable' => $isCurrent,
                'transfer' => null,
            ];
        }

        usort($entries, fn (array $a, array $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);

        return $entries;
    }

    /**
     * @return TransferInfo|null
     */
    private function transferInfo(PithInventory $load, bool $isCurrent): ?array
    {
        $transfer = $load->pith_transfer_id !== null ? $load->transfer : null;

        if (! $transfer) {
            return null;
        }

        $isOut = (int) $load->pith_location_id === (int) $transfer->from_pith_location_id;

        return [
            'id' => $transfer->id,
            'direction' => $isOut ? 'out' : 'in',
            'peer_location_name' => $isOut ? $transfer->toLocation?->name : $transfer->fromLocation?->name,
            'note' => $transfer->note,
            // Changed from the source only; once either side's batch is closed the stock it
            // moved is settled there.
            'changeable' => $isCurrent && $isOut && $transfer->loads->every(fn (PithInventory $row) => (int) $row->is_archived === 0),
        ];
    }
}
