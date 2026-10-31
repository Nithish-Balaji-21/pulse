<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * `users.current_team_id` is not the team a request runs in — SetActiveTeam never
 * persists it. Routes with no {team} segment must therefore resolve the browsed
 * factory from the `last_team_id` cookie, or a superadmin opening /teams/create
 * from one factory lands in whichever one that column happens to name.
 */
beforeEach(function () {
    $this->browsedTeamId = TeamFactory::create('Browsed Factory '.uniqid());
    $this->staleTeamId = TeamFactory::create('Stale Factory '.uniqid());

    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->staleTeamId,
    ]);

    app()->forgetInstance('current.team');
});

it('sets last_team_id when a team-scoped route is visited', function () {
    $response = $this->actingAs($this->user)->get("/{$this->browsedTeamId}/dashboard");

    $response->assertCookie('last_team_id', (string) $this->browsedTeamId);
});

it('resolves the browsed factory on a route with no team segment', function () {
    $this->actingAs($this->user)
        ->withCookie('last_team_id', (string) $this->browsedTeamId)
        ->get('/teams/create')
        ->assertOk();

    expect(app('current.team')->id)->toBe($this->browsedTeamId);
});

it('falls back to current_team_id when no cookie is present', function () {
    $this->actingAs($this->user)
        ->get('/teams/create')
        ->assertOk();

    expect(app('current.team')->id)->toBe($this->staleTeamId);
});

it('ignores a last_team_id cookie naming a factory the user cannot access', function () {
    $member = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->staleTeamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->staleTeamId,
        'user_id' => $member->id,
        'role' => 'owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($member)->withCookie('last_team_id', (string) $this->browsedTeamId);

    expect($member->activeTeam()->id)->toBe($this->staleTeamId);
});

it('prefers the team in the URL over the cookie', function () {
    $this->actingAs($this->user)
        ->withCookie('last_team_id', (string) $this->staleTeamId)
        ->get("/{$this->browsedTeamId}/dashboard");

    expect(app('current.team')->id)->toBe($this->browsedTeamId);
});

it('reports team access for superadmins and members only', function () {
    $outsider = User::factory()->create(['is_superadmin' => 0]);
    $team = Team::find($this->browsedTeamId);

    expect($this->user->canAccessTeam($team))->toBeTrue()
        ->and($outsider->canAccessTeam($team))->toBeFalse();
});

/**
 * `last_team_id` outlives the session that set it, so a shared browser points the
 * next user at the previous user's factory — and /dashboard redirects on it before
 * anything checks membership. Landing on a factory they can open beats a dead end.
 */
it('redirects to a factory the user belongs to instead of dead-ending', function () {
    $member = memberOf($this->staleTeamId);

    $this->actingAs($member)
        ->get("/{$this->browsedTeamId}/dashboard")
        ->assertRedirect("/{$this->staleTeamId}/dashboard");
});

it('re-stamps the cookie once the fallback factory is opened', function () {
    $member = memberOf($this->staleTeamId);

    $this->actingAs($member)
        ->withCookie('last_team_id', (string) $this->browsedTeamId)
        ->get("/{$this->staleTeamId}/dashboard")
        ->assertCookie('last_team_id', (string) $this->staleTeamId);
});

it('never redirects back to the denied factory', function () {
    // current_team_id still names the factory being denied, so the fallback has
    // to come from actual membership or the redirect would loop onto itself.
    $member = memberOf($this->staleTeamId, currentTeamId: $this->browsedTeamId);

    $this->actingAs($member)
        ->get("/{$this->browsedTeamId}/dashboard")
        ->assertRedirect("/{$this->staleTeamId}/dashboard");
});

it('forbids the request when the user belongs to no other factory', function () {
    $outsider = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->browsedTeamId,
    ]);

    $this->actingAs($outsider)
        ->get("/{$this->browsedTeamId}/dashboard")
        ->assertForbidden()
        ->assertSee('No factory assigned');
});

