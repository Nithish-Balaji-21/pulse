<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PithLocationGroup extends Model
{
    protected $table = 'pulse_pith_location_groups';

    protected $fillable = [
        'team_id',
        'name',
        'display_order',
        'is_active',
    ];

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function pithLocations(): HasMany
    {
        return $this->hasMany(PithLocation::class, 'group_id');
    }
}
