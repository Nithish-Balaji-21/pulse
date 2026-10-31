<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * A user's role must follow the factory the request is running in.
 *
 * `users.current_team_id` is not that factory — SetActiveTeam is route middleware
 * on the `{team}` group and never runs for `POST /livewire/update`, so the column
 * still names whichever team an explicit switch last wrote. Authorizing against it
 * let Livewire render Edit buttons that the following full request answered with
 * a 403, for anyone holding different roles in two factories.
 */
beforeEach(function () {
    $this->readerTeamId = TeamFactory::create('Reader Factory '.uniqid());
    $this->staffTeamId = TeamFactory::create('Staff Factory '.uniqid());

    // current_team_id deliberately points at the factory they are NOT browsing.
    $this->user = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->staffTeamId,
    ]);

    DB::table('team_user')->insert([
        ['team_id' => $this->readerTeamId, 'user_id' => $this->user->id, 'role' => 'reader'],
        ['team_id' => $this->staffTeamId, 'user_id' => $this->user->id, 'role' => 'staff'],
    ]);

    app()->forgetInstance('current.team');
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('resolves the role from the browsed factory, not current_team_id', function () {
    app()->instance('current.team', Team::find($this->readerTeamId));

    expect($this->user->getCurrentTeamRole())->toBe('reader');
});

it('denies record management to a reader in the browsed factory', function () {
    app()->instance('current.team', Team::find($this->readerTeamId));

    expect($this->user->canManageRecords())->toBeFalse()
        ->and($this->user->canDeleteRecords())->toBeFalse();
});

it('still grants management in the factory where they are staff', function () {
    app()->instance('current.team', Team::find($this->staffTeamId));

    expect($this->user->getCurrentTeamRole())->toBe('staff')
        ->and($this->user->canManageRecords())->toBeTrue();
});

it('falls back to current_team_id when no team is bound', function () {
    expect($this->user->getCurrentTeamRole())->toBe('staff');
});

/**
 * The end-to-end shape of the original report: the edit route 403s for a reader,
 * so no UI rendered on any kind of request may offer them the button.
 */
it('refuses the edit route in the factory where they are a reader', function () {
    $this->actingAs($this->user)
        ->get("/{$this->readerTeamId}/pith/edit/1")
        ->assertForbidden();
});

/**
 * Owner was added to the role hierarchy after these gates were written and was
 * left out of both, so an owner could neither edit nor delete production records
 * while their own staff could.
 */
it('grants an owner the same record access as an admin', function () {
    $ownerTeamId = TeamFactory::create('Owned Factory '.uniqid());

    $owner = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $ownerTeamId,
    ]);
    DB::table('team_user')->insert([
        ['team_id' => $ownerTeamId, 'user_id' => $owner->id, 'role' => 'owner'],
    ]);

    app()->instance('current.team', Team::find($ownerTeamId));

    expect($owner->canManageRecords())->toBeTrue()
        ->and($owner->canDeleteRecords())->toBeTrue();
});

it('still keeps readers out of record management', function () {
    app()->instance('current.team', Team::find($this->readerTeamId));

    expect($this->user->canManageRecords())->toBeFalse();
});

it('still keeps staff out of deletion', function () {
    app()->instance('current.team', Team::find($this->staffTeamId));

    expect($this->user->canManageRecords())->toBeTrue()
        ->and($this->user->canDeleteRecords())->toBeFalse();
});
