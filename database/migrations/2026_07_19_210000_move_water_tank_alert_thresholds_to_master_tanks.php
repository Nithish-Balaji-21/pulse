<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WATER_ALERT_TYPES = [
        'WATER_TANK_LOW',
        'WATER_TANK_OVERFLOW',
    ];

    private const WATER_ALERT_SETTING_KEYS = [
        'ALERT_THRESHOLD_LOW_WATER_LEVEL',
        'ALERT_THRESHOLD_OVERFLOW_WATER_LEVEL',
    ];

    public function up(): void
    {
        Schema::table('pulse_master_water_tanks', function (Blueprint $table) {
            $table->decimal('low_alert_percent', 5, 2)->default(5)->after('capacity_litres');
            $table->decimal('overflow_alert_percent', 5, 2)->default(98)->after('low_alert_percent');
        });

        DB::table('pulse_settings')
            ->whereIn('key', self::WATER_ALERT_SETTING_KEYS)
            ->delete();

        $teamIdsWithTanks = $this->teamIdsWithWaterTanks();

        DB::table('pulse_user_notification_preferences')
            ->whereIn('notification_type', self::WATER_ALERT_TYPES)
            ->whereNotIn('team_id', $teamIdsWithTanks ?: [0])
            ->delete();

        if (empty($teamIdsWithTanks)) {
            return;
        }

        $teamUsers = DB::table('team_user')
            ->whereIn('team_id', $teamIdsWithTanks)
            ->get();

        $now = now();
        $rows = [];

        foreach ($teamUsers as $tu) {
            $isAdmin = ($tu->role === 'admin');
            foreach (self::WATER_ALERT_TYPES as $type) {
                $rows[] = [
                    'user_id' => $tu->user_id,
                    'team_id' => $tu->team_id,
                    'notification_type' => $type,
                    'is_email_enabled' => $isAdmin,
                    'is_push_enabled' => $isAdmin,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pulse_user_notification_preferences')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        Schema::table('pulse_master_water_tanks', function (Blueprint $table) {
            $table->dropColumn(['low_alert_percent', 'overflow_alert_percent']);
        });
    }

    /**
     * @return list<int>
     */
    private function teamIdsWithWaterTanks(): array
    {
        return DB::table('pulse_master_water_tanks as mwt')
            ->join('pulse_devices as d', 'd.device_id', '=', 'mwt.device_id')
            ->distinct()
            ->pluck('d.team_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
};
