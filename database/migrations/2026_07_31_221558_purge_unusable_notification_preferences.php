<?php

use App\Support\Notifications\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clears preference rows that can no longer do anything.
 *
 * Two kinds accumulated: rows belonging to a user who has since left the team
 * (nothing removed them until RemoveNotificationPreferences was added), and
 * rows for staff and readers, who are now excluded from notifications outright.
 *
 * Both are ignored at send time either way — this just stops them showing up as
 * configured users on the preference screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pulse_user_notification_preferences as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('team_user as tu', function ($join) {
                $join->on('tu.user_id', '=', 'p.user_id')
                    ->on('tu.team_id', '=', 'p.team_id');
            })
            // Superadmins keep their rows: those are per-factory mute settings
            // and do not depend on team membership.
            ->where('u.is_superadmin', 0)
            ->where(function ($query) {
                $query->whereNull('tu.role')
                    ->orWhereNotIn('tu.role', NotificationType::NOTIFIABLE_ROLES);
            })
            ->delete();
    }

    public function down(): void
    {
        // The rows carried no information the role defaults do not already
        // supply, so there is nothing meaningful to restore.
    }
};
