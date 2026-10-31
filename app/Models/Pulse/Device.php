<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use HasFactory;

    protected $table = 'pulse_devices';

    protected $primaryKey = 'device_id';

    public $incrementing = false;

    protected $fillable = [
        'team_id',
        'device_id',
        'module',
        'eb_units',
        'status',
        'off_th',
        'idle_th',
        'efficient_th',
        'duration_device_msg',
        'duration_device_missing',
    ];

    public $timestamps = true;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function pithProductions()
    {
        return $this->hasMany(PithProduction::class);
    }

    public function miniBaleProductions()
    {
        return $this->hasMany(MiniBaleProduction::class);
    }

    public function deviceDataStates()
    {
        return $this->hasMany(DeviceDataState::class);
    }
}
