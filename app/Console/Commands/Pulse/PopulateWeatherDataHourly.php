<?php

namespace App\Console\Commands\Pulse;

use App\Models\Pulse\WeatherDataHourly;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PopulateWeatherDataHourly extends Command
{
    protected $signature = 'weather:populate_hourly';

    protected $description = 'Fetch and store weather data for all teams from Tomorrow.io';

    public function handle()
    {
        $this->info('Fetching weather data...');
        $teams = $this->getTeamsWithCoordinates();
        $groupedCoordinates = $this->groupTeamsByCoordinates($teams);

        foreach ($groupedCoordinates as $coordinates => $teamIds) {
            $this->info("Coordinates: {$coordinates} for Teams: ".implode(', ', $teamIds));
            $data = $this->fetchWeatherData($coordinates);

            if ($data) {
                foreach ($teamIds as $teamId) {
                    $this->storeWeatherData($teamId, $data);
                }
            } else {
                $this->error("Failed to fetch data for teams with coordinates: {$coordinates}.");
            }

            // Sleep to avoid hitting Tomorrow.io rate limits (e.g. 3 requests/sec)
            sleep(1);
        }
    }

    private function getTeamsWithCoordinates()
    {
        return Team::whereHas('pulse', fn ($q) => $q->whereNotNull('coordinates'))->with('pulse')->get();
    }

    private function groupTeamsByCoordinates($teams)
    {
        $grouped = [];
        foreach ($teams as $team) {
            $coordinates = $team->pulse->coordinates ?? null;
            $grouped[$coordinates][] = $team->id;
        }

        return $grouped;
    }

    private function fetchWeatherData($location)
    {
        $url = 'https://api.tomorrow.io/v4/timelines';
        $apiKey = config('app.tomorrow_io.api_key');

        $params = [
            'location' => $location,
            'fields' => 'temperature,humidity,rainIntensity,windSpeed,uvIndex,precipitationProbability,dewPoint,windDirection',
            'timesteps' => '1h',
            'startTime' => now()->toIso8601String(),
            'endTime' => now()->addDays(5)->toIso8601String(),
            'apikey' => $apiKey,
        ];

        // Make the API request
        $response = Http::get($url, $params);

        if (! $response->successful()) {
            $this->error("API request failed. Status: {$response->status()} - {$response->body()}");
            Log::error('Tomorrow.io hourly weather request failed.', [
                'location' => $location,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $intervals = $response->json('data.timelines.0.intervals');

        if (! is_array($intervals)) {
            $this->error("API returned an unexpected payload. Status: {$response->status()} - {$response->body()}");
            Log::error('Tomorrow.io hourly weather response missing data.timelines.0.intervals.', [
                'location' => $location,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $intervals;
    }

    private function storeWeatherData($teamId, $data)
    {
        foreach ($data as $interval) {
            $startTimeUTC = Carbon::parse($interval['startTime']); // Parse UTC time
            $startTimeLocal = $startTimeUTC->copy()->setTimezone(config('app.timezone', 'Asia/Kolkata'));
            $values = $interval['values'];

            WeatherDataHourly::updateOrCreate(
                [
                    'team_id' => $teamId,
                    'date' => $startTimeLocal->toDateString(),
                    'hour' => $startTimeLocal->hour,
                ],
                [
                    'temperature' => $values['temperature'] ?? 0,
                    'humidity' => $values['humidity'] ?? 0,
                    'rain_intensity' => $values['rainIntensity'] ?? 0,
                    'precipitation_probability' => $values['precipitationProbability'] ?? 0,
                    'windspeed' => isset($values['windSpeed']) ? ($values['windSpeed'] * 3.6) : 0,
                    'uv_index' => $values['uvIndex'] ?? 0,
                    'dew_point' => $values['dewPoint'] ?? 0,
                    'wind_direction' => $values['windDirection'] ?? 0,
                    'updated_at' => now(),
                ]
            );
        }

        $this->info("Data stored for Team ID: {$teamId}.");
    }
}
