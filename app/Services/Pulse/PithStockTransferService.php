<?php

namespace App\Services\Pulse;

use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves pith from one location to another, for both the web inventory page and the mobile API.
 *
 * A transfer writes an outward row at the source and an inward row at the destination, both
 * tagged with the transfer's id, so every existing stock figure stays a plain sum over the
 * ledger. The source is not required to have enough stock in hand — like Stock Out, a transfer
 * is allowed to leave it negative. The write still happens inside a transaction holding a lock
 * on both locations, so the two ledger rows and the closed-batch check stay consistent under
 * concurrent transfers.
 *
 * Rule failures are thrown as ValidationException keyed from_location_id / to_location_id / cft.
 */
class PithStockTransferService
{
    public const MAX_CFT = 999999;

    /**
     * The location's open-batch stock, in cft. Can be negative once it has been overdrawn.
     */
    public function availableStock(PithLocation $location): float
    {
        $sums = PithInventory::where('pith_location_id', $location->id)
            ->where('is_archived', 0)
            ->selectRaw('COALESCE(SUM(cft_offloaded), 0) as inward, COALESCE(SUM(taken_cft_calculated), 0) as outward')
            ->first();

        return round((float) $sums->inward - (float) $sums->outward, 2);
    }

    public function transfer(
        int $teamId,
        int $fromLocationId,
        int $toLocationId,
        float $cft,
        string $date,
        ?string $note = null,
        ?int $userId = null,
    ): PithTransfer {
        $cft = round($cft, 2);
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        if ($fromLocationId === $toLocationId) {
            $this->fail('to_location_id', __('Source and destination locations cannot be the same.'));
        }

        if ($cft <= 0) {
            $this->fail('cft', __('Transfer quantity must be greater than 0 cft.'));
        }

        if ($cft > self::MAX_CFT) {
            $this->fail('cft', __('Transfer quantity cannot exceed :max cft.', ['max' => self::MAX_CFT]));
        }

        return DB::transaction(function () use ($teamId, $fromLocationId, $toLocationId, $cft, $date, $note, $userId) {
            // Locked in id order, so opposing transfers between the same pair can't deadlock.
            $locations = PithLocation::where('team_id', $teamId)
                ->whereIn('id', [$fromLocationId, $toLocationId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $from = $locations->get($fromLocationId);
            $to = $locations->get($toLocationId);

            if (! $from) {
                $this->fail('from_location_id', __('Source location not found.'));
            }

            if (! $to) {
                $this->fail('to_location_id', __('Destination location not found.'));
            }

            if (! $to->is_active) {
                $this->fail('to_location_id', __(':location is inactive and cannot receive stock.', ['location' => $to->name]));
            }

            $transfer = PithTransfer::create([
                'from_pith_location_id' => $from->id,
                'to_pith_location_id' => $to->id,
                'date' => $date,
                'cft' => $cft,
                'note' => $note,
                'created_by' => $userId,
            ]);

            PithInventory::create([
                'pith_location_id' => $from->id,
                'pith_transfer_id' => $transfer->id,
                'date' => $date,
                'taken_cft_calculated' => $cft,
            ]);

            PithInventory::create([
                'pith_location_id' => $to->id,
                'pith_transfer_id' => $transfer->id,
                'date' => $date,
                'cft_offloaded' => $cft,
            ]);

            return $transfer->setRelation('fromLocation', $from)->setRelation('toLocation', $to);
        });
    }

    /**
     * Changes a transfer's destination, quantity, date or comment. The source stays fixed.
     *
     * A new destination gets a fresh inward row rather than having the old one moved across:
     * a row keeps its id, and an old id could fall inside one of the new location's closed-batch
     * id ranges, which would pull it into that closed batch.
     */
    public function update(
        PithTransfer $transfer,
        int $toLocationId,
        float $cft,
        string $date,
        ?string $note = null,
    ): PithTransfer {
        $cft = round($cft, 2);
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $fromLocationId = (int) $transfer->from_pith_location_id;

        if ($fromLocationId === $toLocationId) {
            $this->fail('to_location_id', __('Source and destination locations cannot be the same.'));
        }

        if ($cft <= 0) {
            $this->fail('cft', __('Transfer quantity must be greater than 0 cft.'));
        }

        if ($cft > self::MAX_CFT) {
            $this->fail('cft', __('Transfer quantity cannot exceed :max cft.', ['max' => self::MAX_CFT]));
        }

        return DB::transaction(function () use ($transfer, $fromLocationId, $toLocationId, $cft, $date, $note) {
            $from = PithLocation::whereKey($fromLocationId)->lockForUpdate()->first();
            $teamId = (int) $from->team_id;

            $locations = PithLocation::where('team_id', $teamId)
                ->whereIn('id', [$fromLocationId, $toLocationId, (int) $transfer->to_pith_location_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $to = $locations->get($toLocationId);

            if (! $to) {
                $this->fail('to_location_id', __('Destination location not found.'));
            }

            $loads = $transfer->loads()->lockForUpdate()->get();

            if ($loads->contains(fn (PithInventory $load) => (int) $load->is_archived === 1)) {
                $this->fail('transfer', __('This transfer belongs to a closed batch and can\'t be changed.'));
            }

            $destinationChanged = $toLocationId !== (int) $transfer->to_pith_location_id;

            if ($destinationChanged && ! $to->is_active) {
                $this->fail('to_location_id', __(':location is inactive and cannot receive stock.', ['location' => $to->name]));
            }

            $out = $loads->firstWhere('pith_location_id', $fromLocationId);
            $in = $loads->first(fn (PithInventory $load) => $load->id !== $out?->id);

            $out?->update(['date' => $date, 'taken_cft_calculated' => $cft]);

            if ($destinationChanged) {
                $in?->delete();
                PithInventory::create([
                    'pith_location_id' => $to->id,
                    'pith_transfer_id' => $transfer->id,
                    'date' => $date,
                    'cft_offloaded' => $cft,
                ]);
            } else {
                $in?->update(['date' => $date, 'cft_offloaded' => $cft]);
            }

            $transfer->update([
                'to_pith_location_id' => $to->id,
                'date' => $date,
                'cft' => $cft,
                'note' => $note,
            ]);

            return $transfer->setRelation('fromLocation', $from)->setRelation('toLocation', $to);
        });
    }

    /**
     * Reverses a transfer by removing it and both its ledger rows. Refused once either
     * location has closed the batch holding its row — that stock is already accounted for.
     */
    public function delete(PithTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $loads = $transfer->loads()->lockForUpdate()->get();

            if ($loads->contains(fn (PithInventory $load) => (int) $load->is_archived === 1)) {
                $this->fail('transfer', __('This transfer belongs to a closed batch and can\'t be deleted.'));
            }

            $transfer->loads()->delete();
            $transfer->delete();
        });
    }

    /**
     * A transfer of this team, or null.
     */
    public function findForTeam(int $teamId, int|string $transferId): ?PithTransfer
    {
        return PithTransfer::whereKey($transferId)
            ->whereHas('fromLocation', fn ($query) => $query->where('team_id', $teamId))
            ->with(['fromLocation', 'toLocation'])
            ->first();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
