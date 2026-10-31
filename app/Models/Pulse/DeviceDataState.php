<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDataState extends Model
{
    protected $table = 'pulse_summary_power_states';

    // Disable the default timestamps if not using created_at and updated_at
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'device_id',
        'state',
        'start_time',
        'end_time',
        'staff_id',
        'duration',
        'eb_units',
        'eb_price',
    ];

    /**
     * Get the device that the state belongs to.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
