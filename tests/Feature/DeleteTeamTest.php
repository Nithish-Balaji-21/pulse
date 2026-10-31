<?php

use App\Models\Team;
use App\Models\User;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm;
use Livewire\Livewire;

test('teams can be deleted', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create([
        'is_superadmin' => 1,
    ]));

    $team = Team::factory()->ownedBy($user)->create();

    $team->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'test-role']
    );

    Livewire::test(DeleteTeamForm::class, ['team' => $team->fresh()])
        ->call('deleteTeam');

    expect($team->fresh())->toBeNull();
    expect($otherUser->fresh()->teams)->toHaveCount(0);
});

/**
 * Owning a team is not enough to delete it — TeamPolicy::delete() allows
 * superadmins only. Replaces the old "personal teams cant be deleted" test;
 * personal teams were dropped in the July 2026 team schema refactor.
 */
test('teams cant be deleted by non superadmins', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $team = Team::factory()->ownedBy($user)->create();

    Livewire::test(DeleteTeamForm::class, ['team' => $team->fresh()])
        ->call('deleteTeam');

    expect($team->fresh())->not->toBeNull();
});
