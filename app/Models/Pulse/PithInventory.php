<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PithInventory extends Model
{
    protected $table = 'pulse_pith_inventory';

    protected $fillable = [
        'pith_production_id',
        'pith_transfer_id',
        'pith_location_id',
        'date',
        'cft_offloaded',
        'taken_cft_calculated',
        'is_archived',
        'pith_stock_depletion_id',
    ];

    public $timestamps = true;

    public function pithLocation(): BelongsTo
    {
        return $this->belongsTo(PithLocation::class);
    }

    public function pithProduction(): BelongsTo
    {
        return $this->belongsTo(PithProduction::class);
    }

    /**
     * Set on the two rows a transfer writes; those change only through the transfer.
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(PithTransfer::class, 'pith_transfer_id');
    }

    /**
     * The closure that archived this row; null while it sits in the location's open batch.
     */
    public function depletion(): BelongsTo
    {
        return $this->belongsTo(PithStockDepletion::class, 'pith_stock_depletion_id');
    }
}
