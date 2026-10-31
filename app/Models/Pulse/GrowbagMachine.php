<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

class GrowbagMachine extends Model
{
    protected $table = 'pulse_master_growbag_machines';

    protected $fillable = [
        'team_id',
        'device_id',
        'field_id',
        'machine_name',
        'bags_per_punch',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'bags_per_punch' => 'integer',
    ];

    // Relationships
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }
}
