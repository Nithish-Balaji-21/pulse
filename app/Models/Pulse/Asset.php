<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    protected $table = 'pulse_assets';

    public $timestamps = true;

    protected $fillable = [
        'team_id',
        'asset_category_id',
        'asset_name',
        'state',
        'remarks',
        'attachments',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assetCategory(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }
}
