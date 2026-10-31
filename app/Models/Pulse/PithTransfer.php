<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pith moved between two locations of a team. Its two ledger rows (outward at the source,
 * inward at the destination) are the pulse_pith_inventory rows carrying its id.
 */
class PithTransfer extends Model
{
    protected $table = 'pulse_pith_transfers';

    protected $fillable = [
        'from_pith_location_id',
        'to_pith_location_id',
        'date',
        'cft',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cft' => 'float',
        ];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(PithLocation::class, 'from_pith_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(PithLocation::class, 'to_pith_location_id');
    }

    public function loads(): HasMany
    {
        return $this->hasMany(PithInventory::class, 'pith_transfer_id');
    }

    /**
     * @return array{id: int, date: string, cft: float, note: string|null, from_location_id: int, from_location_name: string|null, to_location_id: int, to_location_name: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->format('Y-m-d'),
            'cft' => round($this->cft, 2),
            'note' => $this->note,
            'from_location_id' => $this->from_pith_location_id,
            'from_location_name' => $this->fromLocation?->name,
            'to_location_id' => $this->to_pith_location_id,
            'to_location_name' => $this->toLocation?->name,
        ];
    }
}
