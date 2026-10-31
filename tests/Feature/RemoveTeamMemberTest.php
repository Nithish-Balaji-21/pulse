<?php

use App\Actions\Jetstream\RemoveTeamMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Pulse\Support\RbacFixtures;

test('team members can be removed from teams', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->ownedBy($owner)->create();

    $team->users()->attach($member = User::factory()->create(), ['role' => 'admin']);

    app(RemoveTeamMember::class)->remove($owner, $team, $member);

    expect($team->fresh()->users)->toHaveCount(1);
    expect($member->fresh()->teams)->toHaveCount(0);
});

test('superadmins can remove team members', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->ownedBy($owner)->create();

    $superadmin = User::factory()->create(['is_superadmin' => 1]);
    $team->users()->attach($member = User::factory()->create(), ['role' => 'admin']);

    app(RemoveTeamMember::class)->remove($superadmin, $team, $member);

    expect($team->fresh()->users)->toHaveCount(1);
});

/**
 * Removing a member is a `manage_users:delete` capability, read off the team's own role
 * matrix — not a rank. The default matrix gives it to the owner alone, so an admin is
 * refused until a team grants it, and granting it is all it takes.
 */
test('team admins cannot remove team members until the team grants it', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->ownedBy($owner)->create();

    $team->users()->attach($admin = User::factory()->create(), ['role' => 'admin']);
    $team->users()->attach($member = User::factory()->create(), ['role' => 'staff']);

    expect(fn () => app(RemoveTeamMember::class)->remove($admin, $team, $member))
        ->toThrow(AuthorizationException::class);
    expect($team->fresh()->users)->toHaveCount(3);

    RbacFixtures::grant($team->id, 'admin', ['manage_users:delete']);

    app(RemoveTeamMember::class)->remove($admin, $team, $member);

    expect($team->fresh()->users)->toHaveCount(2);
});

test('non admin members cannot remove other members', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->ownedBy($owner)->create();

    $team->users()->attach($staff = User::factory()->create(), ['role' => 'staff']);
    $team->users()->attach($otherMember = User::factory()->create(), ['role' => 'reader']);

    expect(fn () => app(RemoveTeamMember::class)->remove($staff, $team, $otherMember))
        ->toThrow(AuthorizationException::class);

    expect($team->fresh()->users)->toHaveCount(3);
});
