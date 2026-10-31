<?php

namespace Database\Seeders;

use App\Models\PulseUserNotificationPreference;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PulseUserNotificationPreferencesSeeder extends Seeder
{
    /**
     * Seed every team member's preference rows from their role defaults.
     *
     * Rows are only a convenience for the settings screens — NotificationType
     * resolves the same defaults when a row is missing.
     */
    public function run(): void
    {
        $preferences = app(NotificationPreferenceService::class);
        $teamUsers = DB::table('team_user')->get();
        $superadminIds = DB::table('users')->where('is_superadmin', 1)->pluck('id')->all();

        $insertData = [];
        $now = now();
        $typesByTeam = [];

        foreach ($teamUsers as $tu) {
            $teamId = (int) $tu->team_id;
            $isSuperadmin = in_array($tu->user_id, $superadminIds);

            $typesByTeam[$teamId] ??= $preferences->availableTypesForTeam($teamId);

            foreach ($typesByTeam[$teamId] as $type) {
                $enabled = $preferences->defaultFor($type, $tu->role, $isSuperadmin);

                $insertData[] = [
                    'user_id' => $tu->user_id,
                    'team_id' => $tu->team_id,
                    'notification_type' => $type,
                    'is_email_enabled' => $enabled && NotificationType::supportsChannel($type, NotificationType::CHANNEL_EMAIL),
                    'is_push_enabled' => $enabled && NotificationType::supportsChannel($type, NotificationType::CHANNEL_PUSH),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($insertData, 500) as $chunk) {
            PulseUserNotificationPreference::insertOrIgnore($chunk);
        }
    }
}
