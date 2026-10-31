<?php

use App\Livewire\NotificationPreferences;
use App\Models\Team;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * Every user picks their own notifications, and a superadmin can additionally
 * mute a whole factory. Absent a stored row the role defaults apply, so these
 * tests assert on what actually lands in
 * `pulse_user_notification_preferences` rather than on the screen alone.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Preference Factory '.uniqid());
    $this->otherTeamId = TeamFactory::create('Other Factory '.uniqid());

    $this->member = function (string $role, array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'is_superadmin' => 0,
            'current_team_id' => $this->teamId,
        ], $attributes));

        DB::table('team_user')->insert([
            'team_id' => $this->teamId,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    };

    app()->forgetInstance('current.team');
});

it('shows an admin their notification screen', function () {
    $admin = ($this->member)('admin');

    $this->actingAs($admin)
        ->get("/{$this->teamId}/notification-preferences")
        ->assertOk()
        ->assertSeeLivewire(NotificationPreferences::class);
});

it('opens with the role defaults selected', function () {
    $admin = ($this->member)('admin');

    // Visiting the team-scoped route first binds `current.team`, which is how
    // the full-page component learns which factory it is configuring.
    $this->actingAs($admin)->get("/{$this->teamId}/notification-preferences")->assertOk();

    Livewire::actingAs($admin)
        ->test(NotificationPreferences::class)
        ->assertSet('canReceiveNotifications', true)
        ->assertSet('preferences.'.NotificationType::PRODUCTION_SUMMARY_DAILY.'.email', true)
        ->assertSet('preferences.'.NotificationType::PITH_LOCATION_CLOSED.'.email', true)
        ->assertSet('preferences.'.NotificationType::MACHINE_OFF.'.push', true)
        // Machine alerts are push-only, so the email column stays off.
        ->assertSet('preferences.'.NotificationType::MACHINE_OFF.'.email', false)
        // The licence is the owner's to renew; admins can opt in.
        ->assertSet('preferences.'.NotificationType::LICENSE_EXPIRY.'.email', false);
});

it('persists a toggle the user flips', function () {
    $owner = ($this->member)('owner');

    $this->actingAs($owner)->get("/{$this->teamId}/notification-preferences")->assertOk();

    Livewire::actingAs($owner)
        ->test(NotificationPreferences::class)
        ->set('preferences.'.NotificationType::PRODUCTION_SUMMARY_DAILY.'.email', false);

    $row = DB::table('pulse_user_notification_preferences')
        ->where('user_id', $owner->id)
        ->where('team_id', $this->teamId)
        ->where('notification_type', NotificationType::PRODUCTION_SUMMARY_DAILY)
        ->first();

    expect($row)->not->toBeNull()
        ->and((bool) $row->is_email_enabled)->toBeFalse();
});

it('lets a superadmin mute an entire factory', function () {
    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);

    $service = app(NotificationPreferenceService::class);

    expect($service->receivesAnythingFrom($superadmin, $this->otherTeamId))->toBeTrue();

    $this->actingAs($superadmin)->get("/{$this->teamId}/notification-preferences")->assertOk();

    Livewire::actingAs($superadmin)
        ->test(NotificationPreferences::class)
        ->set('factoryEnabled.'.$this->otherTeamId, false);

    expect($service->receivesAnythingFrom($superadmin->fresh(), $this->otherTeamId))->toBeFalse();
});

it('keeps staff out of notification preferences', function () {
    $staff = ($this->member)('staff');

    $this->actingAs($staff)
        ->get("/{$this->teamId}/notification-preferences")
        ->assertForbidden();
});

it('keeps supervisors out of notification preferences', function () {
    $supervisor = ($this->member)('supervisor');

    $this->actingAs($supervisor)
        ->get("/{$this->teamId}/notification-preferences")
        ->assertForbidden();
});

it('refuses to store a preference for a staff member', function () {
    $staff = ($this->member)('staff');

    app(NotificationPreferenceService::class)->set(
        $staff,
        $this->teamId,
        NotificationType::PRODUCTION_SUMMARY_DAILY,
        NotificationType::CHANNEL_EMAIL,
        true
    );

    expect(
        DB::table('pulse_user_notification_preferences')
            ->where('user_id', $staff->id)
            ->where('team_id', $this->teamId)
            ->exists()
    )->toBeFalse();
});

