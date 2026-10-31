<?php

namespace App\Support\Pulse;

use App\Models\Team;

/**
 * Reads team_pulse.modules.pulse.modules for role-matrix visibility.
 */
final class TeamModuleContext
{
    public function __construct(
        public readonly bool $pithActive,
        public readonly bool $pithBales,
        public readonly bool $pithBlocks,
        public readonly bool $fibreActive,
        public readonly bool $fibreWeight,
        public readonly bool $growbagsActive,
        public readonly bool $attendanceActive,
        public readonly bool $husksStockActive,
        public readonly bool $waterMeterActive,
        public readonly bool $geofencingActive,
    ) {}

    public static function fromTeam(Team $team): self
    {
        $modules = $team->pulse?->modules['pulse']['modules'] ?? [];

        return new self(
            pithActive: (bool) ($modules['pith']['is_active'] ?? false),
            pithBales: (bool) ($modules['pith']['submodules']['bales'] ?? false),
            pithBlocks: (bool) ($modules['pith']['submodules']['blocks'] ?? false),
            fibreActive: (bool) ($modules['fibre']['is_active'] ?? false),
            fibreWeight: (bool) ($modules['fibre']['submodules']['weight'] ?? false),
            growbagsActive: (bool) ($modules['growbags']['is_active'] ?? false),
            attendanceActive: (bool) ($modules['attendance']['is_active'] ?? true),
            husksStockActive: (bool) ($modules['husks_stock']['is_active'] ?? false),
            waterMeterActive: (bool) ($modules['water_meter']['is_active'] ?? false),
            geofencingActive: (bool) ($modules['geofencing']['is_active'] ?? false),
        );
    }

    public static function fromTeamId(int $teamId): self
    {
        $team = Team::with('pulse')->findOrFail($teamId);

        return self::fromTeam($team);
    }

    /**
     * @param  list<list<string>>  $visibleWhen  OR of AND-groups
     */
    public function matchesVisibleWhen(array $visibleWhen): bool
    {
        if ($visibleWhen === []) {
            return true;
        }

        foreach ($visibleWhen as $group) {
            if ($this->matchesGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $group
     */
    private function matchesGroup(array $group): bool
    {
        foreach ($group as $condition) {
            if (! $this->checkCondition($condition)) {
                return false;
            }
        }

        return true;
    }

    private function checkCondition(string $condition): bool
    {
        return match ($condition) {
            'always' => true,
            'pith.active' => $this->pithActive,
            'pith.submodules.bales' => $this->pithActive && $this->pithBales,
            'pith.submodules.blocks' => $this->pithActive && $this->pithBlocks,
            'fibre.active' => $this->fibreActive,
            'fibre.submodules.weight' => $this->fibreActive && $this->fibreWeight,
            'growbags.active' => $this->growbagsActive,
            'attendance.active' => $this->attendanceActive,
            'husks_stock.active' => $this->husksStockActive,
            'water_meter.active' => $this->waterMeterActive,
            'geofencing.active' => $this->geofencingActive,
            'not.fibre_only' => ! $this->fibreActive || $this->pithActive,
            // Device-only: fibre and/or growbags without pith. Mirrors
            // Team::getIsDeviceOnlyFactoryAttribute.
            'not.device_only' => (! $this->fibreActive && ! $this->growbagsActive) || $this->pithActive,
            default => false,
        };
    }

    public function isModuleVisible(string $moduleKey): bool
    {
        $def = config("pulse_role_permissions.modules.{$moduleKey}");

        if (! is_array($def)) {
            return false;
        }

        return $this->matchesVisibleWhen($def['visible_when'] ?? [['always']]);
    }
}
