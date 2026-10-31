<?php

use App\Support\Notifications\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Production summaries used to be a single, all-or-nothing preference. Split it
 * into the four periods so a user can keep the monthly report while muting the
 * daily one.
 *
 * Preference rows were only ever written by the seeding listener (there was no
 * screen to change them), so rows that still match the old seed default are
 * dropped: the role-based defaults in NotificationType now cover them, and
 * removing them lets owners and supervisors pick up their new defaults. Rows
 * that differ from the old default were changed by hand and are preserved.
 */
return new class extends Migration
{
    private const PERIOD_TYPES = [
        NotificationType::PRODUCTION_SUMMARY_DAILY,
        NotificationType::PRODUCTION_SUMMARY_WEEKLY,
        NotificationType::PRODUCTION_SUMMARY_MONTHLY,
        NotificationType::PRODUCTION_SUMMARY_YEARLY,
    ];

    public function up(): void
    {
        $this->splitProductionSummaryRows();
        $this->dropRowsMatchingOldSeedDefault();
    }

    public function down(): void
    {
        $daily = DB::table('pulse_user_notification_preferences')
            ->where('notification_type', NotificationType::PRODUCTION_SUMMARY_DAILY)
            ->get();

        $now = now();
        $rows = $daily->map(fn ($row) => [
            'user_id' => $row->user_id,
            'team_id' => $row->team_id,
            'notification_type' => NotificationType::LEGACY_PRODUCTION_SUMMARY,
            'is_email_enabled' => $row->is_email_enabled,
            'is_push_enabled' => $row->is_push_enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pulse_user_notification_preferences')->insertOrIgnore($chunk);
        }

        DB::table('pulse_user_notification_preferences')
            ->whereIn('notification_type', self::PERIOD_TYPES)
            ->delete();
    }

    private function splitProductionSummaryRows(): void
    {
        $legacy = DB::table('pulse_user_notification_preferences')
            ->where('notification_type', NotificationType::LEGACY_PRODUCTION_SUMMARY)
            ->get();

        if ($legacy->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($legacy as $preference) {
            foreach (self::PERIOD_TYPES as $type) {
                $rows[] = [
                    'user_id' => $preference->user_id,
                    'team_id' => $preference->team_id,
                    'notification_type' => $type,
                    'is_email_enabled' => $preference->is_email_enabled,
                    'is_push_enabled' => $preference->is_push_enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pulse_user_notification_preferences')->insertOrIgnore($chunk);
        }

        DB::table('pulse_user_notification_preferences')
            ->where('notification_type', NotificationType::LEGACY_PRODUCTION_SUMMARY)
            ->delete();
    }

    /**
     * The old listener seeded every row with `enabled = (role === 'admin')`.
     * Anything still in that state is an untouched seed and can go.
     */
    private function dropRowsMatchingOldSeedDefault(): void
    {
        DB::table('pulse_user_notification_preferences as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('team_user as tu', function ($join) {
                $join->on('tu.user_id', '=', 'p.user_id')
                    ->on('tu.team_id', '=', 'p.team_id');
            })
            ->where(function ($query) {
                // Superadmins now default to receiving every factory's
                // notifications, so their seeded rows must not opt them out.
                $query->where('u.is_superadmin', 1)
                    ->orWhereRaw("p.is_email_enabled = p.is_push_enabled and p.is_email_enabled = (case when tu.role = 'admin' then 1 else 0 end)");
            })
            ->delete();
    }
};
