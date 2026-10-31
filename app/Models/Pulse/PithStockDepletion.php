<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PithStockDepletion extends Model
{
    protected $table = 'pulse_pith_stock_depletions';

    protected $fillable = [
        'pith_location_id',
        'depletion_date',
        'first_pith_inventory_id',
        'last_pith_inventory_id',
        'total_cft_offloaded',
        'total_cft_taken_calculated',
    ];

    public $timestamps = true;

    public function pithLocation(): BelongsTo
    {
        return $this->belongsTo(PithLocation::class);
    }

    public function waterings(): HasMany
    {
        return $this->hasMany(PithWatering::class);
    }
}
