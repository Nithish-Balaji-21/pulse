<?php

use App\Exceptions\ReadOnlyImpersonationException;
use App\Models\ImpersonationLog;
use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Pulse\Support\TeamFactory;

/**
 * Superadmin impersonation. Sessions start read-only; write mode is opt-in and
 * every mutation made while impersonating lands in `impersonation_logs`.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Impersonation Factory '.uniqid());

    // Distinct passwords on purpose. UserFactory reuses one static hash for every
    // user, which would make AuthenticateSession's password-hash check pass
    // trivially and hide the session dying after an identity swap.
    $this->superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
        'password' => Hash::make('superadmin-secret'),
    ]);

    $this->target = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
        'last_login_at' => null,
        'password' => Hash::make('target-secret'),
    ]);

    DB::table('team_user')->insert([
        ['team_id' => $this->teamId, 'user_id' => $this->target->id, 'role' => 'admin'],
    ]);

    app()->forgetInstance('current.team');
});

/**
 * State lives in the session, which persists in-process across a test — clear it
 * so a read-only session cannot bleed into the next test's fixtures.
 */
afterEach(function () {
    Impersonation::clear();
});

/**
 * Drop the guard users actingAs() seeded so the next request resolves the session
 * cookie the way a browser does. Without this the seeded user shadows the identity
 * the session actually holds, and the HTTP flows prove nothing.
 */
function asBrowser(): void
{
    app('auth')->forgetGuards();
}

/** Put the process inside a live impersonation without going through HTTP. */
function impersonate(User $superadmin, User $target): void
{
    session()->start();
    Impersonation::start($superadmin, $target);
}

// ─── Access control ──────────────────────────────────────────────────────────

it('lets a superadmin start impersonating a user', function () {
    $this->actingAs($this->superadmin)
        ->post(route('impersonate.start', $this->target))
        ->assertRedirect("/{$this->teamId}/dashboard")
        // Asserted on the request's own session — the service reads a store that
        // is no longer started once the response has been sent.
        ->assertSessionHas('impersonate.impersonator_id', $this->superadmin->id)
        ->assertSessionHas('impersonate.write_mode', false);

    // Named explicitly: `auth:sanctum` makes sanctum the request's default guard,
    // so auth()->id() is not the identity that moved.
    $this->assertAuthenticatedAs($this->target, 'web');
});

/**
 * The bug this pins: AuthenticateSession keeps the authenticated user's password
 * hash in the session and logs out any request whose user no longer matches it.
 * An identity swap leaves the previous hash behind, so impersonation survived the
 * POST and then died on the very next request — landing on /login.
 */
it('survives the request after the swap', function () {
    $this->actingAs($this->superadmin)
        ->post(route('impersonate.start', $this->target));

    // forgetGuards() drops the guard user actingAs() seeded, so this request
    // resolves the session cookie the way a browser does. Without it the seeded
    // superadmin masks the swap entirely and the test proves nothing.
    asBrowser();

    // Roles index is readable for admins with manage_users:view (default admin seed).
    $this->get("/{$this->teamId}/roles")
        ->assertOk();

    $this->assertAuthenticatedAs($this->target, 'web');
});

it('keeps the impersonator signed in across requests after stopping', function () {
    $this->actingAs($this->superadmin)->post(route('impersonate.start', $this->target));

    asBrowser();
    $this->get("/{$this->teamId}/roles")->assertOk();

    asBrowser();
    $this->post(route('impersonate.stop'));

    asBrowser();
    $this->get("/{$this->teamId}/roles")->assertOk();
    $this->assertAuthenticatedAs($this->superadmin, 'web');
});

it('refuses a non-superadmin', function () {
    $this->actingAs($this->target)
        ->post(route('impersonate.start', $this->superadmin))
        ->assertForbidden();

    expect(Impersonation::isActive())->toBeFalse();
});

it('refuses to impersonate another superadmin', function () {
    $other = User::factory()->create(['is_superadmin' => 1, 'is_active' => 1]);

    $this->actingAs($this->superadmin)
        ->from('/')
        ->post(route('impersonate.start', $other))
        ->assertSessionHas('error');

    expect(Impersonation::isActive())->toBeFalse();
});

it('refuses to impersonate a deactivated user', function () {
    $inactive = User::factory()->create(['is_superadmin' => 0, 'is_active' => 0]);

    $this->actingAs($this->superadmin)
        ->from('/')
        ->post(route('impersonate.start', $inactive))
        ->assertSessionHas('error');

    expect(Impersonation::isActive())->toBeFalse();
});

