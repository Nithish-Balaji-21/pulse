<?php

namespace App\Services\Pulse;

use App\Models\Pulse\Shift;
use App\Models\Settings;
use App\Support\Pulse\IotLiveDay;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use Carbon\Carbon;

class ProductionEntryPolicy
{
    /** @var array<string, self> */
    private static array $cache = [];

    public function __construct(
        private readonly int $teamId,
        private readonly string $settingKey,
        private readonly int $windowDays,
        private readonly string $liveShiftDay,
    ) {}

    public static function forTeam(int $teamId, string $settingKey): self
    {
        $cacheKey = $teamId.':'.$settingKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $definition = ProductionEntryPolicyKeys::DEFINITIONS[$settingKey];
        $setting = Settings::query()
            ->where('team_id', $teamId)
            ->where('key', $settingKey)
            ->first();

        $windowDays = ProductionEntryPolicyKeys::normalizeDay(
            $setting?->value ?? $definition['default_days']
        );

        $liveShiftDay = IotLiveDay::forAnchor(Shift::anchorForTeam($teamId));

        return self::$cache[$cacheKey] = new self(
            $teamId,
            $settingKey,
            $windowDays,
            $liveShiftDay,
        );
    }

    public static function forgetCacheForTeam(int $teamId): void
    {
        foreach (array_keys(self::$cache) as $key) {
            if (str_starts_with($key, $teamId.':')) {
                unset(self::$cache[$key]);
            }
        }
    }

    public function teamId(): int
    {
        return $this->teamId;
    }

    public function settingKey(): string
    {
        return $this->settingKey;
    }

    public function windowDays(): int
    {
        return $this->windowDays;
    }

    public function liveShiftDay(): string
    {
        return $this->liveShiftDay;
    }

    public function minShiftDate(): string
    {
        return Carbon::parse($this->liveShiftDay)
            ->subDays($this->windowDays)
            ->toDateString();
    }

    public function maxCreateShiftDate(): string
    {
        return Carbon::parse($this->liveShiftDay)
            ->subDay()
            ->toDateString();
    }

    public function canCreate(string $shiftDate): bool
    {
        return $this->isWithinInclusiveRange($shiftDate, $this->minShiftDate(), $this->maxCreateShiftDate());
    }

    public function canEdit(string $shiftDate): bool
    {
        return $this->isWithinInclusiveRange($shiftDate, $this->minShiftDate(), $this->liveShiftDay);
    }

    public function canDelete(string $shiftDate): bool
    {
        return $this->isWithinInclusiveRange($shiftDate, $this->minShiftDate(), $this->liveShiftDay);
    }

    public function policyMessage(?string $customActionText = null): string
    {
        if ($customActionText !== null) {
            return 'Record can be '.$customActionText.' up to '.$this->windowDays.' days only.';
        }

        return 'Record can be edited and deleted up to '.$this->windowDays.' days only.';
    }

    /** @return array<string, mixed> */
    public function toApiMetadata(): array
    {
        return [
            'window_days' => $this->windowDays(),
            'min_editable_date' => $this->minShiftDate(),
            'max_create_date' => $this->maxCreateShiftDate(),
            'live_shift_day' => $this->liveShiftDay(),
        ];
    }

    private function isWithinInclusiveRange(string $shiftDate, string $minDate, string $maxDate): bool
    {
        $date = Carbon::parse($shiftDate)->startOfDay();
        $min = Carbon::parse($minDate)->startOfDay();
        $max = Carbon::parse($maxDate)->startOfDay();

        return $date->gte($min) && $date->lte($max);
    }
}