it('turns the API away for a role that is never notified', function () {
    $reader = ($this->member)('reader');

    $this->actingAs($reader, 'sanctum')
        ->getJson('/api/notification-preferences')
        ->assertStatus(403);

    $this->actingAs($reader, 'sanctum')
        ->putJson('/api/notification-preferences', [
            'preferences' => [NotificationType::PRODUCTION_SUMMARY_DAILY => ['email' => true]],
        ])
        ->assertStatus(403);
});

it('clears a member\'s preferences when they leave the factory', function () {
    $admin = ($this->member)('admin');

    app(NotificationPreferenceService::class)->set(
        $admin,
        $this->teamId,
        NotificationType::PRODUCTION_SUMMARY_DAILY,
        NotificationType::CHANNEL_EMAIL,
        false
    );

    $team = Team::find($this->teamId);
    TeamMemberRemoved::dispatch($team, $admin);

    expect(
        DB::table('pulse_user_notification_preferences')
            ->where('user_id', $admin->id)
            ->where('team_id', $this->teamId)
            ->exists()
    )->toBeFalse();
});

it('keeps a superadmin\'s factory mute when they leave that factory', function () {
    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $superadmin->id,
        'role' => 'admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(NotificationPreferenceService::class)->setFactoryEnabled($superadmin, $this->teamId, false);

    $team = Team::find($this->teamId);
    TeamMemberRemoved::dispatch($team, $superadmin);

    expect(app(NotificationPreferenceService::class)->receivesAnythingFrom($superadmin, $this->teamId))
        ->toBeFalse();
});

it('does not offer water alerts to a factory without tanks', function () {
    $owner = ($this->member)('owner');

    $types = app(NotificationPreferenceService::class)->availableTypesForTeam($this->teamId);

    expect($types)->not->toContain(NotificationType::WATER_TANK_LOW)
        ->and($types)->toContain(NotificationType::PRODUCTION_SUMMARY_DAILY)
        ->and($owner->id)->toBeGreaterThan(0);
});

it('returns the preference matrix over the API', function () {
    $owner = ($this->member)('owner');

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/notification-preferences')
        ->assertOk()
        ->assertJsonPath('team_id', $this->teamId)
        ->assertJsonPath('role', 'owner')
        ->assertJsonStructure(['groups' => [['group', 'types' => [['type', 'label', 'email', 'push']]]]]);
});

it('updates preferences over the API', function () {
    $owner = ($this->member)('owner');

    $this->actingAs($owner, 'sanctum')
        ->putJson('/api/notification-preferences', [
            'preferences' => [
                NotificationType::PRODUCTION_SUMMARY_WEEKLY => ['email' => false, 'push' => false],
            ],
        ])
        ->assertOk();

    $row = DB::table('pulse_user_notification_preferences')
        ->where('user_id', $owner->id)
        ->where('team_id', $this->teamId)
        ->where('notification_type', NotificationType::PRODUCTION_SUMMARY_WEEKLY)
        ->first();

    expect((bool) $row->is_email_enabled)->toBeFalse()
        ->and((bool) $row->is_push_enabled)->toBeFalse();
});

it('rejects an unknown notification type over the API', function () {
    $owner = ($this->member)('owner');

    $this->actingAs($owner, 'sanctum')
        ->putJson('/api/notification-preferences', [
            'preferences' => ['NOT_A_REAL_TYPE' => ['email' => true]],
        ])
        ->assertStatus(422);
});

it('keeps the factory mute endpoints to superadmins', function () {
    $owner = ($this->member)('owner');

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/notification-preferences/factories')
        ->assertStatus(403);

    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);

    $this->actingAs($superadmin, 'sanctum')
        ->putJson("/api/notification-preferences/factories/{$this->otherTeamId}", ['enabled' => false])
        ->assertOk();

    expect(app(NotificationPreferenceService::class)->receivesAnythingFrom($superadmin, $this->otherTeamId))
        ->toBeFalse();
});
