<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterWaterMeter extends Model
{
    use HasFactory;

    protected $table = 'pulse_master_water_meters';

    protected $fillable = [
        'device_id',
        'field_id',
        'consumption_name',
    ];

    public $timestamps = false;

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
