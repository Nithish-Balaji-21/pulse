<?php

namespace App\Providers;

use App\Listeners\CreateDefaultNotificationPreferences;
use App\Listeners\LogSlowQuery;
use App\Listeners\RemoveNotificationPreferences;
use App\Listeners\SeedSuperadminNotificationPreferences;
use App\Listeners\SendFailedJobAlert;
use App\Models\Team;
use App\Observers\TeamObserver;
use App\Services\Impersonation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Team::observe(TeamObserver::class);

        // Read-only enforcement + audit trail for superadmin impersonation.
        Impersonation::registerGuards();

        $isQueue = app()->runningInConsole()
            && Str::contains(implode(' ', $_SERVER['argv']), 'queue:work');

        $slowQueryLogger = new LogSlowQuery($isQueue);
        DB::listen(fn ($query) => $slowQueryLogger->handle($query));
        Queue::failing(app(SendFailedJobAlert::class));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // ─── Share view data — NO DB work here ───────────────────────────────
        // View::composer('*') fires for every @include, so we must NOT query
        // the DB here. The ResolveActiveTeam middleware has already bound the
        // team for this request.
        View::composer('*', function ($view) {
            try {
                $isAuthenticated = Auth::check();
            } catch (\Throwable $e) {
                // Stale or corrupt "remember me" cookie — treat as guest
                Log::warning('Auth::check() failed in View composer (bad cookie?): '.$e->getMessage());

                return;
            }

            if (! $isAuthenticated) {
                return;
            }

            // Resolve current team (already bound by ResolveActiveTeam middleware)
            $currentTeam = Auth::user()?->activeTeam();

            // Always share currentTeam (even if null) to prevent undefined variable errors
            $viewData = [
                'currentTeam' => $currentTeam,
            ];

            if ($currentTeam) {
                $viewData['activeModule'] = $currentTeam->active_module;
            }

            $view->with($viewData);

            // License expiry warning — for the team being browsed, not current_team_id
            $expiryDateValue = $currentTeam?->pulse?->license_valid_till;
            $expiryDate = $expiryDateValue
                ? Carbon::parse($expiryDateValue)
                : null;

            // `license_valid_till` is inclusive (see Team::hasPulseAccess()), so the
            // warning must still show on the expiry date itself — the team retains
            // access that whole day.
            $showExpiryWarning = $expiryDate
                && $expiryDate->gte(Carbon::today())
                && $expiryDate->lte(now()->addWeeks(2));

            $view->with([
                'showExpiryWarning' => $showExpiryWarning,
                'expiryDate' => $expiryDate,
            ]);
        });
        // EventServiceProvider is not in bootstrap/providers.php, so its $listen
        // map never runs — these registrations are the live ones.
        Event::listen(
            TeamMemberAdded::class,
            CreateDefaultNotificationPreferences::class,
        );
        Event::listen(
            TeamMemberRemoved::class,
            RemoveNotificationPreferences::class,
        );
        // Superadmins follow every factory without joining one, so TeamMemberAdded
        // never fires for them and a new factory would fall back to the defaults.
        Event::listen(
            TeamCreated::class,
            SeedSuperadminNotificationPreferences::class,
        );
        // Place Pulse views under resources/views/pulse/... to use this.
        View::addNamespace('pulse', resource_path('views/Pulse'));

        // @assetVersion('js/foo.js') — an asset() URL stamped with the file's mtime, so a
        // deploy that changes the file is not served the cached old one. See AssetVersion.
        Blade::directive('assetVersion', function (string $expression): string {
            return "<?php echo e(\App\Services\AssetVersion::url({$expression})); ?>";
        });
    }
}
