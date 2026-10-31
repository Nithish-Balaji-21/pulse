<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        if ($recipients = $this->recipients()) {
            Horizon::routeMailNotificationsTo($recipients);
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null
                && in_array($user->email, $this->recipients(), true);
        });
    }

    /**
     * The queue operators, from HORIZON_RECIPIENTS.
     *
     * @return list<string>
     */
    protected function recipients(): array
    {
        return config('horizon.recipients', []);
    }
}