/**
 * The same forever cookie also survives its factory going away — deleted, or the
 * app repointed at another database — leaving an id that resolves to no team.
 * /dashboard redirects onto it before anything validates it, so a hard 404 here
 * would strand the user on every request with nothing left to clear the cookie.
 */
it('redirects off a team id that no longer exists', function () {
    $member = memberOf($this->staleTeamId);
    $missingTeamId = Team::max('id') + 1000;

    $this->actingAs($member)
        ->withCookie('last_team_id', (string) $missingTeamId)
        ->get("/{$missingTeamId}/dashboard")
        ->assertRedirect("/{$this->staleTeamId}/dashboard");
});

it('re-stamps the cookie off a missing team once the fallback is followed', function () {
    $member = memberOf($this->staleTeamId);
    $missingTeamId = Team::max('id') + 1000;

    $this->actingAs($member)
        ->withCookie('last_team_id', (string) $missingTeamId)
        ->followingRedirects()
        ->get("/{$missingTeamId}/dashboard")
        ->assertCookie('last_team_id', (string) $this->staleTeamId);
});

it('recovers when current_team_id itself names a missing team', function () {
    // A database switch invalidates that column too, so the fallback cannot lean
    // on currentTeam — only real membership is left to redirect to.
    $missingTeamId = Team::max('id') + 1000;
    $member = memberOf($this->staleTeamId, currentTeamId: $missingTeamId);

    $this->actingAs($member)
        ->get("/{$missingTeamId}/dashboard")
        ->assertRedirect("/{$this->staleTeamId}/dashboard");
});

it('404s on a missing team when the user belongs to no factory at all', function () {
    $missingTeamId = Team::max('id') + 1000;
    $orphan = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $missingTeamId,
    ]);

    $this->actingAs($orphan)
        ->get("/{$missingTeamId}/dashboard")
        ->assertNotFound()
        ->assertSee('No factory assigned');
});

it('answers XHR requests for a missing team with 404', function () {
    $member = memberOf($this->staleTeamId);
    $missingTeamId = Team::max('id') + 1000;

    $this->actingAs($member)
        ->getJson("/{$missingTeamId}/dashboard")
        ->assertNotFound();
});

it('answers XHR requests with 403 rather than a redirect', function () {
    $member = memberOf($this->staleTeamId);

    $this->actingAs($member)
        ->getJson("/{$this->browsedTeamId}/dashboard")
        ->assertForbidden();
});

it('forgets last_team_id on logout so the next user starts clean', function () {
    $member = memberOf($this->staleTeamId);

    $response = $this->actingAs($member)
        ->withCookie('last_team_id', (string) $this->staleTeamId)
        ->post('/logout');

    $response->assertCookieExpired('last_team_id');
});

/**
 * A non-superadmin owning exactly one factory.
 */
function memberOf(int $teamId, ?int $currentTeamId = null): User
{
    $user = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $currentTeamId ?? $teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $user->id,
        'role' => 'owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * A user can be left with no resolvable factory (removed from it while
 * `last_team_id` still names it). The profile page has no {team} segment, so the
 * navigation menu renders with a null $currentTeam and must not build team routes.
 */
it('renders the profile page for a user with no resolvable factory', function () {
    $member = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => null,
    ]);

    $this->actingAs($member)
        ->withCookie('last_team_id', (string) $this->browsedTeamId)
        ->get('/user/profile')
        ->assertOk();
});

it('shows the no-factory message from /dashboard instead of a broken redirect', function () {
    $orphan = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => null,
    ]);

    $this->actingAs($orphan)
        ->get('/dashboard')
        ->assertForbidden()
        ->assertSee('No factory assigned');

    $this->actingAs($orphan)
        ->withCookie('last_team_id', (string) $this->browsedTeamId)
        ->get('/dashboard')
        ->assertForbidden()
        ->assertSee('No factory assigned');
});

it('falls back to a factory the user belongs to when /dashboard has no cookie or current team', function () {
    $member = memberOf($this->staleTeamId);
    $member->forceFill(['current_team_id' => null])->save();

    $this->actingAs($member)
        ->get('/dashboard')
        ->assertRedirect("/{$this->staleTeamId}/dashboard");
});
