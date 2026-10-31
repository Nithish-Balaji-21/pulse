<?php

namespace App\Services;

use App\Exceptions\ReadOnlyImpersonationException;
use App\Models\ImpersonationLog;
use App\Models\User;
use BadMethodCallException;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Superadmin impersonation: identity swap, read-only enforcement, audit trail.
 *
 * Sessions are read-only by default. Write mode is opt-in per session and every
 * mutation made while impersonating is recorded in `impersonation_logs`,
 * grouped by a per-session uuid.
 *
 * State lives entirely in the session, so nothing leaks into queue workers or
 * console commands — `isActive()` is false wherever there is no started session.
 */
class Impersonation
{
    private const KEY_UUID = 'impersonate.uuid';

    private const KEY_IMPERSONATOR = 'impersonate.impersonator_id';

    private const KEY_WRITE_MODE = 'impersonate.write_mode';

    private const KEY_RETURN_TEAM = 'impersonate.return_team_id';

    /**
     * Attribute names never written to the audit trail.
     *
     * @var list<string>
     */
    private const REDACTED = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'token',
        'api_token',
    ];

    /**
     * Tables the read-only guard must never block.
     *
     * The session driver is `database`, so blocking writes to `sessions` would
     * break every request — including the one trying to stop impersonating.
     *
     * @var list<string>
     */
    private const INFRASTRUCTURE_TABLES = [
        'sessions',
        'impersonation_logs',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
    ];

    /**
     * Set while the audit trail writes itself, so it doesn't trip its own guard.
     */
    private static bool $bypassGuard = false;

    // ─── State ───────────────────────────────────────────────────────────────

    /**
     * The session guard, addressed explicitly.
     *
     * Team routes authenticate with `auth:sanctum`, which makes that the default
     * guard for the request — and Sanctum's guard is a RequestGuard with no
     * login() at all. Every identity swap has to name the session guard.
     */
    private static function guard(): StatefulGuard
    {
        return Auth::guard('web');
    }

    /**
     * Whether the current request is running inside an impersonation session.
     *
     * Safe to call from anywhere: returns false when there is no session at all
     * (queue workers, console commands, tests without a session).
     */
    public static function isActive(): bool
    {
        if (! app()->bound('session.store') || ! session()->isStarted()) {
            return false;
        }

        return session()->has(self::KEY_IMPERSONATOR);
    }

    public static function sessionUuid(): ?string
    {
        return self::isActive() ? session(self::KEY_UUID) : null;
    }

    public static function impersonatorId(): ?int
    {
        return self::isActive() ? (int) session(self::KEY_IMPERSONATOR) : null;
    }

    public static function impersonator(): ?User
    {
        $id = self::impersonatorId();

        return $id ? User::find($id) : null;
    }

    /**
     * Whether this session may mutate data — true when not impersonating at all.
     */
    public static function canWrite(): bool
    {
        return ! self::isActive() || (bool) session(self::KEY_WRITE_MODE, false);
    }

    public static function isReadOnly(): bool
    {
        return self::isActive() && ! self::canWrite();
    }

    /**
     * The team the impersonator was in before the session started, so stopping
     * returns them to their own factory rather than the target's.
     */
    public static function returnTeamId(): ?string
    {
        return self::isActive() ? session(self::KEY_RETURN_TEAM) : null;
    }

    // ─── Lifecycle ───────────────────────────────────────────────────────────

    /**
     * Why $impersonator may not impersonate $target, or null when they may.
     */
    public static function reasonCannotImpersonate(User $impersonator, User $target): ?string
    {
        return match (true) {
            ! $impersonator->isSuperadmin() => 'Only superadmins can impersonate users.',
            self::isActive() => 'You are already impersonating a user. Stop the current session first.',
            $impersonator->id === $target->id => 'You cannot impersonate yourself.',
            (bool) $target->is_superadmin => 'Superadmins cannot be impersonated.',
            ! $target->is_active => 'This user is deactivated and cannot be impersonated.',
            default => null,
        };
    }

    /**
     * Swap the authenticated identity to $target, read-only.
     */
    public static function start(User $impersonator, User $target): void
    {
        // Written before Auth::login() so the Login listener can tell this is an
        // impersonation and skip stamping the target's last_login_at. Session
        // data survives the id regeneration login performs.
        session([
            self::KEY_UUID => (string) Str::uuid(),
            self::KEY_IMPERSONATOR => $impersonator->id,
            self::KEY_WRITE_MODE => false,
            self::KEY_RETURN_TEAM => request()->cookie('last_team_id'),
        ]);

        self::guard()->login($target);
        self::syncSessionPasswordHash($target);
        $target->forgetRoleSessionCache();

        // A confirmed password belongs to the superadmin's identity; it must not
        // wave this session past the target's confirmation walls.
        session()->forget('auth.password_confirmed_at');

        self::record('start');
    }

    /**
     * Restore the impersonator's identity. Returns them, or null if the session
     * was not impersonating (or the impersonator no longer exists).
     */
    public static function stop(): ?User
    {
        if (! self::isActive()) {
            return null;
        }

        $impersonator = self::impersonator();

        if (! $impersonator) {
            // Deleted mid-session; drop the state rather than stranding the session.
            self::clear();

            return null;
        }

        // Recorded before the identity swap so `impersonated_id` still resolves
        // to the target, matching every other row in the session.
        self::record('stop');

        // Logged in while the session flags are still set, so RecordLastLogin
        // recognises this as impersonation and leaves last_login_at alone —
        // returning from a session is not a login the impersonator performed.
        self::guard()->login($impersonator);
        self::syncSessionPasswordHash($impersonator);
        $impersonator->forgetRoleSessionCache();

        self::clear();
        session()->forget('auth.password_confirmed_at');

        return $impersonator;
    }

    /**
     * Re-point the session's AuthenticateSession fingerprint at $user.
     *
     * That middleware stores the authenticated user's password hash in the session
     * and logs out — flushing the session and redirecting to /login — on any later
     * request whose user no longer matches it. An identity swap leaves the previous
     * user's hash behind, so without this the session dies on the very next request.
     */
    private static function syncSessionPasswordHash(User $user): void
    {
        $hash = $user->getAuthPassword();

        try {
            $hash = self::guard()->hashPasswordForCookie($hash);
        } catch (BadMethodCallException) {
            // Guard without cookie hashing; the raw hash is still accepted.
        }

        // The middleware keys on the request's default driver — `sanctum` on team
        // routes, `web` elsewhere. Write both so either lookup matches.
        foreach (array_unique([Auth::getDefaultDriver(), 'web']) as $driver) {
            session(['password_hash_'.$driver => $hash]);
        }

        // $request->user() still resolves through Sanctum's guard, which cached the
        // *previous* user at the start of this request. Left alone, the middleware
        // re-stores that stale user's hash on the way out and undoes the above.
        request()->setUserResolver(fn () => $user);
    }

    public static function setWriteMode(bool $enabled): void
    {
        if (! self::isActive()) {
            return;
        }

        self::record($enabled ? 'write_mode_enabled' : 'write_mode_disabled');

        session([self::KEY_WRITE_MODE => $enabled]);
    }

    /**
     * Drop all impersonation state without touching the authenticated identity.
     */
    public static function clear(): void
    {
        session()->forget([
            self::KEY_UUID,
            self::KEY_IMPERSONATOR,
            self::KEY_WRITE_MODE,
            self::KEY_RETURN_TEAM,
        ]);
    }

    // ─── Audit trail ─────────────────────────────────────────────────────────

    /**
     * Append a row to the current impersonation's trail. No-op when not
     * impersonating, so callers never need to check first.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(string $action, ?Model $subject = null, ?array $changes = null): void
    {
        if (! self::isActive()) {
            return;
        }

        self::withoutGuard(function () use ($action, $subject, $changes) {
            ImpersonationLog::create([
                'session_uuid' => session(self::KEY_UUID),
                'impersonator_id' => session(self::KEY_IMPERSONATOR),
                'impersonated_id' => self::guard()->id(),
                'team_id' => app()->has('current.team') ? app('current.team')->id : null,
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'changes' => $changes ? self::redact($changes) : null,
                'url' => Str::limit(request()->fullUrl(), 1024, ''),
                'ip_address' => request()->ip(),
            ]);
        });
    }

    /**
     * Strip credentials and tokens from an attribute set before it is stored.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function redact(array $attributes): array
    {
        foreach (array_keys($attributes) as $key) {
            if (in_array($key, self::REDACTED, true)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }

    // ─── Write guard ─────────────────────────────────────────────────────────

    /**
     * Run $callback with the read-only guard suspended.
     */
    public static function withoutGuard(callable $callback): mixed
    {
        self::$bypassGuard = true;

        try {
            return $callback();
        } finally {
            self::$bypassGuard = false;
        }
    }

    /**
     * Refuse the write when this session is read-only.
     *
     * @throws ReadOnlyImpersonationException
     */
    public static function assertCanWrite(): void
    {
        if (self::$bypassGuard || ! self::isReadOnly()) {
            return;
        }

        throw new ReadOnlyImpersonationException;
    }

    /**
     * Install the read-only guard and the audit listeners.
     *
     * Two layers, because neither covers everything on its own:
     *
     * 1. Eloquent model events — precise, and the only place with enough context
     *    to name the model and its dirty attributes for the audit trail.
     * 2. A query-level backstop — the app makes ~50 `DB::table()->insert/update/
     *    delete` calls that fire no model events at all, so the Eloquent layer
     *    alone would leave them unguarded.
     */
    public static function registerGuards(): void
    {
        Event::listen(['eloquent.saving: *', 'eloquent.deleting: *'], function (string $event, array $payload): void {
            if (! ($payload[0] ?? null) instanceof ImpersonationLog) {
                self::assertCanWrite();
            }
        });

        Event::listen('eloquent.created: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Model && ! $model instanceof ImpersonationLog) {
                self::record('created', $model, $model->getAttributes());
            }
        });

        Event::listen('eloquent.updated: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Model && ! $model instanceof ImpersonationLog && $model->getChanges()) {
                self::record('updated', $model, $model->getChanges());
            }
        });

        Event::listen('eloquent.deleted: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Model && ! $model instanceof ImpersonationLog) {
                self::record('deleted', $model, $model->getAttributes());
            }
        });

        // Registered per connection as each is resolved — `beforeExecuting` lives
        // on Connection, so DB::beforeExecuting() would only cover the default one.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $event->connection->beforeExecuting(function (string $query): void {
                if (self::isWriteQuery($query)) {
                    self::assertCanWrite();
                }
            });
        });
    }

    /**
     * Whether a raw statement mutates non-infrastructure data.
     *
     * Only statements positively identified as writes are blocked — selects,
     * transaction control and anything unrecognised pass through untouched.
     */
    private static function isWriteQuery(string $query): bool
    {
        $pattern = '/^\s*(?:insert(?:\s+ignore)?\s+into|replace\s+into|update|delete\s+from|truncate(?:\s+table)?|drop\s+table|alter\s+table)\s+`?([a-zA-Z0-9_]+)`?/i';

        if (! preg_match($pattern, $query, $matches)) {
            return false;
        }

        return ! in_array(strtolower($matches[1]), self::INFRASTRUCTURE_TABLES, true);
    }
}
