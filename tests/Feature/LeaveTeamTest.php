<?php

use App\Actions\Jetstream\RemoveTeamMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Leaving a team runs through App\Actions\Jetstream\RemoveTeamMember (a member
 * removing themselves). Jetstream's TeamMemberManager component is not used by
 * this app — it is commented out of resources/views/teams/show.blade.php.
 */
test('users can leave teams', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->ownedBy($owner)->create();

    $team->users()->attach($member = User::factory()->create(), ['role' => 'admin']);

    app(RemoveTeamMember::class)->remove($member, $team, $member);

    expect($member->fresh()->teams)->toHaveCount(0);
    expect($team->fresh()->users)->toHaveCount(1);
});

test('team owners cant leave their own team', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->ownedBy($owner)->create();

    expect(fn () => app(RemoveTeamMember::class)->remove($owner, $team, $owner))
        ->toThrow(ValidationException::class);

    expect($team->fresh()->users)->toHaveCount(1);
});
