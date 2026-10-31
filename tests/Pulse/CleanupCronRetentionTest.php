<?php

use App\Models\ImpersonationLog;
use App\Models\Pulse\WeatherDataDaily;
use App\Models\Pulse\WeatherDataHourly;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Pulse\Support\TeamFactory;

/**
 * `data:cleanup` retention for impersonation logs (all environments) and for
 * device/weather data under the demo environment, which churns through
 * generated data far faster than production and so gets pruned much sooner.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Cleanup Retention Factory '.uniqid());
});

it('deletes impersonation logs older than two months and keeps newer ones', function () {
    $impersonator = User::factory()->create(['current_team_id' => $this->teamId]);
    $target = User::factory()->create(['current_team_id' => $this->teamId]);

    $stale = ImpersonationLog::create([
        'session_uuid' => (string) Str::uuid(),
        'impersonator_id' => $impersonator->id,
        'impersonated_id' => $target->id,
        'team_id' => $this->teamId,
        'action' => 'start',
    ]);
    $stale->forceFill(['created_at' => Carbon::now()->subMonths(3)])->save();

    $recent = ImpersonationLog::create([
        'session_uuid' => (string) Str::uuid(),
        'impersonator_id' => $impersonator->id,
        'impersonated_id' => $target->id,
        'team_id' => $this->teamId,
        'action' => 'start',
    ]);
    $recent->forceFill(['created_at' => Carbon::now()->subDays(20)])->save();

    $this->artisan('data:cleanup')->assertSuccessful();

    expect(ImpersonationLog::whereKey($stale->id)->exists())->toBeFalse()
        ->and(ImpersonationLog::whereKey($recent->id)->exists())->toBeTrue();
});

it('prunes weather data much faster in the demo environment', function () {
    app()->instance('env', 'demo');

    $dailyReading = [
        'temperature' => 28.0,
        'humidity' => 60.0,
        'rain_intensity' => 0.0,
        'precipitation_probability' => 0.0,
        'windspeed' => 5.0,
        'wind_direction' => 90.0,
        'uv_index' => 4.0,
        'dew_point' => 20.0,
    ];

    WeatherDataDaily::create([
        'team_id' => $this->teamId,
        'date' => Carbon::now()->subDays(10)->toDateString(),
        ...$dailyReading,
    ])->forceFill(['created_at' => Carbon::now()->subDays(10)])->save();

    $recentDaily = WeatherDataDaily::create([
        'team_id' => $this->teamId,
        'date' => Carbon::now()->subDays(1)->toDateString(),
        ...$dailyReading,
    ]);
    $recentDaily->forceFill(['created_at' => Carbon::now()->subDays(1)])->save();

    WeatherDataHourly::create([
        'team_id' => $this->teamId,
        'date' => Carbon::now()->toDateString(),
        'hour' => 1,
        ...$dailyReading,
    ])->forceFill(['created_at' => Carbon::now()->subHours(6)])->save();

    $recentHourly = WeatherDataHourly::create([
        'team_id' => $this->teamId,
        'date' => Carbon::now()->toDateString(),
        'hour' => 23,
        ...$dailyReading,
    ]);
    $recentHourly->forceFill(['created_at' => Carbon::now()->subHours(1)])->save();

    $this->artisan('data:cleanup')->assertSuccessful();

    expect(WeatherDataDaily::where('team_id', $this->teamId)->count())->toBe(1)
        ->and(WeatherDataDaily::whereKey($recentDaily->id)->exists())->toBeTrue()
        ->and(WeatherDataHourly::where('team_id', $this->teamId)->count())->toBe(1)
        ->and(WeatherDataHourly::whereKey($recentHourly->id)->exists())->toBeTrue();
});

it('prunes device data six months back in the demo environment', function () {
    app()->instance('env', 'demo');

    $device = random_int(100000, 999999);

    $stale = DB::table('pulse_device_data')->insertGetId([
        'device_id' => $device,
        'datetime' => Carbon::now()->subMonths(8)->toDateTimeString(),
        'created_at' => Carbon::now()->subMonths(8)->toDateTimeString(),
    ]);

    $recent = DB::table('pulse_device_data')->insertGetId([
        'device_id' => $device,
        'datetime' => Carbon::now()->subMonths(3)->toDateTimeString(),
        'created_at' => Carbon::now()->subMonths(3)->toDateTimeString(),
    ]);

    $this->artisan('data:cleanup')->assertSuccessful();

    expect(DB::table('pulse_device_data')->where('id', $stale)->exists())->toBeFalse()
        ->and(DB::table('pulse_device_data')->where('id', $recent)->exists())->toBeTrue();
});
