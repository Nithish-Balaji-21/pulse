<?php

use App\Jobs\SendPushNotificationJob;
use App\Listeners\SeedSuperadminNotificationPreferences;
use App\Mail\ProductionSummaryMail;
use App\Models\Team;
use App\Models\User;
use App\Services\NotificationSenderService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Jetstream\Events\TeamCreated;
use Tests\Pulse\Support\TeamFactory;

/**
 * Superadmins follow every factory. What they hear from each one is written out
 * as preference rows rather than left to the "superadmins get everything"
 * fallback, so one of them can keep every push alert while being emailed the
 * monthly roll-up alone.
 */
beforeEach(function () {
    config()->set('app.env', 'production');
    Queue::fake();
    Mail::fake();

    $this->teamId = TeamFactory::create('Superadmin Config '.uniqid());

    // Somebody has to hold the To line, or there is no email to inspect.
    $this->owner = User::factory()->create(['is_superadmin' => 0, 'is_internal' => 0]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /**
     * A superadmin configured the way the migration configures the real ones:
     * push on for everything, email on for the listed types alone.
     *
     * @param  array<int, string>|null  $emailTypes  Null for every type.
     */
    $this->configuredSuperadmin = function (?array $emailTypes, ?int $teamId = null): User {
        $user = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1]);
        $now = now();

        $rows = [];

        foreach (NotificationType::keys() as $type) {
            $rows[] = [
                'user_id' => $user->id,
                'team_id' => $teamId ?? $this->teamId,
                'notification_type' => $type,
                'is_email_enabled' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_EMAIL)
                    && ($emailTypes === null || in_array($type, $emailTypes, true)),
                'is_push_enabled' => NotificationType::supportsChannel($type, NotificationType::CHANNEL_PUSH),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('pulse_user_notification_preferences')->insert($rows);

        return $user;
    };

    $this->withDevice = function (User $user): string {
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

        return $token;
    };

    $this->notify = function (string $type, ?int $teamId = null) {
        app(NotificationSenderService::class)->notifyTeamUsers(
            $teamId ?? $this->teamId,
            [],
            'Superadmin Config - Summary',
            ['Pith: 100 cft'],
            now()->subDay()->toDateString(),
            null,
            true,
            $type
        );
    };

    $this->emailed = function (): array {
        $addresses = [];

        Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use (&$addresses) {
            $addresses = collect($mail->to)->merge($mail->cc)->merge($mail->bcc)->pluck('address')->all();

            return true;
        });

        return $addresses;
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

it('blind copies the unrestricted superadmin on every factory email', function () {
    $primary = ($this->configuredSuperadmin)(null);

    ($this->notify)(NotificationType::PRODUCTION_SUMMARY_DAILY);

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($primary) {
        return in_array($primary->email, collect($mail->bcc)->pluck('address')->all(), true)
            && ! in_array($primary->email, collect($mail->to)->pluck('address')->all(), true);
    });
});

it('emails the monthly-only superadmin the monthly summary', function () {
    $restricted = ($this->configuredSuperadmin)([NotificationType::PRODUCTION_SUMMARY_MONTHLY]);

    ($this->notify)(NotificationType::PRODUCTION_SUMMARY_MONTHLY);

    expect(($this->emailed)())->toContain($restricted->email);
});

it('leaves the monthly-only superadmin off every other factory email', function () {
    $restricted = ($this->configuredSuperadmin)([NotificationType::PRODUCTION_SUMMARY_MONTHLY]);

    foreach ([
        NotificationType::PRODUCTION_SUMMARY_DAILY,
        NotificationType::PRODUCTION_SUMMARY_WEEKLY,
        NotificationType::PRODUCTION_SUMMARY_YEARLY,
        NotificationType::PITH_LOCATION_CLOSED,
        NotificationType::LICENSE_EXPIRY,
        NotificationType::WATER_TANK_LOW,
        NotificationType::GENERAL,
    ] as $type) {
        Mail::fake();

        ($this->notify)($type);

        expect(($this->emailed)())
            ->toContain($this->owner->email)
            ->not->toContain($restricted->email);
    }
});

it('still pushes every alert to the monthly-only superadmin', function () {
    $restricted = ($this->configuredSuperadmin)([NotificationType::PRODUCTION_SUMMARY_MONTHLY]);
    $token = ($this->withDevice)($restricted);

    ($this->notify)(NotificationType::PRODUCTION_SUMMARY_DAILY);
    ($this->notify)(NotificationType::MACHINE_OFF);
    ($this->notify)(NotificationType::WATER_TANK_LOW);

    expect(($this->pushedTokens)())->toContain($token);
});

it('carries a superadmin configuration onto a newly created factory', function () {
    $restricted = ($this->configuredSuperadmin)([NotificationType::PRODUCTION_SUMMARY_MONTHLY]);

    $newTeamId = TeamFactory::create('Onboarded Later '.uniqid());

    DB::table('team_user')->insert([
        'team_id' => $newTeamId,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // TeamFactory writes rows directly, so raise the event the Team model would.
    $team = Team::find($newTeamId);
    app(SeedSuperadminNotificationPreferences::class)->handle(new TeamCreated($team));

    ($this->notify)(NotificationType::PRODUCTION_SUMMARY_DAILY, $newTeamId);

    expect(($this->emailed)())->not->toContain($restricted->email);

    Mail::fake();

    ($this->notify)(NotificationType::PRODUCTION_SUMMARY_MONTHLY, $newTeamId);

    expect(($this->emailed)())->toContain($restricted->email);
});

it('gives a superadmin with no history everything on a new factory', function () {
    $fresh = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1]);

    $newTeamId = TeamFactory::create('Fresh Factory '.uniqid());
    $team = Team::find($newTeamId);

    app(SeedSuperadminNotificationPreferences::class)->handle(new TeamCreated($team));

    $rows = DB::table('pulse_user_notification_preferences')
        ->where('user_id', $fresh->id)
        ->where('team_id', $newTeamId)
        ->get();

    expect($rows)->toHaveCount(count(NotificationType::keys()))
        ->and($rows->every(fn ($row) => (bool) $row->is_push_enabled))->toBeTrue()
        ->and($rows->firstWhere('notification_type', NotificationType::PRODUCTION_SUMMARY_DAILY)->is_email_enabled)->toEqual(1);
});
