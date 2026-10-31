<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetOutward extends Model
{
    protected $table = 'pulse_asset_outwards';

    protected $primaryKey = 'outward_id';

    public $timestamps = false;

    protected $fillable = [
        'asset_id',
        'outward_type',
        'outward_date',
        'to_company',
        'outward_quantity',
        'approved_by',
        'remarks',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(AssetReturn::class, 'outward_id');
    }
}
