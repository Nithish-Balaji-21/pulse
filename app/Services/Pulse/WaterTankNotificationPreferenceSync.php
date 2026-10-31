<?php

namespace App\Services\Pulse;

use App\Models\PulseUserNotificationPreference;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;

class WaterTankNotificationPreferenceSync
{
    private const TYPES = [
        NotificationType::WATER_TANK_LOW,
        NotificationType::WATER_TANK_OVERFLOW,
    ];

    public static function teamHasWaterTanks(int $teamId): bool
    {
        return DB::table('pulse_master_water_tanks as mwt')
            ->join('pulse_devices as d', 'd.device_id', '=', 'mwt.device_id')
            ->where('d.team_id', $teamId)
            ->exists();
    }

    public static function ensureForTeam(int $teamId): void
    {
        if (! self::teamHasWaterTanks($teamId)) {
            return;
        }

        $teamUsers = DB::table('team_user')->where('team_id', $teamId)->get();
        $now = now();

        foreach ($teamUsers as $tu) {
            foreach (self::TYPES as $type) {
                $enabled = NotificationType::isDefaultForRole($type, $tu->role);

                PulseUserNotificationPreference::firstOrCreate(
                    [
                        'user_id' => $tu->user_id,
                        'team_id' => $teamId,
                        'notification_type' => $type,
                    ],
                    [
                        'is_email_enabled' => $enabled,
                        'is_push_enabled' => $enabled,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public static function removeForTeamIfNoTanks(int $teamId): void
    {
        if (self::teamHasWaterTanks($teamId)) {
            return;
        }

        PulseUserNotificationPreference::where('team_id', $teamId)
            ->whereIn('notification_type', self::TYPES)
            ->delete();
    }
}