// ─── Read-only by default ────────────────────────────────────────────────────

it('blocks Eloquent writes by default', function () {
    impersonate($this->superadmin, $this->target);

    expect(fn () => User::find($this->target->id)->update(['name' => 'Renamed']))
        ->toThrow(ReadOnlyImpersonationException::class);

    expect(User::find($this->target->id)->name)->not->toBe('Renamed');
});

it('blocks raw query-builder writes that fire no model events', function () {
    impersonate($this->superadmin, $this->target);

    expect(fn () => DB::table('users')->where('id', $this->target->id)->update(['name' => 'Renamed']))
        ->toThrow(ReadOnlyImpersonationException::class);

    expect(User::find($this->target->id)->name)->not->toBe('Renamed');
});

it('still allows reads while read-only', function () {
    impersonate($this->superadmin, $this->target);

    expect(User::find($this->target->id)->email)->toBe($this->target->email);
});

/**
 * The session driver is `database`, so a guard that blocked every write would
 * take the app down — including the request trying to stop impersonating.
 */
it('still allows session writes while read-only', function () {
    impersonate($this->superadmin, $this->target);

    DB::table('sessions')->insert([
        'id' => 'impersonation-test-'.uniqid(),
        'payload' => 'x',
        'last_activity' => time(),
    ]);
})->throwsNoExceptions();

it('records no mutation rows for a read-only session', function () {
    impersonate($this->superadmin, $this->target);

    try {
        User::find($this->target->id)->update(['name' => 'Renamed']);
    } catch (ReadOnlyImpersonationException) {
        // expected
    }

    $mutations = ImpersonationLog::where('session_uuid', Impersonation::sessionUuid())
        ->whereIn('action', ['created', 'updated', 'deleted'])
        ->count();

    expect($mutations)->toBe(0);
});

// ─── Write mode ──────────────────────────────────────────────────────────────

it('allows and audits writes once write mode is on', function () {
    impersonate($this->superadmin, $this->target);
    Impersonation::setWriteMode(true);

    User::find($this->target->id)->update(['name' => 'Renamed By Support']);

    expect(User::find($this->target->id)->name)->toBe('Renamed By Support');

    $log = ImpersonationLog::where('session_uuid', Impersonation::sessionUuid())
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->impersonator_id)->toBe($this->superadmin->id)
        ->and($log->impersonated_id)->toBe($this->target->id)
        ->and($log->subject_type)->toBe(User::class)
        ->and($log->subject_id)->toBe($this->target->id)
        ->and($log->changes)->toHaveKey('name')
        ->and($log->changes['name'])->toBe('Renamed By Support');
});

it('redacts credentials from the audit trail', function () {
    impersonate($this->superadmin, $this->target);
    Impersonation::setWriteMode(true);

    User::find($this->target->id)->update(['password' => 'a-new-secret-value']);

    $log = ImpersonationLog::where('session_uuid', Impersonation::sessionUuid())
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($log->changes['password'])->toBe('[redacted]');
});

it('records the write-mode switch itself', function () {
    impersonate($this->superadmin, $this->target);
    Impersonation::setWriteMode(true);

    expect(
        ImpersonationLog::where('session_uuid', Impersonation::sessionUuid())
            ->where('action', 'write_mode_enabled')
            ->exists()
    )->toBeTrue();
});

it('returns to read-only when write mode is switched back off', function () {
    impersonate($this->superadmin, $this->target);
    Impersonation::setWriteMode(true);
    Impersonation::setWriteMode(false);

    expect(fn () => User::find($this->target->id)->update(['name' => 'Renamed']))
        ->toThrow(ReadOnlyImpersonationException::class);
});

// ─── Lifecycle ───────────────────────────────────────────────────────────────

it('opens the session with a start row', function () {
    impersonate($this->superadmin, $this->target);

    $log = ImpersonationLog::where('session_uuid', Impersonation::sessionUuid())
        ->where('action', 'start')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->impersonator_id)->toBe($this->superadmin->id)
        ->and($log->impersonated_id)->toBe($this->target->id);
});

it('restores the impersonator on stop and clears the session', function () {
    $this->actingAs($this->superadmin)->post(route('impersonate.start', $this->target));

    $this->assertAuthenticatedAs($this->target, 'web');

    asBrowser();
    $this->post(route('impersonate.stop'))
        ->assertRedirect()
        ->assertSessionMissing('impersonate.impersonator_id');

    $this->assertAuthenticatedAs($this->superadmin, 'web');
});

