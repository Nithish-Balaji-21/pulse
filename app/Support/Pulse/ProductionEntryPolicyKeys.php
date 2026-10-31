<?php

namespace App\Support\Pulse;

use Illuminate\Support\Facades\DB;

class ProductionEntryPolicyKeys
{
    /** @var list<int> */
    private const DAY_OPTIONS = [1, 2, 3, 5, 7, 10, 14, 21, 28, 30];

    public const BLOCKS = 'PITH_BLOCKS_ENTRY_POLICY';

    public const LOADS = 'PITH_PRODUCTION_ENTRY_POLICY';

    public const BALES = 'FIBRE_BALES_ENTRY_POLICY';

    /** @var array<string, array{text: string, default_days: int}> */
    public const DEFINITIONS = [
        self::BLOCKS => [
            'text' => 'How many days should Pith Blocks production editing be allowed for?',
            'default_days' => 10,
        ],
        self::LOADS => [
            'text' => 'How many days should Pith Production editing be allowed for?',
            'default_days' => 10,
        ],
        self::BALES => [
            'text' => 'How many days should Fibre Bales production editing be allowed for?',
            'default_days' => 10,
        ],
    ];

    public static function all(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isPolicyKey(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::DEFINITIONS);
    }

    public static function dayOptions(): string
    {
        return implode(',', self::DAY_OPTIONS);
    }

    /** @return list<string> */
    public static function dayOptionsList(): array
    {
        return array_map('strval', self::DAY_OPTIONS);
    }

    public static function normalizeDay(int|string|null $days): int
    {
        $day = (int) $days;
        if ($day <= 0) {
            return self::DEFINITIONS[self::BLOCKS]['default_days'];
        }

        $closest = self::DAY_OPTIONS[0];
        foreach (self::DAY_OPTIONS as $option) {
            if (abs($option - $day) < abs($closest - $day)) {
                $closest = $option;
            }
        }

        return $closest;
    }

    public static function formatDaysLabel(?string $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /** @return list<array{team_id: int, module: string, key: string, text: string, value: string, options: string, value_remark: null, created_at: mixed, updated_at: mixed}> */
    public static function rowsForTeam(int $teamId, bool $hasPith, bool $hasBales, $now): array
    {
        if (! $hasPith) {
            return [];
        }

        $rows = [
            self::row($teamId, self::BLOCKS, $now),
            self::row($teamId, self::LOADS, $now),
        ];

        if ($hasBales) {
            $rows[] = self::row($teamId, self::BALES, $now);
        }

        return $rows;
    }

    /** @return array{team_id: int, module: string, key: string, text: string, value: string, options: string, value_remark: null, created_at: mixed, updated_at: mixed} */
    public static function row(int $teamId, string $key, $now): array
    {
        $definition = self::DEFINITIONS[$key];

        return [
            'team_id' => $teamId,
            'module' => 'pith',
            'key' => $key,
            'text' => $definition['text'],
            'value' => (string) $definition['default_days'],
            'options' => self::dayOptions(),
            'value_remark' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array{has_pith: bool, has_bales: bool} */
    public static function teamModuleFlags(?string $modulesJson): array
    {
        $modules = json_decode($modulesJson ?? '', true) ?? [];
        $pith = $modules['pulse']['modules']['pith'] ?? [];

        return [
            'has_pith' => (bool) ($pith['is_active'] ?? false),
            'has_bales' => (bool) ($pith['submodules']['bales'] ?? false),
        ];
    }

    public static function purgeMisplacedSettings(): void
    {
        $knownTeamIds = [];

        DB::table('team_pulse')
            ->select('team_id', 'modules')
            ->orderBy('team_id')
            ->get()
            ->each(function ($pulse) use (&$knownTeamIds) {
                $teamId = (int) $pulse->team_id;
                $knownTeamIds[] = $teamId;

                $flags = self::teamModuleFlags($pulse->modules);

                if (! $flags['has_pith']) {
                    DB::table('pulse_settings')
                        ->where('team_id', $teamId)
                        ->whereIn('key', self::all())
                        ->delete();

                    return;
                }

                if (! $flags['has_bales']) {
                    DB::table('pulse_settings')
                        ->where('team_id', $teamId)
                        ->where('key', self::BALES)
                        ->delete();
                }
            });

        DB::table('pulse_settings')
            ->whereIn('key', self::all())
            ->when($knownTeamIds !== [], fn ($query) => $query->whereNotIn('team_id', $knownTeamIds))
            ->delete();
    }

    public static function seedAllPithTeams($now): void
    {
        self::purgeMisplacedSettings();

        DB::table('team_pulse')
            ->select('team_id', 'modules')
            ->orderBy('team_id')
            ->get()
            ->each(function ($pulse) use ($now) {
                $flags = self::teamModuleFlags($pulse->modules);

                foreach (self::rowsForTeam((int) $pulse->team_id, $flags['has_pith'], $flags['has_bales'], $now) as $row) {
                    DB::table('pulse_settings')->updateOrInsert(
                        [
                            'team_id' => $row['team_id'],
                            'module' => $row['module'],
                            'key' => $row['key'],
                        ],
                        $row
                    );
                }
            });
    }

    public static function normalizeExistingSettings(): void
    {
        self::purgeMisplacedSettings();

        DB::table('pulse_settings')
            ->whereIn('key', self::all())
            ->select('id', 'key', 'value')
            ->orderBy('id')
            ->get()
            ->each(function ($setting) {
                $key = (string) $setting->key;

                DB::table('pulse_settings')
                    ->where('id', $setting->id)
                    ->update([
                        'text' => self::DEFINITIONS[$key]['text'],
                        'value' => (string) self::normalizeDay($setting->value),
                        'options' => self::dayOptions(),
                        'value_remark' => null,
                        'updated_at' => now(),
                    ]);
            });
    }
}
