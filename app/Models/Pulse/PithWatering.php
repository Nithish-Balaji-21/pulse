<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One watering cycle at a pith location. A NULL pith_stock_depletion_id means the watering
 * belongs to the location's current batch; closing the stock archives it onto the depletion.
 */
class PithWatering extends Model
{
    protected $table = 'pulse_pith_waterings';

    protected $fillable = [
        'pith_location_id',
        'pith_stock_depletion_id',
        'date',
        'litres',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'litres' => 'integer',
        ];
    }

    public function pithLocation(): BelongsTo
    {
        return $this->belongsTo(PithLocation::class);
    }

    public function stockDepletion(): BelongsTo
    {
        return $this->belongsTo(PithStockDepletion::class, 'pith_stock_depletion_id');
    }

    public function scopeCurrentBatch(Builder $query): Builder
    {
        return $query->whereNull('pith_stock_depletion_id');
    }

    public function isArchived(): bool
    {
        return $this->pith_stock_depletion_id !== null;
    }

    /**
     * @return array{id: int, date: string, litres: int}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->format('Y-m-d'),
            'litres' => $this->litres,
        ];
    }
}
