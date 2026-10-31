<?php

use App\Livewire\ManageUsers;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Roles are managed by App\Livewire\ManageUsers (routed as users.index behind the
 * `view-settings` gate), not by Jetstream's TeamMemberManager, which this app does
 * not render. The valid roles are admin, supervisor, staff and reader.
 */
test('team member roles can be updated', function () {
    $this->actingAs($owner = User::factory()->withPersonalTeam()->create());
    $team = $owner->currentTeam;

    $team->users()->attach($member = User::factory()->create(), ['role' => 'staff']);

    Livewire::test(ManageUsers::class)
        ->call('edit', $member->id)
        ->set('role', 'supervisor')
        ->call('update');

    expect($member->fresh()->teams()->find($team->id)->pivot->role)->toEqual('supervisor');
});

test('team member roles are restricted to the known set', function () {
    $this->actingAs($owner = User::factory()->withPersonalTeam()->create());
    $team = $owner->currentTeam;

    $team->users()->attach($member = User::factory()->create(), ['role' => 'staff']);

    Livewire::test(ManageUsers::class)
        ->call('edit', $member->id)
        ->set('role', 'editor')
        ->call('update')
        ->assertHasErrors('role');

    expect($member->fresh()->teams()->find($team->id)->pivot->role)->toEqual('staff');
});

test('only owners superadmins and team admins may manage team members', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    $team->users()->attach($admin = User::factory()->create(), ['role' => 'admin']);
    $team->users()->attach($staff = User::factory()->create(), ['role' => 'staff']);

    expect(Gate::forUser($owner)->allows('updateTeamMember', $team))->toBeTrue();
    expect(Gate::forUser($staff)->allows('updateTeamMember', $team))->toBeFalse();
});
