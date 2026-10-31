<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Mail\ProductionSummaryMail;
use App\Models\Team;
use App\Models\User;
use App\Support\Notifications\NotificationType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kreait\Firebase\Factory;

class NotificationSenderService
{
    public function __construct(protected NotificationPreferenceService $preferences) {}

    /**
     * @param  array<int, string>  $roles  Restrict to these team roles; empty means every role is considered.
     * @param  array<int, string>|string  $notificationType
     * @param  array{content_base64: string, filename: string, mime: string}|null  $attachment
     */
    public function notifyTeamUsers(int $teamId, array $roles = [], $subject = '', $bodyLines = [], $startDate = null, $endDate = null, bool $instant = false, array|string $notificationType = NotificationType::GENERAL, ?array $attachment = null): void
    {
        $team = Team::with('pulse')->find($teamId);
        if (! $team || $team->is_active == 0) {
            Log::info("Skipping notifications: Team {$teamId} is inactive.");

            return;
        }

        $types = $this->normalizeTypes($notificationType);
        $recipients = $this->resolveRecipients($team, $roles, $types);

        if ($recipients->isEmpty()) {
            Log::info("No users found to notify for team {$teamId}.");

            return;
        }

        $body = empty($bodyLines) ? 'No data available' : implode("\n", $bodyLines);
        $startDate = $startDate ? Carbon::parse($startDate) : null;
        $endDate = $endDate ? Carbon::parse($endDate) : null;

        // Push notifications
        $this->pushNotify($recipients, $team, $subject, $body, $startDate, $endDate, $instant, $types);

        // Email notifications
        $emailSubject = str_replace('Yesterday', 'Daily Summary', $subject);
        $this->emailNotify($recipients, $teamId, $emailSubject, $bodyLines, $attachment);
    }

    /**
     * Everyone who should hear about $types for this team, already resolved
     * against their personal preferences.
     *
     * Team members are subscribed by role; superadmins are subscribed to every
     * factory unless they have explicitly muted one.
     *
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $types
     * @return Collection<int, array{user: User, role: ?string, tier: string, email: bool, push: bool}>
     */
    protected function resolveRecipients(Team $team, array $roles, array $types): Collection
    {
        $teamId = (int) $team->id;
        $members = $this->getNotificationUsers($teamId, $roles);
        $superadmins = $this->getSuperadmins();

        /** @var Collection<int, User> $candidates */
        $candidates = $members->keyBy('id');

        foreach ($superadmins as $superadmin) {
            if (! $candidates->has($superadmin->id)) {
                $candidates->put($superadmin->id, $superadmin);
            }
        }

        $stored = $this->preferences->storedFor($teamId, $candidates->keys()->all(), $types);

        return $candidates
            ->reject(fn (User $user) => str_starts_with(strtolower($user->email), 'noreply'))
            ->reject(fn (User $user) => ! $this->preferences->canReceiveAnything(
                $user->team_role ?? null,
                (bool) $user->is_superadmin
            ))
            ->map(function (User $user) use ($stored, $types) {
                $role = $user->team_role ?? null;
                $isSuperadmin = (bool) $user->is_superadmin;

                $email = false;
                $push = false;

                foreach ($types as $type) {
                    $email = $email || $this->preferences->isEnabled($user->id, $type, NotificationType::CHANNEL_EMAIL, $role, $isSuperadmin, $stored);
                    $push = $push || $this->preferences->isEnabled($user->id, $type, NotificationType::CHANNEL_PUSH, $role, $isSuperadmin, $stored);
                }

                return [
                    'user' => $user,
                    'role' => $role,
                    'tier' => $this->emailTierFor($user, $role),
                    'email' => $email,
                    'push' => $push,
                ];
            })
            ->filter(fn (array $recipient) => $recipient['email'] || $recipient['push'])
            ->values();
    }

    /**
     * Which line of the email a recipient belongs on.
     *
     * Owners and admins are the audience, supervisors are copied, and our own
     * staff are blind-copied so factory users never see internal addresses.
     */
    protected function emailTierFor(User $user, ?string $role): string
    {
        if ($user->is_superadmin || $user->is_internal) {
            return 'bcc';
        }

        return in_array($role, NotificationType::PRIMARY_EMAIL_ROLES, true) ? 'to' : 'cc';
    }

