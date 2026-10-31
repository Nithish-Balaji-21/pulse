<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetPurchase extends Model
{
    protected $table = 'pulse_asset_purchases';

    protected $primaryKey = 'asset_purchase_id';

    public $timestamps = false;

    protected $fillable = [
        'asset_id',
        'purchase_type',
        'purchase_date',
        'from_company',
        'purchased_quantity',
        'price',
        'remarks',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
