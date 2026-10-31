<?php

namespace App\Listeners;

use App\Models\PulseUserNotificationPreference;
use Illuminate\Support\Facades\Log;

/**
 * Drops a user's notification preferences for a factory they have left.
 *
 * Without this the rows outlive the membership, and the preference screens
 * count them as configured users for a factory nobody is a member of.
 */
class RemoveNotificationPreferences
{
    public function handle(object $event): void
    {
        if (! isset($event->team) || ! isset($event->user)) {
            return;
        }

        // Superadmins are notified about every factory whether or not they are
        // a member, so their mute settings must survive leaving a team.
        if ($event->user->is_superadmin) {
            return;
        }

        $deleted = PulseUserNotificationPreference::where('user_id', $event->user->id)
            ->where('team_id', $event->team->id)
            ->delete();

        if ($deleted > 0) {
            Log::info("Removed {$deleted} notification preferences for user {$event->user->id} leaving team {$event->team->id}.");
        }
    }
}
