<?php

use App\Support\Notifications\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Write out what each superadmin hears from each factory, rather than leaning on
 * the "superadmins get everything" fallback in NotificationPreferenceService.
 *
 * The primary superadmin does want everything on both channels, so their rows
 * only make the existing behaviour explicit. The second one wants every push
 * alert but, by email, the monthly roll-up alone.
 *
 * Rows cover every type including GENERAL, which the preference screen does not
 * expose: leaving it out would let a GENERAL notification fall through to the
 * fallback and reach an account that has opted out of everything else.
 *
 * Matched on email rather than id so this is a no-op on a database where the
 * accounts do not exist.
 */
return new class extends Migration
{
    /**
     * Email address => the types that account may be emailed about, or null for
     * every type.
     */
    private const EMAIL_TYPES = [
        'jagadeesan.balakrishnan@gmail.com' => null,
        'jagadeshjk7@gmail.com' => [NotificationType::PRODUCTION_SUMMARY_MONTHLY],
    ];

    public function up(): void
    {
        $teamIds = DB::table('teams')->pluck('id');

        if ($teamIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (self::EMAIL_TYPES as $email => $emailTypes) {
            $userId = DB::table('users')->where('email', $email)->value('id');

            if (! $userId) {
                continue;
            }

            foreach ($teamIds as $teamId) {
                foreach (NotificationType::keys() as $type) {
                    $rows[] = [
                        'user_id' => $userId,
                        'team_id' => $teamId,
                        'notification_type' => $type,
                        'is_email_enabled' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_EMAIL)
                            && ($emailTypes === null || in_array($type, $emailTypes, true)),
                        'is_push_enabled' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_PUSH),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pulse_user_notification_preferences')->upsert(
                $chunk,
                ['user_id', 'team_id', 'notification_type'],
                ['is_email_enabled', 'is_push_enabled', 'updated_at']
            );
        }
    }

    /**
     * Dropping the rows returns both accounts to the superadmin fallback, which
     * is everything on both channels.
     */
    public function down(): void
    {
        $userIds = DB::table('users')
            ->whereIn('email', array_keys(self::EMAIL_TYPES))
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('pulse_user_notification_preferences')
            ->whereIn('user_id', $userIds)
            ->delete();
    }
};
