<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;

class DeviceData extends Model
{
    // The table associated with the model
    protected $table = 'pulse_device_data';

    // The attributes that are mass assignable.
    protected $fillable = [
        'device_id',
        'date',
        'time',
        'd4', 'd5', 'd6', // Voltage data
        'd7',              // Current data
    ];

    // Disable the default timestamps if not using created_at and updated_at
    public $timestamps = false;
}
