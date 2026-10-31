<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetReturn extends Model
{
    protected $table = 'pulse_asset_returns';

    protected $primaryKey = 'return_id';

    public $timestamps = false;

    protected $fillable = [
        'outward_id',
        'returned_date',
        'returned_quantity',
        'remarks',
    ];

    public function outward(): BelongsTo
    {
        return $this->belongsTo(AssetOutward::class, 'outward_id');
    }
}
