<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LogoutResponse;
use App\Models\User;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Fortify's default password confirmation validates credentials as
        // [Fortify::username() => $user->{Fortify::username()}, ...]. This app sets
        // `fortify.username` to 'username', which is a login *form field* resolved
        // against email/mobile below — there is no `users.username` column, so the
        // default path queries a non-existent column and 500s. Check the hash directly.
        Fortify::confirmPasswordsUsing(function (User $user, string $password) {
            return Hash::check($password, $user->password);
        });

        // The 'dashboard' route requires a {team} parameter, so the framework's
        // default RedirectIfAuthenticated::defaultRedirectUri() (which calls
        // route('dashboard') with no params) throws UrlGenerationException for
        // already-authenticated users hitting Fortify's guest-only routes (e.g. /login).
        RedirectIfAuthenticated::redirectUsing(function (Request $request) {
            $teamId = $request->cookie('last_team_id') ?? $request->user()?->current_team_id;

            return $teamId ? "/{$teamId}/dashboard" : '/dashboard';
        });

        // Bound here rather than in register() so it always lands after Fortify's
        // own provider has bound the default. Drops the `last_team_id` cookie on
        // logout — see App\Http\Responses\LogoutResponse.
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->input('username'))
                ->orWhere('mobile', $request->input('username'))
                ->first();

            if ($user && Hash::check($request->password, $user->password)) {
                // Check if user account is active
                if (! $user->is_active) {
                    throw ValidationException::withMessages(['email' => 'Your account has been deactivated. Please contact your administrator.']);
                }

                // Check if user is assigned to a team
                if ($user->current_team_id == null) {
                    throw ValidationException::withMessages(['email' => 'Your account is not assigned to any factory.']);
                }

                Auth::login($user, true);

                return $user;
            }

            // Explicitly return null when authentication fails
            return null;
        });
    }
}
