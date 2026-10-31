<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

class WeatherDataHourly extends Model
{
    protected $table = 'pulse_weather_data_hourly';

    protected $fillable = [
        'team_id',
        'date',
        'hour',
        'temperature',
        'humidity',
        'rain_intensity',
        'precipitation_probability',
        'dew_point',
        'windspeed',
        'uv_index',
        'wind_direction',
        'dew_point',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
