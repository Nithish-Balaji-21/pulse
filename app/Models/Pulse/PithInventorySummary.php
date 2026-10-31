<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PithInventorySummary extends Model
{
    protected $table = 'pulse_pith_inventory_summary';

    protected $fillable = [
        'summary_date',
        'team_id',
        'location_id',
        'inward',
        'outward',
        'in_stock',
    ];

    public $timestamps = true;

    public function pithLocation(): BelongsTo
    {
        return $this->belongsTo(PithLocation::class, 'location_id');
    }
}
