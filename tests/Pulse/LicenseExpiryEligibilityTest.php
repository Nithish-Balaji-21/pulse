<?php

use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The licence is the owner's to renew. An admin may follow it if they choose;
 * a supervisor is not offered it at all and cannot subscribe themselves.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Licence Roles '.uniqid());

    $this->member = function (string $role): User {
        $user = User::factory()->create(['is_superadmin' => 0, 'is_internal' => 0]);

        DB::table('team_user')->insert([
            'team_id' => $this->teamId,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    };

    $this->typesOffered = function (User $user): array {
        return collect(app(NotificationPreferenceService::class)->matrixFor($user, $this->teamId))
            ->flatMap(fn (array $group) => $group['types'])
            ->pluck('type')
            ->all();
    };
});

it('offers the licence warning to an owner', function () {
    expect(($this->typesOffered)(($this->member)('owner')))
        ->toContain(NotificationType::LICENSE_EXPIRY);
});

it('offers the licence warning to an admin so they can subscribe', function () {
    expect(($this->typesOffered)(($this->member)('admin')))
        ->toContain(NotificationType::LICENSE_EXPIRY);
});

it('does not offer the licence warning to a supervisor at all', function () {
    $supervisor = ($this->member)('supervisor');

    expect(($this->typesOffered)($supervisor))
        ->not->toContain(NotificationType::LICENSE_EXPIRY)
        // Everything else is still theirs to configure.
        ->toContain(NotificationType::PRODUCTION_SUMMARY_DAILY);
});

it('refuses to store a licence subscription for a supervisor', function () {
    $supervisor = ($this->member)('supervisor');

    app(NotificationPreferenceService::class)->set(
        $supervisor,
        $this->teamId,
        NotificationType::LICENSE_EXPIRY,
        NotificationType::CHANNEL_EMAIL,
        true
    );

    expect(
        DB::table('pulse_user_notification_preferences')
            ->where('user_id', $supervisor->id)
            ->where('notification_type', NotificationType::LICENSE_EXPIRY)
            ->exists()
    )->toBeFalse();
});

it('ignores a licence row left behind on a supervisor', function () {
    $supervisor = ($this->member)('supervisor');

    // A row from before they were demoted, or written straight to the table.
    DB::table('pulse_user_notification_preferences')->insert([
        'user_id' => $supervisor->id,
        'team_id' => $this->teamId,
        'notification_type' => NotificationType::LICENSE_EXPIRY,
        'is_email_enabled' => 1,
        'is_push_enabled' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $enabled = app(NotificationPreferenceService::class)->isEnabled(
        $supervisor->id,
        NotificationType::LICENSE_EXPIRY,
        NotificationType::CHANNEL_EMAIL,
        'supervisor',
        false
    );

    expect($enabled)->toBeFalse();
});

it('lets an admin subscribe to the licence warning', function () {
    $admin = ($this->member)('admin');
    $preferences = app(NotificationPreferenceService::class);

    $preferences->set(
        $admin,
        $this->teamId,
        NotificationType::LICENSE_EXPIRY,
        NotificationType::CHANNEL_EMAIL,
        true
    );

    expect($preferences->isEnabled(
        $admin->id,
        NotificationType::LICENSE_EXPIRY,
        NotificationType::CHANNEL_EMAIL,
        'admin',
        false,
        $preferences->storedFor($this->teamId, [$admin->id], [NotificationType::LICENSE_EXPIRY])
    ))->toBeTrue();
});
