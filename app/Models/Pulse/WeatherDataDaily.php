<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

class WeatherDataDaily extends Model
{
    protected $table = 'pulse_weather_data_daily';

    protected $fillable = [
        'team_id',
        'date',
        'temperature',
        'humidity',
        'rain_intensity',
        'precipitation_probability',
        'dew_point',
        'windspeed',
        'uv_index',
        'wind_direction',
        'dew_point',
        'wind_direction',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
