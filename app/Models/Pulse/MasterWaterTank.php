<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterWaterTank extends Model
{
    use HasFactory;

    protected $table = 'pulse_master_water_tanks';

    protected $fillable = [
        'device_id',
        'field_id',
        'tank_name',
        'capacity_litres',
        'low_alert_percent',
        'overflow_alert_percent',
        'tank_type',
        'cyl_height',
        'cyl_diameter',
    ];

    protected $casts = [
        'capacity_litres' => 'float',
        'low_alert_percent' => 'float',
        'overflow_alert_percent' => 'float',
        'cyl_height' => 'float',
        'cyl_diameter' => 'float',
    ];

    public $timestamps = false;

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
