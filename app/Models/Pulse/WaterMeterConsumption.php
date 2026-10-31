<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaterMeterConsumption extends Model
{
    use HasFactory;

    protected $table = 'pulse_summary_water_meter';

    protected $fillable = [
        'team_id',
        'device_id',
        'field_id',
        'date',
        'litres_consumed',
        'created_at',
        'updated_at',
    ];

    public $timestamps = true;

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
