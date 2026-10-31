<?php

use App\Mail\ProductionSummaryMail;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Pulse\Support\TeamFactory;

/**
 * Owners get a licence reminder a week out and again the day before. Renewing
 * in between moves the date, so the factory stops matching and the second
 * reminder never fires.
 */
beforeEach(function () {
    config()->set('app.env', 'production');
    Mail::fake();

    $this->teamId = TeamFactory::create('Licence Factory '.uniqid());

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

    $this->setExpiry = fn (string $date) => DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update(['license_valid_till' => $date]);

    $this->addressedTo = function (): array {
        $addresses = [];

        Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use (&$addresses) {
            $addresses = collect($mail->to)->merge($mail->cc)->merge($mail->bcc)->pluck('address')->all();

            return true;
        });

        return $addresses;
    };
});

it('warns the owner a week before expiry', function () {
    $owner = ($this->member)('owner');
    ($this->setExpiry)(now()->addDays(7)->toDateString());

    $this->artisan('alerts:license-expiry')->assertSuccessful();

    expect(($this->addressedTo)())->toContain($owner->email);
});

it('warns the owner again the day before expiry', function () {
    $owner = ($this->member)('owner');
    ($this->setExpiry)(now()->addDay()->toDateString());

    $this->artisan('alerts:license-expiry')->assertSuccessful();

    Mail::assertQueued(ProductionSummaryMail::class, function ($mail) use ($owner) {
        return str_contains($mail->title, 'expires tomorrow')
            && in_array($owner->email, collect($mail->to)->pluck('address')->all(), true);
    });
});

it('stays quiet on every other day', function () {
    ($this->member)('owner');
    ($this->setExpiry)(now()->addDays(4)->toDateString());

    $this->artisan('alerts:license-expiry')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('stops warning once the licence is extended', function () {
    ($this->member)('owner');
    ($this->setExpiry)(now()->addDays(7)->toDateString());

    // Renewed the same day the first warning went out.
    ($this->setExpiry)(now()->addYear()->toDateString());

    $this->artisan('alerts:license-expiry')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('leaves admins and supervisors off the licence warning by default', function () {
    $owner = ($this->member)('owner');
    $admin = ($this->member)('admin');
    $supervisor = ($this->member)('supervisor');

    ($this->setExpiry)(now()->addDays(7)->toDateString());

    $this->artisan('alerts:license-expiry')->assertSuccessful();

    $addressed = ($this->addressedTo)();

    expect($addressed)->toContain($owner->email)
        ->not->toContain($admin->email)
        ->not->toContain($supervisor->email);
});

it('lets an admin subscribe to the licence warning', function () {
    ($this->member)('owner');
    $admin = ($this->member)('admin');

    app(NotificationPreferenceService::class)->set(
        $admin,
        $this->teamId,
        NotificationType::LICENSE_EXPIRY,
        NotificationType::CHANNEL_EMAIL,
        true
    );

    ($this->setExpiry)(now()->addDays(7)->toDateString());

    $this->artisan('alerts:license-expiry')->assertSuccessful();

    expect(($this->addressedTo)())->toContain($admin->email);
});

it('ignores factories whose licence has already lapsed', function () {
    ($this->member)('owner');
    ($this->setExpiry)(now()->subDay()->toDateString());

    $this->artisan('alerts:license-expiry')->assertSuccessful();

    Mail::assertNothingQueued();
});
