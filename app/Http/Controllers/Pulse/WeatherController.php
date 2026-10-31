<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Pulse\CoirDryingAdvisor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherController extends Controller
{
    /** @var array<int, string> Static map sizes callers may request; first entry is the default. */
    private const MAP_SIZES = ['600x300', '600x600', '400x400'];

    public function index(Request $request)
    {
        $team = $this->activeTeam();
        $siteMapUrl = $team?->coordinatePair() && $this->staticMapsConfigured()
            ? route('weather.map', ['team' => $team->id])
            : null;
        $dailyWeather = $this->fetchDailyWeatherEvents($request);
        $hourlyWeather = $this->fetchHourlyWeatherEvents($request);
        $dailyDrying = $this->fetchDailyDryingIntervals();
        $lastUpdatedAt = $this->weatherLastUpdatedAt();
        $nextUpdateAt = $this->nextWeatherUpdateAt();

        if ($request->wantsJson()) {
            return response()->json([
                'hourlyWeather' => $hourlyWeather,
                'dailyWeather' => $dailyWeather,
                'dailyDrying' => $dailyDrying,
                'lastUpdatedAt' => $lastUpdatedAt?->toIso8601String(),
                'nextUpdateAt' => $nextUpdateAt->toIso8601String(),
            ]);
        }

        return view('pulse::weather.weather', array_merge(
            compact('siteMapUrl', 'dailyWeather', 'hourlyWeather', 'dailyDrying', 'lastUpdatedAt', 'nextUpdateAt'),
            ['teamId' => $team->id]
        ));
    }

    /**
     * Streams the factory's location as a Google Static Map, proxied server-side
     * so the API key is never exposed to the browser or the mobile app. Serves
     * both `weather.map` (web) and `api.weather.map` (mobile).
     */
    public function map(Request $request): Response
    {
        $team = $this->activeTeam();
        $coordinates = $team?->coordinatePair();

        abort_if($coordinates === null, 404, 'This factory has no location coordinates configured.');
        abort_unless($this->staticMapsConfigured(), 503, 'Map service is not configured.');

        $centre = $coordinates['lat'].','.$coordinates['lng'];
        $zoom = (int) $request->integer('zoom', 13);
        $zoom = max(1, min(20, $zoom));
        $size = in_array($request->query('size'), self::MAP_SIZES, true)
            ? $request->query('size')
            : self::MAP_SIZES[0];

        $cacheKey = "weather-map:{$team->id}:{$centre}:{$zoom}:{$size}";
        $image = Cache::get($cacheKey);

        if ($image === null) {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/staticmap', [
                'center' => $centre,
                'zoom' => $zoom,
                'size' => $size,
                'scale' => 2,
                'maptype' => 'roadmap',
                'markers' => 'color:red|label:A|'.$centre,
                'key' => config('app.google.api_key'),
            ]);

            if ($response->failed()) {
                Log::warning('Static map request failed', [
                    'team_id' => $team->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                abort(502, 'Could not load the location map.');
            }

            $image = $response->body();
            Cache::put($cacheKey, $image, now()->addDays(30));
        }

        return response($image, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Whether a real Google key is present. The shipped `.env` examples carry an
     * `xxxx...` placeholder, which Google rejects with an "invalid API key" image.
     */
    private function staticMapsConfigured(): bool
    {
        $apiKey = (string) config('app.google.api_key');

        return filled($apiKey) && ! str_starts_with($apiKey, 'xxx');
    }

    /**
     * The team the request is running in, as resolved by the active-team
     * middleware, falling back to the user's own current team.
     */
    private function activeTeam(): ?Team
    {
        return app()->has('current.team') ? app('current.team') : Auth::user()?->currentTeam;
    }

    /**
     * Coir drying recommendations per day-part window (morning/midday/afternoon)
     * for the 5-day forecast range, computed from the hourly data.
     *
     * @return array<string, array<string, array{level: string, label: string, advice: string, score: int, window: string}>>
     */
    private function fetchDailyDryingIntervals(): array
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $startDate = Carbon::now()->startOfDay();
        $endDate = $startDate->copy()->addDays(4);

        $hourlyRows = DB::table('pulse_weather_data_hourly')
            ->select('date', 'hour', 'temperature', 'humidity', 'rain_intensity', 'precipitation_probability', 'uv_index', 'windspeed')
            ->where('team_id', $currentTeamId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereBetween('hour', [6, 17])
            ->orderBy('date')
            ->orderBy('hour')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->date)->format('Y-m-d'));

        $dailyDrying = [];
        foreach ($hourlyRows as $date => $hours) {
            foreach (CoirDryingAdvisor::DAY_PARTS as $partKey => $part) {
                $windowHours = $hours->filter(
                    fn ($row) => (int) $row->hour >= $part['from'] && (int) $row->hour < $part['to']
                );
                $recommendation = CoirDryingAdvisor::forWindow($windowHours);

                if ($recommendation !== null) {
                    $dailyDrying[$date][$partKey] = array_merge($recommendation, ['window' => $part['label']]);
                }
            }
        }

        return $dailyDrying;
    }

    private function weatherLastUpdatedAt(): ?Carbon
    {
        $lastUpdatedAt = DB::table('pulse_weather_data_hourly')
            ->where('team_id', Auth::user()->currentTeam->id)
            ->max('updated_at');

        return $lastUpdatedAt ? Carbon::parse($lastUpdatedAt) : null;
    }

    /**
     * Next run of weather:populate_hourly, scheduled at minute 30 of hours 6-18
     * (see routes/console.php).
     */
    private function nextWeatherUpdateAt(): Carbon
    {
        $next = now()->second(0)->minute(30);

        if (now()->minute >= 30) {
            $next->addHour();
        }

        if ($next->hour < 6) {
            $next->setTime(6, 30);
        } elseif ($next->hour > 18) {
            $next->addDay()->setTime(6, 30);
        }

        return $next;
    }

    public function fetchHourlyWeatherEvents(Request $request)
    {
        $date = $request->query('date', Carbon::now()->format('Y-m-d'));
        $currentTeamId = Auth::user()->currentTeam->id;

        // Fetch weather data along with classifications
        $weatherData = DB::table('pulse_weather_data_hourly')
            ->select(
                'pulse_weather_data_hourly.id',
                'pulse_weather_data_hourly.date',
                'pulse_weather_data_hourly.hour',
                'pulse_weather_data_hourly.temperature',
                'pulse_weather_data_hourly.humidity',
                'pulse_weather_data_hourly.rain_intensity',
                'pulse_weather_data_hourly.precipitation_probability',
                'pulse_weather_data_hourly.windspeed',
                'pulse_weather_data_hourly.uv_index',
                'pulse_weather_data_hourly.wind_direction',
                'pulse_weather_data_hourly.dew_point',
                DB::raw("CASE
                    WHEN pulse_weather_data_hourly.hour >= 6 AND pulse_weather_data_hourly.hour < 18 THEN
                        CASE
                            WHEN pulse_weather_data_hourly.rain_intensity >= 0.6 THEN 'rainy'
                            WHEN pulse_weather_data_hourly.uv_index < 3 THEN 'cloudy'
                            WHEN pulse_weather_data_hourly.uv_index BETWEEN 3 AND 5 THEN 'partly-cloudy'
                            WHEN pulse_weather_data_hourly.uv_index >= 6 THEN 'sunny'
                        END
                    ELSE
                        CASE
                            WHEN pulse_weather_data_hourly.rain_intensity >= 0.6 THEN 'rainy-night'
                            WHEN pulse_weather_data_hourly.rain_intensity >= 0.1 THEN 'cloudy-night'
                            WHEN pulse_weather_data_hourly.humidity >= 80 THEN 'foggy'
                            ELSE 'clear-night'
                        END
                    END AS forecast"),
                DB::raw("
                    CASE
                        WHEN pulse_weather_data_hourly.uv_index = 0 THEN '0%'
                        WHEN pulse_weather_data_hourly.uv_index = 1 THEN '10%'
                        WHEN pulse_weather_data_hourly.uv_index = 2 THEN '20%'
                        WHEN pulse_weather_data_hourly.uv_index = 3 THEN '30%'
                        WHEN pulse_weather_data_hourly.uv_index = 4 THEN '50%'
                        WHEN pulse_weather_data_hourly.uv_index = 5 THEN '70%'
                        WHEN pulse_weather_data_hourly.uv_index = 6 THEN '80%'
                        WHEN pulse_weather_data_hourly.uv_index = 7 THEN '90%'
                    ELSE '100%'
                    END AS sunlight_classification"),
                DB::raw(
                    "CASE
                        WHEN pulse_weather_data_hourly.rain_intensity BETWEEN 0.1 AND 0.5 THEN 'Drizzle'
                        WHEN pulse_weather_data_hourly.rain_intensity BETWEEN 0.6 AND 2.5 THEN 'Light Rain'
                        WHEN pulse_weather_data_hourly.rain_intensity >= 2.6 THEN 'Heavy Rain'
                    END AS rain_classification"
                ),
                DB::raw(
                    "CASE
                        WHEN pulse_weather_data_hourly.windspeed BETWEEN 0 AND 7 THEN 'Calm'
                        WHEN pulse_weather_data_hourly.windspeed BETWEEN 8 AND 15 THEN 'Light'
                        WHEN pulse_weather_data_hourly.windspeed BETWEEN 16 AND 32 THEN 'Breezy'
                        WHEN pulse_weather_data_hourly.windspeed BETWEEN 33 AND 49 THEN 'Strong'
                        WHEN pulse_weather_data_hourly.windspeed >= 50 THEN 'Storm'
                    END AS wind_classification"
                )
            )
            ->where('team_id', $currentTeamId)
            ->where('pulse_weather_data_hourly.date', $date)
            ->where(function ($query) use ($date) {
                // If it's today, only include hours >= current hour
                if ($date == Carbon::today()->toDateString()) {
                    $query->where('hour', '>=', date('G'));
                } else {
                    $query->where('hour', '>=', 0); // Include all hours for past or future dates
                }
            })
            ->orderBy('pulse_weather_data_hourly.date')
            ->orderBy('pulse_weather_data_hourly.hour')
            ->get();

        // Map the response
        $hourlyWeather = $weatherData->map(function ($weather) {
            return [
                'id' => $weather->id,
                'start' => Carbon::parse($weather->date)->format('Y-m-d'),
                'end' => Carbon::parse($weather->date)->addDay()->format('Y-m-d'),
                'hour' => $weather->hour,
                'title' => $weather->forecast,
                'extendedProps' => [
                    'uv_index' => $weather->uv_index,
                    'temperature' => round($weather->temperature, 1),
                    'rain_intensity' => $weather->rain_intensity,
                    'precipitation_probability' => $weather->precipitation_probability,
                    'humidity' => $weather->humidity,
                    'dew_point' => round($weather->dew_point, 1),
                    'windspeed' => round($weather->windspeed, 1),
                    'wind_direction' => $weather->wind_direction,
                    'sunlight_classification' => $weather->sunlight_classification ?? null,
                    'rain_classification' => $weather->rain_classification ?? null,
                    'wind_classification' => $weather->wind_classification ?? null,
                    'overall_classification' => $weather->forecast,
                    'drying' => CoirDryingAdvisor::forHour($weather, (int) $weather->hour),
                ],
            ];
        });

        return $hourlyWeather;
    }

    public function fetchDailyWeatherEvents(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $startDate = Carbon::now()->startOfDay();
        $endDate = $startDate->copy()->addDays(4);

        // Fetch data for the calculated range
        $weatherData = DB::table('pulse_weather_data_daily')
            ->select(
                'pulse_weather_data_daily.id',
                'pulse_weather_data_daily.date',
                'pulse_weather_data_daily.temperature',
                'pulse_weather_data_daily.humidity',
                'pulse_weather_data_daily.rain_intensity',
                'pulse_weather_data_daily.precipitation_probability',
                'pulse_weather_data_daily.dew_point',
                'pulse_weather_data_daily.windspeed',
                'pulse_weather_data_daily.wind_direction',
                'pulse_weather_data_daily.uv_index',
                DB::raw(
                    "CASE
                        WHEN pulse_weather_data_daily.rain_intensity >= 0.6 THEN 'rainy'
                        WHEN pulse_weather_data_daily.uv_index < 3 THEN 'cloudy'
                        WHEN pulse_weather_data_daily.uv_index BETWEEN 3 AND 5 THEN 'partly-cloudy'
                        WHEN pulse_weather_data_daily.uv_index >= 6 THEN 'sunny'
                    ELSE 'clear'
                    END AS forecast"
                ),
                DB::raw(
                    "CASE
                        WHEN pulse_weather_data_daily.uv_index = 0 THEN '0%'
                        WHEN pulse_weather_data_daily.uv_index = 1 THEN '10%'
                        WHEN pulse_weather_data_daily.uv_index = 2 THEN '20%'
                        WHEN pulse_weather_data_daily.uv_index = 3 THEN '30%'
                        WHEN pulse_weather_data_daily.uv_index = 4 THEN '50%'
                        WHEN pulse_weather_data_daily.uv_index = 5 THEN '70%'
                        WHEN pulse_weather_data_daily.uv_index = 6 THEN '80%'
                        WHEN pulse_weather_data_daily.uv_index = 7 THEN '90%'
                    ELSE '100%'
                    END AS sunlight_classification"
                ),
                DB::raw(
                    "CASE
                        WHEN pulse_weather_data_daily.rain_intensity BETWEEN 0.1 AND 0.5 THEN 'Drizzle'
                        WHEN pulse_weather_data_daily.rain_intensity BETWEEN 0.6 AND 2.5 THEN 'Light Rain'
                        WHEN pulse_weather_data_daily.rain_intensity >= 2.6 THEN 'Heavy Rain'
                    END AS rain_classification"
                ),
                DB::raw(
                    "CASE
                        WHEN pulse_weather_data_daily.windspeed BETWEEN 0 AND 7 THEN 'Calm'
                        WHEN pulse_weather_data_daily.windspeed BETWEEN 8 AND 15 THEN 'Light'
                        WHEN pulse_weather_data_daily.windspeed BETWEEN 16 AND 32 THEN 'Breezy'
                        WHEN pulse_weather_data_daily.windspeed BETWEEN 33 AND 49 THEN 'Strong'
                        WHEN pulse_weather_data_daily.windspeed >= 50 THEN 'Storm'
                    END AS wind_classification"
                )
            )
            ->where('team_id', $currentTeamId)
            ->whereBetween('pulse_weather_data_daily.date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('pulse_weather_data_daily.date')
            ->get();

        // Map the response
        $dailyWeather = $weatherData->map(function ($weather) {
            return [
                'id' => $weather->id,
                'date' => Carbon::parse($weather->date)->format('Y-m-d'),
                'title' => $weather->forecast,
                'extendedProps' => [
                    'uv_index' => $weather->uv_index,
                    'temperature' => round($weather->temperature, 1),
                    'rain_intensity' => $weather->rain_intensity,
                    'humidity' => $weather->humidity,
                    'dew_point' => round($weather->dew_point, 1),
                    'precipitation_probability' => $weather->precipitation_probability,
                    'windspeed' => round($weather->windspeed, 1),
                    'sunlight_classification' => $weather->sunlight_classification ?? null,
                    'rain_classification' => $weather->rain_classification ?? null,
                    'wind_direction' => $weather->wind_direction,
                    'wind_classification' => $weather->wind_classification ?? null,
                    'overall_classification' => $weather->forecast, // Only the image name
                    'drying' => CoirDryingAdvisor::forHour($weather, 12),
                ],
            ];
        });

        if ($request->wantsJson()) {
            $factory = DB::table('teams')
                ->where('id', $currentTeamId)
                ->select('name')
                ->first();

            $coordinates = $this->activeTeam()?->coordinatePair();

            return response()->json([
                'factory_name' => $factory?->name,
                'latitude' => $coordinates['lat'] ?? null,
                'longitude' => $coordinates['lng'] ?? null,
                'map_url' => $coordinates && $this->staticMapsConfigured()
                    ? route('api.weather.map')
                    : null,
                'data' => $dailyWeather,
            ]);
        }

        return $dailyWeather;
    }
}
