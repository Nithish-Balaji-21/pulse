<?php

namespace App\Models\Pulse;

use Illuminate\Database\Eloquent\Model;

class WeatherRule extends Model
{
    protected $table = 'pulse_weather_rules';

    protected $fillable = [
        'condition', 'is_daytime',
        'min_temperature', 'max_temperature',
        'min_humidity', 'max_humidity',
        'min_precipitation', 'max_precipitation',
        'min_windspeed', 'max_windspeed',
        'min_uv_index', 'max_uv_index',
    ];
}
