<?php

namespace App\Console\Commands\Pulse;

use App\Models\Team;
use App\Services\NotificationSenderService;
use App\Services\Pulse\WaterTankLevelCalculator;
use App\Support\Notifications\NotificationType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorWaterTankLevels extends Command
{
    private const DEFAULT_LOW_THRESHOLD = 5.0;

    private const DEFAULT_OVERFLOW_THRESHOLD = 98.0;

    private const STATE_LOW = 'low';

    private const STATE_NORMAL = 'normal';

    private const STATE_OVERFLOW = 'overflow';

    protected $signature = 'pulse:monitor-water-tanks';

    protected $description = 'Monitors configured water tanks and sends low / overflow water-level alerts.';

    public function handle(
        NotificationSenderService $notifier,
        WaterTankLevelCalculator $calculator
    ): int {
        $teams = Team::with([
            'devices' => fn ($query) => $query->where('status', 'active'),
        ])->activePulse()->get();

        foreach ($teams as $team) {
            if ($team->devices->isEmpty()) {
                continue;
            }

            $lowMessages = [];
            $overflowMessages = [];

            $tanks = DB::table('pulse_master_water_tanks')
                ->whereIn('device_id', $team->devices->pluck('device_id'))
                ->get();

            if ($tanks->isEmpty()) {
                continue;
            }

            foreach ($tanks as $tank) {
                $reading = DB::table('pulse_device_data_water_tank_levels')
                    ->where('device_id', $tank->device_id)
                    ->where('field_id', $tank->field_id)
                    ->orderByDesc('level_datetime')
                    ->first(['available_height']);

                if (! $reading) {
                    continue;
                }

                $percentage = $calculator->percentage($tank, (float) $reading->available_height);

                if ($percentage === null) {
                    Log::warning("Skipping water tank {$tank->id}: capacity or dimensions are invalid.");

                    continue;
                }

                $lowThreshold = $this->normalizeThreshold(
                    $tank->low_alert_percent ?? null,
                    self::DEFAULT_LOW_THRESHOLD
                );
                $overflowThreshold = $this->normalizeThreshold(
                    $tank->overflow_alert_percent ?? null,
                    self::DEFAULT_OVERFLOW_THRESHOLD
                );

                $currentState = $this->classifyState($percentage, $lowThreshold, $overflowThreshold);
                $cacheKey = "water_tank_state_{$tank->id}";

                if (! Cache::has($cacheKey)) {
                    Cache::put($cacheKey, $currentState);
                    Log::info("Set water tank {$tank->id} state '{$currentState}' for team {$team->id}.");

                    continue;
                }

                $lastState = Cache::get($cacheKey);
                if ($currentState === $lastState) {
                    continue;
                }

                Cache::put($cacheKey, $currentState);

                $tankName = $tank->tank_name ?: 'Water Tank';

                if ($currentState === self::STATE_LOW) {
                    $lowMessages[] = "{$tankName} water level is low";
                } elseif ($currentState === self::STATE_OVERFLOW) {
                    $overflowMessages[] = "{$tankName} is overflowing!";
                }
            }

            if (! empty($lowMessages)) {
                $notifier->notifyTeamUsers(
                    $team->id,
                    [],
                    "{$team->name} - Water Tank Alert",
                    $lowMessages,
                    now(),
                    null,
                    true,
                    NotificationType::WATER_TANK_LOW
                );
                Log::info("Sent WATER_TANK_LOW alert for team {$team->id}.");
            }

            if (! empty($overflowMessages)) {
                $notifier->notifyTeamUsers(
                    $team->id,
                    [],
                    "{$team->name} - Water Tank Alert",
                    $overflowMessages,
                    now(),
                    null,
                    true,
                    NotificationType::WATER_TANK_OVERFLOW
                );
                Log::info("Sent WATER_TANK_OVERFLOW alert for team {$team->id}.");
            }
        }

        return self::SUCCESS;
    }

    private function classifyState(float $percentage, float $lowThreshold, float $overflowThreshold): string
    {
        if ($percentage < $lowThreshold) {
            return self::STATE_LOW;
        }

        if ($percentage >= $overflowThreshold) {
            return self::STATE_OVERFLOW;
        }

        return self::STATE_NORMAL;
    }

    private function normalizeThreshold(mixed $value, float $default): float
    {
        $threshold = is_numeric($value) ? (float) $value : $default;

        return $threshold >= 1 && $threshold <= 100
            ? $threshold
            : $default;
    }
}