it('returns the impersonator to their own factory', function () {
    $ownTeamId = TeamFactory::create('Home Factory '.uniqid());

    $this->actingAs($this->superadmin)
        ->withCookie('last_team_id', (string) $ownTeamId)
        ->post(route('impersonate.start', $this->target));

    asBrowser();
    $this->post(route('impersonate.stop'))
        ->assertRedirect("/{$ownTeamId}/dashboard")
        ->assertCookie('last_team_id', (string) $ownTeamId);
});

it('writes a stop row attributed to the impersonated user', function () {
    impersonate($this->superadmin, $this->target);
    $uuid = Impersonation::sessionUuid();

    Impersonation::stop();

    $log = ImpersonationLog::where('session_uuid', $uuid)->where('action', 'stop')->first();

    expect($log)->not->toBeNull()
        ->and($log->impersonated_id)->toBe($this->target->id)
        ->and(auth()->id())->toBe($this->superadmin->id);
});

/**
 * Stamping last_login_at here would falsify the target's login history and burn
 * the null that triggers their first-ever Quick Start tour.
 */
it('does not stamp last_login_at on the impersonated user', function () {
    impersonate($this->superadmin, $this->target);

    expect(User::find($this->target->id)->last_login_at)->toBeNull();
});

it('does not leave a confirmed password behind for the target', function () {
    session()->start();
    session(['auth.password_confirmed_at' => time()]);

    Impersonation::start($this->superadmin, $this->target);

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('refuses to nest a second impersonation', function () {
    // Created up front: once the read-only session is open, even the fixture
    // insert would be blocked — which is the guard working correctly.
    $other = User::factory()->create(['is_superadmin' => 0, 'is_active' => 1]);

    impersonate($this->superadmin, $this->target);

    expect(Impersonation::reasonCannotImpersonate($this->superadmin, $other))
        ->toContain('already impersonating');
});

it('does not stamp last_login_at on the impersonator when the session ends', function () {
    $this->superadmin->forceFill(['last_login_at' => null])->save();

    impersonate($this->superadmin, $this->target);
    Impersonation::stop();

    expect(User::find($this->superadmin->id)->last_login_at)->toBeNull();
});

// ─── Audit log viewer ────────────────────────────────────────────────────────

it('shows the audit trail to a superadmin', function () {
    impersonate($this->superadmin, $this->target);
    Impersonation::setWriteMode(true);
    User::find($this->target->id)->update(['name' => 'Renamed By Support']);
    Impersonation::stop();

    $this->actingAs($this->superadmin)
        ->get(route('impersonation-logs.index', ['team' => $this->teamId]))
        ->assertOk()
        ->assertSee('Impersonation Audit Log')
        ->assertSee($this->superadmin->name)
        ->assertSee('write mode enabled');
});

it('keeps the audit trail away from non-superadmins', function () {
    $this->actingAs($this->target)
        ->get(route('impersonation-logs.index', ['team' => $this->teamId]))
        ->assertForbidden();
});

// ─── No impersonation, no interference ───────────────────────────────────────

it('leaves ordinary writes untouched when nobody is impersonating', function () {
    $this->actingAs($this->superadmin);

    $logCountBefore = ImpersonationLog::count();

    User::find($this->target->id)->update(['name' => 'Ordinary Edit']);

    expect(User::find($this->target->id)->name)->toBe('Ordinary Edit')
        ->and(ImpersonationLog::count())->toBe($logCountBefore);
});

/**
 * ResolveActiveTeam binds the *superadmin's* team on the impersonate POST (the
 * route carries no {team} segment), and User::activeTeam() prefers that binding.
 * So the redirect aimed at a factory the target may not belong to.
 */
it('lands in a factory the target actually belongs to', function () {
    $otherTeamId = TeamFactory::create('Target Only Factory '.uniqid());

    $stranger = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $otherTeamId,
        'password' => Hash::make('stranger-secret'),
    ]);
    DB::table('team_user')->insert([
        ['team_id' => $otherTeamId, 'user_id' => $stranger->id, 'role' => 'admin'],
    ]);

    $this->actingAs($this->superadmin)
        ->withCookie('last_team_id', (string) $this->teamId)
        ->post(route('impersonate.start', $stranger))
        ->assertRedirect("/{$otherTeamId}/dashboard");
});
