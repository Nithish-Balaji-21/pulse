<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDataPithBlocks extends Model
{
    public $timestamps = true;

    protected $table = 'pulse_device_data_pith_blocks';

    protected $fillable = [
        'device_id',
        'block_date',
        'block_datetime',
        'blocks_counter',
        'blocks_produced',
        'shift_date',
        'eb_units',
        'eb_price',
    ];

    /**
     * Get the device that the pith  blocks belongs to.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
