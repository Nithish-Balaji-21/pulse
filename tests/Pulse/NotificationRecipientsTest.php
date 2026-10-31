<?php

use App\Mail\ProductionSummaryMail;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationSenderService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Pulse\Support\TeamFactory;

/**
 * Who hears about a factory event, and on which line of the email.
 *
 * Owners and admins are the audience (To), supervisors are copied (Cc) and
 * superadmins are blind-copied for every factory (Bcc) unless they have muted
 * that factory. Everything is delivered through ProductionSummaryMail, so the
 * assertions read the envelope off that mailable.
 */
beforeEach(function () {
    // The sender only actually delivers in production.
    config()->set('app.env', 'production');
    Mail::fake();

    $this->teamId = TeamFactory::create('Notify Factory '.uniqid());

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

    $this->notify = function (string $type = NotificationType::PRODUCTION_SUMMARY_DAILY) {
        app(NotificationSenderService::class)->notifyTeamUsers(
            $this->teamId,
            [],
            'Test Factory - Yesterday (Jan 1)',
            ['Pith: 100 cft'],
            now()->subDay()->toDateString(),
            null,
            false,
            $type
        );
    };
});

it('puts owners and admins on the To line and supervisors on Cc', function () {
    $owner = ($this->member)('owner');
    $admin = ($this->member)('admin');
    $supervisor = ($this->member)('supervisor');

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($owner, $admin, $supervisor) {
        $to = collect($mail->to)->pluck('address')->all();
        $cc = collect($mail->cc)->pluck('address')->all();

        return in_array($owner->email, $to, true)
            && in_array($admin->email, $to, true)
            && in_array($supervisor->email, $cc, true)
            && ! in_array($supervisor->email, $to, true);
    });
});

it('never emails staff or readers, even with a preference row saying otherwise', function () {
    ($this->member)('owner');
    $staff = ($this->member)('staff');
    $reader = ($this->member)('reader');

    // A row left behind by a role change must not put them back on the list.
    foreach ([$staff, $reader] as $user) {
        DB::table('pulse_user_notification_preferences')->insert([
            'user_id' => $user->id,
            'team_id' => $this->teamId,
            'notification_type' => NotificationType::PRODUCTION_SUMMARY_DAILY,
            'is_email_enabled' => 1,
            'is_push_enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($staff, $reader) {
        $everyone = collect($mail->to)->merge($mail->cc)->merge($mail->bcc)->pluck('address')->all();

        return ! in_array($staff->email, $everyone, true)
            && ! in_array($reader->email, $everyone, true);
    });
});

it('never emails a user who has left the factory', function () {
    ($this->member)('owner');
    $formerMember = ($this->member)('admin');

    DB::table('team_user')
        ->where('team_id', $this->teamId)
        ->where('user_id', $formerMember->id)
        ->delete();

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($formerMember) {
        $everyone = collect($mail->to)->merge($mail->cc)->merge($mail->bcc)->pluck('address')->all();

        return ! in_array($formerMember->email, $everyone, true);
    });
});

it('tells recipients how to opt out at the bottom of the email', function () {
    ($this->member)('owner');

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) {
        $rendered = $mail->render();

        return str_contains($rendered, 'Notification preferences')
            && str_contains($rendered, route('notification-preferences.index', ['team' => $this->teamId]));
    });
});

it('blind copies superadmins who are not members of the factory', function () {
    ($this->member)('owner');
    $superadmin = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1]);

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($superadmin) {
        $bcc = collect($mail->bcc)->pluck('address')->all();
        $to = collect($mail->to)->pluck('address')->all();

        return in_array($superadmin->email, $bcc, true)
            && ! in_array($superadmin->email, $to, true);
    });
});

it('drops a superadmin who has muted the factory', function () {
    ($this->member)('owner');
    $superadmin = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1]);

    app(NotificationPreferenceService::class)
        ->setFactoryEnabled($superadmin, $this->teamId, false);

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($superadmin) {
        $everyone = collect($mail->to)->merge($mail->cc)->merge($mail->bcc)->pluck('address')->all();

        return ! in_array($superadmin->email, $everyone, true);
    });
});

it('honours an explicit opt-out by a team member', function () {
    $owner = ($this->member)('owner');
    ($this->member)('admin');

    app(NotificationPreferenceService::class)->set(
        $owner,
        $this->teamId,
        NotificationType::PRODUCTION_SUMMARY_DAILY,
        NotificationType::CHANNEL_EMAIL,
        false
    );

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($owner) {
        $everyone = collect($mail->to)->merge($mail->cc)->merge($mail->bcc)->pluck('address')->all();

        return ! in_array($owner->email, $everyone, true);
    });
});

it('keeps the daily summary when only the weekly one is muted', function () {
    $owner = ($this->member)('owner');

    app(NotificationPreferenceService::class)->set(
        $owner,
        $this->teamId,
        NotificationType::PRODUCTION_SUMMARY_WEEKLY,
        NotificationType::CHANNEL_EMAIL,
        false
    );

    ($this->notify)(NotificationType::PRODUCTION_SUMMARY_DAILY);

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($owner) {
        return in_array($owner->email, collect($mail->to)->pluck('address')->all(), true);
    });
});

it('never emails machine alerts because they are push only', function () {
    ($this->member)('owner');
    ($this->member)('admin');

    ($this->notify)(NotificationType::MACHINE_OFF);

    Mail::assertNothingQueued();
});

it('notifies owners, admins and supervisors when a pith location is closed', function () {
    $owner = ($this->member)('owner');
    $supervisor = ($this->member)('supervisor');

    ($this->notify)(NotificationType::PITH_LOCATION_CLOSED);

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($owner, $supervisor) {
        return in_array($owner->email, collect($mail->to)->pluck('address')->all(), true)
            && in_array($supervisor->email, collect($mail->cc)->pluck('address')->all(), true);
    });
});

it('sends nothing from a deactivated factory', function () {
    ($this->member)('owner');

    // Deactivating the factory is the only way to silence it wholesale; there
    // is no longer a separate per-factory notification switch.
    DB::table('team_pulse')->where('team_id', $this->teamId)->update(['is_active' => 0]);

    ($this->notify)();

    Mail::assertNothingQueued();
});

it('skips deactivated users', function () {
    ($this->member)('admin');
    $owner = ($this->member)('owner');
    $owner->forceFill(['is_active' => 0])->save();

    ($this->notify)();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($owner) {
        $everyone = collect($mail->to)->merge($mail->cc)->merge($mail->bcc)->pluck('address')->all();

        return ! in_array($owner->email, $everyone, true);
    });
});
