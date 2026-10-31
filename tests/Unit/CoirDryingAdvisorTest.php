<?php

use App\Services\Pulse\CoirDryingAdvisor;

function weatherHour(array $overrides = []): object
{
    return (object) array_merge([
        'temperature' => 34,
        'humidity' => 45,
        'rain_intensity' => 0,
        'precipitation_probability' => 5,
        'uv_index' => 7,
        'windspeed' => 12,
        'hour' => 12,
    ], $overrides);
}

test('strong sun, low humidity midday hour is excellent', function () {
    $recommendation = CoirDryingAdvisor::forHour(weatherHour(), 12);

    expect($recommendation['level'])->toBe(CoirDryingAdvisor::LEVEL_EXCELLENT)
        ->and($recommendation['score'])->toBeGreaterThanOrEqual(75);
});

test('rain intensity or high rain probability means avoid', function (array $overrides) {
    $recommendation = CoirDryingAdvisor::forHour(weatherHour($overrides), 12);

    expect($recommendation['level'])->toBe(CoirDryingAdvisor::LEVEL_AVOID);
})->with([
    'wet hour' => [['rain_intensity' => 1.2]],
    'high rain probability' => [['precipitation_probability' => 70]],
]);

test('weak sun and humid air is poor', function () {
    $recommendation = CoirDryingAdvisor::forHour(
        weatherHour(['uv_index' => 1, 'humidity' => 88, 'temperature' => 23, 'windspeed' => 4]),
        9
    );

    expect($recommendation['level'])->toBe(CoirDryingAdvisor::LEVEL_POOR);
});

test('strong wind caps an otherwise excellent hour at fair', function () {
    $recommendation = CoirDryingAdvisor::forHour(weatherHour(['windspeed' => 40]), 13);

    expect($recommendation['level'])->toBe(CoirDryingAdvisor::LEVEL_FAIR)
        ->and($recommendation['score'])->toBeLessThanOrEqual(45)
        ->and($recommendation['advice'])->toContain('wind');
});

test('hours outside daylight are night regardless of conditions', function (int $hour) {
    $recommendation = CoirDryingAdvisor::forHour(weatherHour(['hour' => $hour]), $hour);

    expect($recommendation['level'])->toBe(CoirDryingAdvisor::LEVEL_NIGHT);
})->with([5, 18, 22]);

test('a single wet hour makes the whole window avoid', function () {
    $window = collect([
        weatherHour(['hour' => 14]),
        weatherHour(['hour' => 15, 'rain_intensity' => 2.0]),
        weatherHour(['hour' => 16]),
    ]);

    expect(CoirDryingAdvisor::forWindow($window)['level'])->toBe(CoirDryingAdvisor::LEVEL_AVOID);
});

test('window averages the hourly scores', function () {
    $window = collect([
        weatherHour(['hour' => 6, 'uv_index' => 2, 'humidity' => 85, 'temperature' => 24, 'windspeed' => 3]),
        weatherHour(['hour' => 8]),
    ]);

    $recommendation = CoirDryingAdvisor::forWindow($window);

    expect($recommendation['level'])->toBe(CoirDryingAdvisor::LEVEL_GOOD)
        ->and($recommendation['score'])->toBeGreaterThan(35)
        ->and($recommendation['score'])->toBeLessThan(75);
});

test('window with only night hours has no recommendation', function () {
    $window = collect([
        weatherHour(['hour' => 19]),
        weatherHour(['hour' => 20]),
    ]);

    expect(CoirDryingAdvisor::forWindow($window))->toBeNull();
});
