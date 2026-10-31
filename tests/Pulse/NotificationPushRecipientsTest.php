<?php

use App\Jobs\SendPushNotificationJob;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationSenderService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Pulse\Support\TeamFactory;

/**
 * Machine alerts are push-only; water alerts go out on both channels. Both
 * reach owners, admins and supervisors — and nobody else, since staff and
 * readers are excluded from notifications outright.
 */
beforeEach(function () {
    config()->set('app.env', 'production');
    Queue::fake();
    Mail::fake();

    $this->teamId = TeamFactory::create('Push Factory '.uniqid());

    // A push only reaches a user who has a device token and a live API session.
    $this->memberWithDevice = function (string $role, array $attributes = []): array {
        $user = User::factory()->create(array_merge(['is_superadmin' => 0], $attributes));

        DB::table('team_user')->insert([
            'team_id' => $this->teamId,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fcm-'.$user->id.'-'.uniqid();

        DB::table('user_device_tokens')->insert([
            'user_id' => $user->id,
            'device_id' => 'device-'.$user->id,
            'device_platform' => 'android',
            'fcm_token' => $token,
        ]);

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'mobile',
            'token' => hash('sha256', $token),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $token];
    };

    $this->alert = function (string $type) {
        app(NotificationSenderService::class)->notifyTeamUsers(
            $this->teamId,
            [],
            'Push Factory - Machine Alerts',
            ['Defibring machine stopped at 10:00'],
            now(),
            null,
            true,
            $type
        );
    };

    $this->pushedTokens = function (): array {
        $tokens = [];

        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as $job) {
                if ($job['job'] instanceof SendPushNotificationJob) {
                    $tokens = array_merge($tokens, (fn () => $this->tokens)->call($job['job']));
                }
            }
        }

        return $tokens;
    };
});

it('pushes machine alerts to owners, admins and supervisors', function () {
    [, $ownerToken] = ($this->memberWithDevice)('owner');
    [, $adminToken] = ($this->memberWithDevice)('admin');
    [, $supervisorToken] = ($this->memberWithDevice)('supervisor');

    ($this->alert)(NotificationType::MACHINE_OFF);

    expect(($this->pushedTokens)())
        ->toContain($ownerToken)
        ->toContain($adminToken)
        ->toContain($supervisorToken);
});

it('never pushes to staff or readers, whatever their preferences say', function () {
    ($this->memberWithDevice)('owner');
    [$staff, $staffToken] = ($this->memberWithDevice)('staff');
    [, $readerToken] = ($this->memberWithDevice)('reader');

    // Writing the preference directly: the service itself refuses to store one.
    DB::table('pulse_user_notification_preferences')->insert([
        'user_id' => $staff->id,
        'team_id' => $this->teamId,
        'notification_type' => NotificationType::MACHINE_OFF,
        'is_email_enabled' => 1,
        'is_push_enabled' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ($this->alert)(NotificationType::MACHINE_OFF);

    expect(($this->pushedTokens)())
        ->not->toContain($staffToken)
        ->not->toContain($readerToken);
});

it('lets a supervisor opt out of machine alerts', function () {
    [, $ownerToken] = ($this->memberWithDevice)('owner');
    [$supervisor, $supervisorToken] = ($this->memberWithDevice)('supervisor');

    app(NotificationPreferenceService::class)->set(
        $supervisor,
        $this->teamId,
        NotificationType::MACHINE_OFF,
        NotificationType::CHANNEL_PUSH,
        false
    );

    ($this->alert)(NotificationType::MACHINE_OFF);

    expect(($this->pushedTokens)())
        ->toContain($ownerToken)
        ->not->toContain($supervisorToken);
});

it('pushes water tank alerts to owners, admins and supervisors', function () {
    [, $ownerToken] = ($this->memberWithDevice)('owner');
    [, $supervisorToken] = ($this->memberWithDevice)('supervisor');

    ($this->alert)(NotificationType::WATER_TANK_LOW);

    expect(($this->pushedTokens)())
        ->toContain($ownerToken)
        ->toContain($supervisorToken);
});

it('pushes to a superadmin of another factory', function () {
    ($this->memberWithDevice)('owner');

    $superadmin = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1]);
    $token = 'fcm-super-'.uniqid();

    DB::table('user_device_tokens')->insert([
        'user_id' => $superadmin->id,
        'device_id' => 'device-super',
        'device_platform' => 'ios',
        'fcm_token' => $token,
    ]);

    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => User::class,
        'tokenable_id' => $superadmin->id,
        'name' => 'mobile',
        'token' => hash('sha256', $token),
        'abilities' => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ($this->alert)(NotificationType::MACHINE_OFF);

    expect(($this->pushedTokens)())->toContain($token);
});

it('does not write transient machine alerts to the in-app feed', function () {
    [$owner] = ($this->memberWithDevice)('owner');

    ($this->alert)(NotificationType::MACHINE_OFF);

    expect(
        DB::table('notifications')->where('team_id', $this->teamId)->where('user_id', $owner->id)->exists()
    )->toBeFalse();
});
