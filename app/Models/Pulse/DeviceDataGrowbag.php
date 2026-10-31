<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDataGrowbag extends Model
{
    public $timestamps = true;

    protected $table = 'pulse_device_data_growbags';

    protected $fillable = [
        'device_id',
        'growbag_date',
        'growbag_datetime',
        'growbags_counter',
        'growbags_produced',
        'shift_id',
        'shift_name',
        'shift_date',
        'eb_units',
        'eb_price',
        'is_duplicate',
    ];

    protected $casts = [
        'is_duplicate' => 'boolean',
    ];

    /**
     * Get the device that the pith  blocks belongs to.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeForMachine($query, $machineName)
    {
        return $query->where('machine_name', $machineName);
    }

    public function scopeNotDuplicate($query)
    {
        return $query->where('is_duplicate', 0);
    }
}
