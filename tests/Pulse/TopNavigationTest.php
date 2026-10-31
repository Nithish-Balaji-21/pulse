<?php

use App\Livewire\NotificationBell;
use App\Models\Team;
use App\Models\User;
use App\Services\Impersonation;
use App\Support\Pulse\TeamRolePermissions;
use App\View\Components\SettingsPanel;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * The header's four controls — factory switcher, settings, notifications, account —
 * plus the superadmin-only Admin entry, each with one job.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Top Nav Factory '.uniqid());
    TeamRolePermissions::syncOnModuleChange($this->teamId);

    $this->member = function (string $role): User {
        $user = User::factory()->create(['is_superadmin' => 0, 'is_active' => 1, 'current_team_id' => $this->teamId]);
        DB::table('team_user')->insert(['team_id' => $this->teamId, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    };

    $this->notification = function (User $user, int $teamId, bool $read = false, ?string $date = null): int {
        return (int) DB::table('notifications')->insertGetId([
            'team_id' => $teamId,
            'user_id' => $user->id,
            'date' => $date ?? now()->toDateString(),
            'type' => 'daily',
            'subject' => 'Top Nav Factory - Yesterday',
            'body' => "Shift 1: 10 bales\nTotal: 10 bales",
            'is_read' => $read ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    Impersonation::clear();
    app()->forgetInstance('current.team');
});

it('shows superadmins the Admin menu with platform tools', function () {
    $superadmin = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1, 'current_team_id' => $this->teamId]);

    $this->actingAs($superadmin)
        ->get("/{$this->teamId}/notification-preferences")
        ->assertSuccessful()
        ->assertSee('Platform · all factories')
        ->assertSee(route('allusers.index', ['team' => $this->teamId]), false)
        ->assertSee(route('impersonation-logs.index', ['team' => $this->teamId]), false)
        ->assertSee('Superadmin');
});

it('keeps platform tools out of a factory member\'s header', function () {
    $owner = ($this->member)('owner');

    $this->actingAs($owner)
        ->get("/{$this->teamId}/notification-preferences")
        ->assertSuccessful()
        ->assertSee('Top Nav Factory')
        ->assertSee('Notification preferences')
        ->assertDontSee('Platform · all factories')
        ->assertDontSee(route('allusers.index', ['team' => $this->teamId]), false);
});

it('scopes settings groups to what the role may configure', function () {
    $team = Team::find($this->teamId);

    $ownerTitles = collect(SettingsPanel::groupsFor($team, ($this->member)('owner')))->pluck('title');
    $readerTitles = collect(SettingsPanel::groupsFor($team, ($this->member)('reader')))->pluck('title');

    expect($ownerTitles)->toContain('Team & access')
        ->and($readerTitles)->not->toContain('Team & access');
});

it('keeps personal notification settings out of the factory settings panel', function () {
    $groups = SettingsPanel::groupsFor(Team::find($this->teamId), ($this->member)('owner'));
    $urls = collect($groups)->flatMap(fn (array $group) => collect($group['items'])->pluck('url'));

    expect($urls)->not->toContain(route('notification-preferences.index', ['team' => $this->teamId]));
});

it('lists only this user\'s notifications for this factory, with the unread count', function () {
    $owner = ($this->member)('owner');
    $otherTeamId = TeamFactory::create('Other Factory '.uniqid());

    ($this->notification)($owner, $this->teamId);
    ($this->notification)($owner, $this->teamId);
    ($this->notification)($owner, $this->teamId, read: true);
    ($this->notification)($owner, $otherTeamId);
    ($this->notification)($owner, $this->teamId, date: now()->subDays(30)->toDateString());

    $this->actingAs($owner);

    Livewire::test(NotificationBell::class, ['teamId' => $this->teamId])
        ->assertViewHas('unreadCount', 2)
        ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 3);
});

it('marks all of this factory\'s notifications read without touching other factories', function () {
    $owner = ($this->member)('owner');
    $otherTeamId = TeamFactory::create('Other Factory '.uniqid());

    $here = ($this->notification)($owner, $this->teamId);
    $elsewhere = ($this->notification)($owner, $otherTeamId);

    $this->actingAs($owner);

    Livewire::test(NotificationBell::class, ['teamId' => $this->teamId])
        ->call('markAllRead')
        ->assertViewHas('unreadCount', 0);

    expect(DB::table('notifications')->where('id', $here)->value('is_read'))->toBe(1)
        ->and(DB::table('notifications')->where('id', $elsewhere)->value('is_read'))->toBe(0);
});

it('does not mark notifications read during a read-only impersonation', function () {
    $owner = ($this->member)('owner');
    $superadmin = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1, 'current_team_id' => $this->teamId]);
    $id = ($this->notification)($owner, $this->teamId);

    session()->start();
    Impersonation::start($superadmin, $owner);
    $this->actingAs($owner);

    Livewire::test(NotificationBell::class, ['teamId' => $this->teamId])
        ->assertViewHas('canMarkRead', false)
        ->call('markAllRead')
        ->call('markRead', $id);

    expect(DB::table('notifications')->where('id', $id)->value('is_read'))->toBe(0);
});
