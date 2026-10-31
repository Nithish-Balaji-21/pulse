<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give every superadmin a full set of preference rows for a newly created
 * factory, carried over from the factories they already follow.
 *
 * Superadmins are subscribed to every factory without being a member of one, so
 * nothing else writes rows for them. Without this a superadmin who has narrowed
 * what they hear about would silently go back to receiving everything the next
 * time a factory is onboarded.
 */
class SeedSuperadminNotificationPreferences
{
    public function handle(object $event): void
    {
        if (! isset($event->team) || ! $event->team->id) {
            return;
        }

        $teamId = (int) $event->team->id;

        $superadmins = User::query()
            ->where('is_superadmin', 1)
            ->where('is_active', 1)
            ->get();

        if ($superadmins->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($superadmins as $superadmin) {
            $carried = $this->settingsFromOtherFactories((int) $superadmin->id, $teamId);

            foreach (NotificationType::keys() as $type) {
                // No history means the standing superadmin default: everything.
                $existing = $carried[$type] ?? ['email' => true, 'push' => true];

                $rows[] = [
                    'user_id' => $superadmin->id,
                    'team_id' => $teamId,
                    'notification_type' => $type,
                    'is_email_enabled' => $existing['email'] && NotificationType::supportsChannel($type, NotificationType::CHANNEL_EMAIL),
                    'is_push_enabled' => $existing['push'] && NotificationType::supportsChannel($type, NotificationType::CHANNEL_PUSH),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('pulse_user_notification_preferences')->insertOrIgnore($rows);

        Log::info("Seeded superadmin notification preferences for team {$teamId}.");
    }

    /**
     * What this superadmin most recently chose for each type elsewhere.
     *
     * @return array<string, array{email: bool, push: bool}>
     */
    private function settingsFromOtherFactories(int $userId, int $teamId): array
    {
        $settings = [];

        $rows = DB::table('pulse_user_notification_preferences')
            ->where('user_id', $userId)
            ->where('team_id', '!=', $teamId)
            ->orderBy('updated_at')
            ->get();

        // Ordered oldest first, so the newest row for a type overwrites the rest.
        foreach ($rows as $row) {
            $settings[$row->notification_type] = [
                'email' => (bool) $row->is_email_enabled,
                'push' => (bool) $row->is_push_enabled,
            ];
        }

        return $settings;
    }
}
