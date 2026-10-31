<?php

namespace App\Models\Pulse;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceEvent extends Model
{
    public const TYPE_ENTER = 'ENTER';

    public const TYPE_EXIT = 'EXIT';

    protected $table = 'pulse_geofence_events';

    protected $fillable = [
        'user_id',
        'team_id',
        'geofence_location_id',
        'event_type',
        'latitude',
        'longitude',
        'recorded_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(GeofenceLocation::class, 'geofence_location_id');
    }
}
