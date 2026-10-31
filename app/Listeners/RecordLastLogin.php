<?php

namespace App\Listeners;

use App\Services\Impersonation;
use Illuminate\Auth\Events\Login;

class RecordLastLogin
{
    /**
     * Handle the event.
     * Stamps last_login_at = now() on every login.
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user) {
            return;
        }

        // Impersonation logs in as the target without them being present. Stamping
        // last_login_at here would falsify their login history. The same
        // applies in reverse when the impersonator's identity is restored.
        if (Impersonation::isActive()) {
            return;
        }

        $user->last_login_at = now();
        $user->save();
    }
}
