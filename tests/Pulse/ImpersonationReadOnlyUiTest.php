<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Pulse\Support\TeamFactory;

/**
 * A read-only impersonation must look read-only.
 *
 * Enforcement alone is not enough: a supervisor genuinely holds manage/delete
 * rights, so their Edit, Inventory, Inward and Outward controls all rendered and
 * then failed at the write guard — which reads as a broken app rather than a
 * deliberate mode. Withdrawing the write capabilities hides every one of them.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Read Only UI Factory '.uniqid());

    $this->superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    $this->supervisor = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        ['team_id' => $this->teamId, 'user_id' => $this->supervisor->id, 'role' => 'supervisor'],
    ]);

    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    Impersonation::clear();
    app()->forgetInstance('current.team');
});

function impersonateReadOnly(User $superadmin, User $target): void
{
    session()->start();
    Impersonation::start($superadmin, $target);
}

it('withdraws write capabilities from a read-only session', function () {
    impersonateReadOnly($this->superadmin, $this->supervisor);

    $user = User::find($this->supervisor->id);

    expect($user->canManageRecords())->toBeFalse()
        ->and($user->canDeleteRecords())->toBeFalse()
        ->and($user->canManageHuskStock())->toBeFalse()
        ->and($user->canCloseLocationStock())->toBeFalse()
        ->and($user->canAddUsers())->toBeFalse()
        ->and($user->canUpdateUsers())->toBeFalse();
});

it('closes the gates the blades and routes check', function () {
    impersonateReadOnly($this->superadmin, $this->supervisor);

    expect(Gate::allows('manage-records'))->toBeFalse()
        ->and(Gate::allows('delete-records'))->toBeFalse()
        ->and(Gate::allows('manage-husk-stock'))->toBeFalse()
        ->and(Gate::allows('close-location-stock'))->toBeFalse();
});

/**
 * Looking at a screen is the whole point, so read capabilities must survive.
 */
it('leaves read capabilities alone', function () {
    impersonateReadOnly($this->superadmin, $this->supervisor);

    $user = User::find($this->supervisor->id);

    expect($user->canAccessTeam(Team::find($this->teamId)))->toBeTrue();
});

it('restores the target real permissions once write mode is on', function () {
    impersonateReadOnly($this->superadmin, $this->supervisor);
    Impersonation::setWriteMode(true);

    $user = User::find($this->supervisor->id);

    // A supervisor may manage records but still may not delete them — write mode
    // returns their own rights, it does not grant admin's.
    expect($user->canManageRecords())->toBeTrue()
        ->and($user->canManageHuskStock())->toBeTrue()
        ->and($user->canCloseLocationStock())->toBeTrue()
        ->and($user->canDeleteRecords())->toBeFalse();
});

it('does not touch permissions when nobody is impersonating', function () {
    $this->actingAs($this->supervisor);

    expect($this->supervisor->canManageRecords())->toBeTrue()
        ->and($this->supervisor->canManageHuskStock())->toBeTrue();
});

it('blocks the edit route during a read-only session', function () {
    impersonateReadOnly($this->superadmin, $this->supervisor);

    expect(Gate::allows('manage-records'))->toBeFalse();
});
