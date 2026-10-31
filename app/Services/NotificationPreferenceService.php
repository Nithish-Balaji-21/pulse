<?php

namespace App\Services;

use App\Models\PulseUserNotificationPreference;
use App\Models\Team;
use App\Models\User;
use App\Services\Pulse\WaterTankNotificationPreferenceSync;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and persists per-user notification preferences.
 *
 * A stored row is always authoritative. When no row exists the answer falls
 * back to a default: superadmins receive everything from every factory, team
 * members receive whatever their role subscribes them to.
 */
class NotificationPreferenceService
{
    /**
     * Stored preferences for a team, keyed by user id then notification type.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $types
     * @return array<int, array<string, array{email: bool, push: bool}>>
     */
    public function storedFor(int $teamId, array $userIds, array $types = []): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('pulse_user_notification_preferences')
            ->where('team_id', $teamId)
            ->whereIn('user_id', $userIds)
            ->when(! empty($types), fn ($query) => $query->whereIn('notification_type', $types))
            ->get();

        $stored = [];

        foreach ($rows as $row) {
            $stored[(int) $row->user_id][$row->notification_type] = [
                'email' => (bool) $row->is_email_enabled,
                'push' => (bool) $row->is_push_enabled,
            ];
        }

        return $stored;
    }

    /**
     * Whether a user should receive $type on $channel for a team.
     *
     * @param  array<int, array<string, array{email: bool, push: bool}>>  $stored
     *                                                                             Pre-fetched rows from storedFor(); pass to avoid a query per user.
     */
    public function isEnabled(
        int $userId,
        string $type,
        string $channel,
        ?string $role,
        bool $isSuperadmin,
        array $stored = []
    ): bool {
        if (! NotificationType::supportsChannel($type, $channel)) {
            return false;
        }

        // Checked before the stored row: staff and readers are never notified,
        // and a role the type is closed to cannot subscribe itself to it, so a
        // row left behind by a role change cannot re-subscribe either of them.
        if (! $this->canReceiveType($type, $role, $isSuperadmin)) {
            return false;
        }

        $row = $stored[$userId][$type] ?? null;

        if ($row !== null) {
            return (bool) ($row[$channel] ?? false);
        }

        return $this->defaultFor($type, $role, $isSuperadmin);
    }

    /**
     * Whether this person is eligible for factory notifications at all.
     *
     * Superadmins always are. Team members must hold a notifiable role; anyone
     * else — staff, readers, and users who are no longer on the team — is out.
     */
    public function canReceiveAnything(?string $role, bool $isSuperadmin): bool
    {
        return $isSuperadmin || NotificationType::roleCanReceive($role);
    }

    /**
     * Whether this person is eligible for one particular type.
     *
     * Superadmins oversee everything and so are eligible for every type; a team
     * member additionally has to hold a role the type is open to.
     */
    public function canReceiveType(string $type, ?string $role, bool $isSuperadmin): bool
    {
        return $isSuperadmin || NotificationType::roleCanReceiveType($type, $role);
    }

    /**
     * The subscription a user gets before they customise anything.
     */
    public function defaultFor(string $type, ?string $role, bool $isSuperadmin): bool
    {
        if (! $this->canReceiveType($type, $role, $isSuperadmin)) {
            return false;
        }

        if ($isSuperadmin) {
            return true;
        }

        return NotificationType::isDefaultForRole($type, $role);
    }

    /**
     * Notification types that apply to a team, dropping those whose hardware is
     * not installed (e.g. water tank alerts for a factory without tanks).
     *
     * @return array<int, string>
     */
    public function availableTypesForTeam(int $teamId): array
    {
        $hasWaterTanks = WaterTankNotificationPreferenceSync::teamHasWaterTanks($teamId);

        return array_values(array_filter(
            NotificationType::configurableKeys(),
            function (string $type) use ($hasWaterTanks) {
                $requires = NotificationType::definition($type)['requires'];

                return $requires !== 'water_tanks' || $hasWaterTanks;
            }
        ));
    }

    /**
     * Preference matrix for one user in one team, grouped for display.
     *
     * @return array<int, array{group: string, types: array<int, array{
     *     type: string,
     *     label: string,
     *     description: string,
     *     email_supported: bool,
     *     push_supported: bool,
     *     email: bool,
     *     push: bool
     * }>}>
     */
    public function matrixFor(User $user, int $teamId): array
    {
        $role = $this->roleFor($user->id, $teamId);
        $isSuperadmin = (bool) $user->is_superadmin;

        // Nothing to configure for a role that is never notified.
        if (! $this->canReceiveAnything($role, $isSuperadmin)) {
            return [];
        }

        // A type the role is not open to is left off the screen entirely rather
        // than shown as an toggle that would never take effect.
        $types = array_values(array_filter(
            $this->availableTypesForTeam($teamId),
            fn (string $type) => $this->canReceiveType($type, $role, $isSuperadmin)
        ));

        $stored = $this->storedFor($teamId, [$user->id], $types);

        $grouped = [];

        foreach ($types as $type) {
            $definition = NotificationType::definition($type);
            $grouped[$definition['group']][] = [
                'type' => $type,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'email_supported' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_EMAIL),
                'push_supported' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_PUSH),
                'email' => $this->isEnabled($user->id, $type, NotificationType::CHANNEL_EMAIL, $role, $isSuperadmin, $stored),
                'push' => $this->isEnabled($user->id, $type, NotificationType::CHANNEL_PUSH, $role, $isSuperadmin, $stored),
            ];
        }

        $matrix = [];

        foreach (NotificationType::groups() as $group) {
            if (isset($grouped[$group])) {
                $matrix[] = ['group' => $group, 'types' => $grouped[$group]];
            }
        }

        return $matrix;
    }

    /**
     * Persist a single channel toggle.
     */
    public function set(User $user, int $teamId, string $type, string $channel, bool $enabled): void
    {
        if (! NotificationType::exists($type) || ! NotificationType::supportsChannel($type, $channel)) {
            return;
        }

        $role = $this->roleFor($user->id, $teamId);
        $isSuperadmin = (bool) $user->is_superadmin;

        if (! $this->canReceiveType($type, $role, $isSuperadmin)) {
            return;
        }

        $stored = $this->storedFor($teamId, [$user->id], [$type]);

        $current = [
            NotificationType::CHANNEL_EMAIL => $this->isEnabled($user->id, $type, NotificationType::CHANNEL_EMAIL, $role, $isSuperadmin, $stored),
            NotificationType::CHANNEL_PUSH => $this->isEnabled($user->id, $type, NotificationType::CHANNEL_PUSH, $role, $isSuperadmin, $stored),
        ];

        $current[$channel] = $enabled;

        PulseUserNotificationPreference::updateOrCreate(
            [
                'user_id' => $user->id,
                'team_id' => $teamId,
                'notification_type' => $type,
            ],
            [
                'is_email_enabled' => $current[NotificationType::CHANNEL_EMAIL],
                'is_push_enabled' => $current[NotificationType::CHANNEL_PUSH],
            ]
        );
    }

    /**
     * Persist a whole matrix in one go.
     *
     * Accepts any configurable type rather than only the ones the team can use
     * today: muting a factory has to cover water alerts for a factory that has
     * no tanks yet, or installing one would silently un-mute it.
     *
     * @param  array<string, array{email?: bool, push?: bool}>  $preferences
     */
    public function setMany(User $user, int $teamId, array $preferences): void
    {
        $types = NotificationType::configurableKeys();
        $role = $this->roleFor($user->id, $teamId);
        $isSuperadmin = (bool) $user->is_superadmin;

        if (! $this->canReceiveAnything($role, $isSuperadmin)) {
            return;
        }

        $stored = $this->storedFor($teamId, [$user->id], $types);
        $now = now();
        $rows = [];

        foreach ($preferences as $type => $channels) {
            if (! in_array($type, $types, true) || ! $this->canReceiveType($type, $role, $isSuperadmin)) {
                continue;
            }

            $email = array_key_exists(NotificationType::CHANNEL_EMAIL, $channels)
                ? (bool) $channels[NotificationType::CHANNEL_EMAIL]
                : $this->isEnabled($user->id, $type, NotificationType::CHANNEL_EMAIL, $role, $isSuperadmin, $stored);

            $push = array_key_exists(NotificationType::CHANNEL_PUSH, $channels)
                ? (bool) $channels[NotificationType::CHANNEL_PUSH]
                : $this->isEnabled($user->id, $type, NotificationType::CHANNEL_PUSH, $role, $isSuperadmin, $stored);

            $rows[] = [
                'user_id' => $user->id,
                'team_id' => $teamId,
                'notification_type' => $type,
                'is_email_enabled' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_EMAIL) && $email,
                'is_push_enabled' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_PUSH) && $push,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return;
        }

        DB::table('pulse_user_notification_preferences')->upsert(
            $rows,
            ['user_id', 'team_id', 'notification_type'],
            ['is_email_enabled', 'is_push_enabled', 'updated_at']
        );
    }

    /**
     * Turn every notification for a factory on or off in one action. Used by
     * superadmins to mute factories they do not want to follow.
     */
    public function setFactoryEnabled(User $user, int $teamId, bool $enabled): void
    {
        $preferences = [];

        foreach (NotificationType::configurableKeys() as $type) {
            $preferences[$type] = [
                NotificationType::CHANNEL_EMAIL => $enabled,
                NotificationType::CHANNEL_PUSH => $enabled,
            ];
        }

        $this->setMany($user, $teamId, $preferences);
    }

    /**
     * Whether a user still receives anything at all from a factory.
     */
    public function receivesAnythingFrom(User $user, int $teamId): bool
    {
        $types = $this->availableTypesForTeam($teamId);
        $stored = $this->storedFor($teamId, [$user->id], $types);
        $role = $this->roleFor($user->id, $teamId);
        $isSuperadmin = (bool) $user->is_superadmin;

        foreach ($types as $type) {
            foreach ([NotificationType::CHANNEL_EMAIL, NotificationType::CHANNEL_PUSH] as $channel) {
                if ($this->isEnabled($user->id, $type, $channel, $role, $isSuperadmin, $stored)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every factory a superadmin oversees, with its current mute state.
     *
     * @return Collection<int, array{team: Team, enabled: bool}>
     */
    public function factoryOverviewFor(User $user): Collection
    {
        // `is_active` is an accessor over team_pulse, so it has to be filtered
        // through the relation rather than a where() on teams.
        return Team::query()
            ->activePulse()
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team) => [
                'team' => $team,
                'enabled' => $this->receivesAnythingFrom($user, (int) $team->id),
            ]);
    }

    public function roleFor(int $userId, int $teamId): ?string
    {
        return DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->value('role');
    }
}
