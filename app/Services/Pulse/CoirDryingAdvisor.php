<?php

namespace App\Services\Pulse;

use Illuminate\Support\Collection;

class CoirDryingAdvisor
{
    public const LEVEL_EXCELLENT = 'excellent';

    public const LEVEL_GOOD = 'good';

    public const LEVEL_FAIR = 'fair';

    public const LEVEL_POOR = 'poor';

    public const LEVEL_AVOID = 'avoid';

    public const LEVEL_NIGHT = 'night';

    /**
     * Day-part windows used for the multi-day drying recommendations.
     * Hours are inclusive of `from`, exclusive of `to`.
     *
     * @var array<string, array{label: string, from: int, to: int}>
     */
    public const DAY_PARTS = [
        'morning' => ['label' => 'Morning', 'from' => 6, 'to' => 10],
        'midday' => ['label' => 'Midday', 'from' => 10, 'to' => 14],
        'afternoon' => ['label' => 'Afternoon', 'from' => 14, 'to' => 18],
    ];

    /**
     * Recommend drying action for a single hour of weather data.
     *
     * @param  object|array<string, mixed>  $weather  Row with temperature, humidity, rain_intensity,
     *                                                precipitation_probability, uv_index and windspeed
     * @return array{level: string, label: string, advice: string, score: int}
     */
    public static function forHour(object|array $weather, int $hour): array
    {
        $w = (object) $weather;

        if ($hour < 6 || $hour >= 18) {
            return self::describe(self::LEVEL_NIGHT, 0);
        }

        if ((float) $w->rain_intensity >= 0.6 || (float) $w->precipitation_probability >= 60) {
            return self::describe(self::LEVEL_AVOID, 0);
        }

        $score = self::score($w);

        if ((float) $w->windspeed >= 33) {
            return self::windyFair($score);
        }

        return self::describe(self::levelForScore($score), $score);
    }

    /**
     * Recommend drying action for a window of hourly rows (e.g. morning 6-10).
     * Any rain-risk hour makes the whole window a no-go, since material left
     * out to dry cannot dodge a single wet hour.
     *
     * @param  Collection<int, object>  $hours
     * @return array{level: string, label: string, advice: string, score: int}|null
     */
    public static function forWindow(Collection $hours): ?array
    {
        $daylight = $hours
            ->filter(fn ($row) => (int) $row->hour >= 6 && (int) $row->hour < 18)
            ->values();

        if ($daylight->isEmpty()) {
            return null;
        }

        $wet = $daylight->first(
            fn ($row) => (float) $row->rain_intensity >= 0.6 || (float) $row->precipitation_probability >= 60
        );
        if ($wet !== null) {
            return self::describe(self::LEVEL_AVOID, 0);
        }

        $average = (int) round($daylight->avg(fn ($row) => self::forHour($row, (int) $row->hour)['score']));

        if ($daylight->contains(fn ($row) => (float) $row->windspeed >= 33)) {
            return self::windyFair($average);
        }

        return self::describe(self::levelForScore($average), $average);
    }

    /**
     * Score a daylight hour 0-100 on how well coir pith/fibre will sun-dry:
     * strong UV, low humidity, heat and a drying breeze all help.
     */
    private static function score(object $w): int
    {
        $uv = (float) $w->uv_index;
        $humidity = (float) $w->humidity;
        $temperature = (float) $w->temperature;
        $windspeed = (float) $w->windspeed;

        $score = match (true) {
            $uv >= 6 => 40,
            $uv >= 3 => 25,
            $uv >= 1 => 10,
            default => 0,
        };

        $score += match (true) {
            $humidity < 50 => 25,
            $humidity <= 65 => 18,
            $humidity <= 80 => 10,
            default => 0,
        };

        $score += match (true) {
            $temperature >= 32 => 20,
            $temperature >= 25 => 15,
            $temperature >= 20 => 8,
            default => 3,
        };

        $score += match (true) {
            $windspeed >= 50 => 0,
            $windspeed >= 33 => 4,
            $windspeed >= 8 => 15,
            default => 8,
        };

        if ((float) $w->precipitation_probability >= 30) {
            $score -= 15;
        }

        return max(0, min(100, $score));
    }

    private static function levelForScore(int $score): string
    {
        return match (true) {
            $score >= 75 => self::LEVEL_EXCELLENT,
            $score >= 55 => self::LEVEL_GOOD,
            $score >= 35 => self::LEVEL_FAIR,
            default => self::LEVEL_POOR,
        };
    }

    /**
     * @return array{level: string, label: string, advice: string, score: int}
     */
    private static function windyFair(int $score): array
    {
        return [
            'level' => self::LEVEL_FAIR,
            'label' => __('Fair drying'),
            'advice' => __('Strong wind — secure loose fibre and pith.'),
            'score' => min($score, 45),
        ];
    }

    /**
     * @return array{level: string, label: string, advice: string, score: int}
     */
    private static function describe(string $level, int $score): array
    {
        [$label, $advice] = match ($level) {
            self::LEVEL_EXCELLENT => [__('Excellent drying'), __('Ideal sun — spread in thin layers for fast drying.')],
            self::LEVEL_GOOD => [__('Good drying'), __('Good conditions — turn material for even drying.')],
            self::LEVEL_FAIR => [__('Fair drying'), __('Slow drying — spread thin, allow extra time.')],
            self::LEVEL_POOR => [__('Poor drying'), __('Weak sun or humid air — drying will stall.')],
            self::LEVEL_AVOID => [__('Avoid drying'), __('Rain risk — not suitable for drying.')],
            default => [__('No drying'), __('After sunset — no sun drying possible.')],
        };

        return ['level' => $level, 'label' => $label, 'advice' => $advice, 'score' => $score];
    }
}