    /**
     * @param  Collection<int, array{user: User, role: ?string, tier: string, email: bool, push: bool}>  $recipients
     * @param  array<int, string>  $types
     */
    protected function pushNotify(Collection $recipients, Team $team, $subject, $body, $startDate, $endDate, bool $instant, array $types): void
    {
        // Suppress push notification for dev environments
        if (config('app.env') !== 'production') {
            Log::info('Not sending push notification for dev environment');

            return;
        }

        $users = $recipients->where('push', true)->pluck('user');

        if ($users->isEmpty()) {
            return;
        }

        try {
            if (! class_exists(Factory::class)) {
                Log::info('Firebase push skipped: Kreait\Firebase\Factory not found.');

                return;
            }

            // Get FCM tokens for users with an active mobile session
            $tokenRows = DB::table('user_device_tokens')
                ->join('personal_access_tokens', 'user_device_tokens.user_id', '=', 'personal_access_tokens.tokenable_id')
                ->whereIn('user_device_tokens.user_id', $users->pluck('id'))
                ->where('personal_access_tokens.tokenable_type', '=', User::class)
                ->distinct()
                ->select('user_device_tokens.user_id', 'user_device_tokens.fcm_token')
                ->get()
                ->filter(fn ($row) => ! empty($row->fcm_token));

            if ($tokenRows->isNotEmpty()) {
                // Factory name prefix is only shown to superadmins and multi-factory users
                $prefixedUserIds = $this->getUserIdsNeedingFactoryPrefix($users);

                $plainSubject = str_starts_with($subject, "{$team->name} - ")
                    ? substr($subject, strlen("{$team->name} - "))
                    : $subject;

                [$prefixedTokenRows, $plainTokenRows] = $tokenRows->partition(
                    fn ($row) => in_array($row->user_id, $prefixedUserIds)
                );

                $batches = [
                    ["{$team->name}: {$plainSubject}", $prefixedTokenRows->pluck('fcm_token')->unique()->values()->toArray()],
                    [$plainSubject, $plainTokenRows->pluck('fcm_token')->unique()->values()->toArray()],
                ];

                foreach ($batches as [$batchSubject, $batchTokens]) {
                    if (empty($batchTokens)) {
                        continue;
                    }

                    if ($instant) {
                        SendPushNotificationJob::dispatchSync($batchTokens, $batchSubject, $body);
                    } else {
                        SendPushNotificationJob::dispatch($batchTokens, $batchSubject, $body);
                    }
                }
            }

            // Instant machine/water alerts are push-only — do not persist to notifications table
            if (empty(array_intersect($types, NotificationType::transientAlertTypes()))) {
                $this->storeNotificationsInDb(
                    $users,
                    (int) $team->id,
                    $this->getNotificationType($startDate, $endDate),
                    $this->formatSubjectForStorage($subject),
                    $body,
                    now()->toDateString()
                );
            }
        } catch (\Throwable $e) {
            Log::error('Push notification error: '.$e->getMessage());
        }
    }

