<?php

namespace App\Listeners;

use App\Models\PulseUserNotificationPreference;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateDefaultNotificationPreferences
{
    public function __construct(private NotificationPreferenceService $preferences) {}

    /**
     * Seed a new team member's preference rows so the settings screen opens on
     * their role defaults instead of an empty slate.
     */
    public function handle(object $event): void
    {
        if (! isset($event->team) || ! isset($event->user)) {
            return;
        }

        $team = $event->team;
        $user = $event->user;

        // Fallback to fetch from pivot if the event does not pass $role explicitly
        $role = $event->role ?? null;
        if (! $role) {
            $pivot = DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)->first();
            $role = $pivot ? $pivot->role : null;
        }

        if (! $role) {
            return;
        }

        $isSuperadmin = (bool) $user->is_superadmin;

        foreach ($this->preferences->availableTypesForTeam((int) $team->id) as $type) {
            // No row at all for a type this role is closed to, so nothing is
            // left behind to resurrect if the type later opens up.
            if (! $this->preferences->canReceiveType($type, $role, $isSuperadmin)) {
                continue;
            }

            $enabled = $this->preferences->defaultFor($type, $role, $isSuperadmin);

            PulseUserNotificationPreference::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'team_id' => $team->id,
                    'notification_type' => $type,
                ],
                [
                    'is_email_enabled' => $enabled && NotificationType::supportsChannel($type, NotificationType::CHANNEL_EMAIL),
                    'is_push_enabled' => $enabled && NotificationType::supportsChannel($type, NotificationType::CHANNEL_PUSH),
                ]
            );
        }

        Log::info("Created notification preferences for user {$user->id} in team {$team->id}.");
    }
}