    /**
     * @param  Collection<int, array{user: User, role: ?string, tier: string, email: bool, push: bool}>  $recipients
     * @param  array{content_base64: string, filename: string, mime: string}|null  $attachment
     */
    protected function emailNotify(Collection $recipients, int $teamId, string $subject, $body, ?array $attachment = null): void
    {
        // Suppress email notification for dev environments
        if (config('app.env') !== 'production') {
            Log::info('Not sending user email notification for dev environment');

            return;
        }

        if (empty($body)) {
            $body = ['No production'];
        }

        $emailRecipients = $recipients->where('email', true);

        if ($emailRecipients->isEmpty()) {
            Log::info("No email recipients found for team {$teamId}, email skipped.");

            return;
        }

        $addressesFor = fn (string $tier) => $emailRecipients
            ->where('tier', $tier)
            ->pluck('user.email')
            ->unique()
            ->values()
            ->all();

        $to = $addressesFor('to');
        $cc = $addressesFor('cc');
        $bcc = $addressesFor('bcc');

        // An email always needs at least one visible recipient: promote the next
        // tier up rather than dropping the notification.
        if (empty($to)) {
            $to = $cc;
            $cc = [];
        }

        if (empty($to)) {
            $to = [config('mail.from.address')];
        }

        try {
            Mail::to($to)
                ->cc($cc)
                ->bcc($bcc)
                ->queue(new ProductionSummaryMail($subject, $body, $teamId, $attachment));
        } catch (\Throwable $e) {
            Log::error('Email notification error: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, string>|string  $notificationType
     * @return array<int, string>
     */
    protected function normalizeTypes(array|string $notificationType): array
    {
        $types = array_values(array_filter((array) $notificationType));

        if (empty($types)) {
            return [NotificationType::GENERAL];
        }

        return $types;
    }

    /**
     * Users who should still see the factory name in push titles:
     * superadmins and users belonging to more than one factory.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, int> user ids
     */
    protected function getUserIdsNeedingFactoryPrefix(Collection $users): array
    {
        $userIds = $users->pluck('id');

        $factoryCounts = DB::table('team_user')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->whereIn('team_user.user_id', $userIds)
            ->select('team_user.user_id', 'team_user.team_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('team_id')->unique()->count());

        return $users
            ->filter(fn ($user) => $user->is_superadmin || $factoryCounts->get($user->id, 0) > 1)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    protected function getNotificationUsers(int $teamId, array $roles = []): Collection
    {
        $allowedRoles = NotificationType::NOTIFIABLE_ROLES;

        // Narrowed to roles that may receive notifications for this factory.
        $roles = empty($roles)
            ? $allowedRoles
            : array_values(array_intersect($roles, $allowedRoles));

        if (empty($roles)) {
            return collect();
        }

        return User::join('team_user', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.team_id', $teamId)
            ->where('users.is_active', 1)
            ->whereIn('team_user.role', $roles)
            ->select('users.*', 'team_user.role as team_role')
            ->get();
    }

    /**
     * Superadmins oversee every factory, so they are candidates for all teams
     * regardless of team membership.
     *
     * @return Collection<int, User>
     */
    protected function getSuperadmins(): Collection
    {
        return User::query()
            ->where('is_superadmin', 1)
            ->where('is_active', 1)
            ->get();
    }

    protected function getNotificationType(?Carbon $startDate, ?Carbon $endDate): string
    {
        $yesterday = Carbon::yesterday();

        // Case 1: Daily (when only yesterday is passed)
        if (
            $startDate && (
                ($endDate === null && $startDate->isSameDay($yesterday)) ||
                ($endDate && $startDate->isSameDay($yesterday) && $endDate->isSameDay($yesterday))
            )
        ) {
            return 'daily';
        }

        // Case 2: Weekly (full 7-day calendar week)
        if (
            $startDate && $endDate &&
            $startDate->isStartOfWeek() &&
            $endDate->isEndOfWeek() &&
            $startDate->month === $endDate->month &&
            $startDate->year === $endDate->year
        ) {
            return 'weekly';
        }

        // Case 3: Monthly (start of month to end of same month)
        if (
            $startDate && $endDate &&
            $startDate->isStartOfMonth() &&
            $endDate->isEndOfMonth() &&
            $startDate->month === $endDate->month &&
            $startDate->year === $endDate->year
        ) {
            return 'monthly';
        }

        // Case 4: Yearly (start of year to end of same year)
        if (
            $startDate && $endDate &&
            $startDate->isStartOfYear() &&
            $endDate->isEndOfYear() &&
            $startDate->year === $endDate->year
        ) {
            return 'yearly';
        }

        // Default: custom range
        return 'custom';
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function storeNotificationsInDb(Collection $users, int $teamId, string $notifyType, string $subject, string $body, string $notifyDate): void
    {
        $notifications = [];
        $now = now();

        foreach ($users as $user) {
            $notifications[] = [
                'team_id' => $teamId,
                'user_id' => $user->id,
                'date' => $notifyDate,
                'type' => $notifyType,
                'subject' => $subject,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($notifications)) {
            DB::table('notifications')->insert($notifications);
        }
    }

    protected function formatSubjectForStorage(string $subject): string
    {
        // Only transform "Yesterday (...)" subjects
        if (str_contains($subject, 'Yesterday')) {

            $formattedDate = Carbon::yesterday()
                ->format(config('app.day_date_only_format', 'D, M j'));

            return preg_replace(
                '/Yesterday\s*\(.*?\)/',
                $formattedDate,
                $subject
            );
        }

        return $subject;
    }
}
