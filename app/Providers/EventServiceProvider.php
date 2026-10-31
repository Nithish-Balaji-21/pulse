<?php

namespace App\Providers;

use App\Listeners\CreateDefaultNotificationPreferences;  // Use the correct ServiceProvider
use App\Listeners\RecordLastLogin;
use App\Listeners\RemoveNotificationPreferences;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        TeamMemberAdded::class => [
            CreateDefaultNotificationPreferences::class,
        ],
        TeamMemberRemoved::class => [
            RemoveNotificationPreferences::class,
        ],
        Login::class => [
            RecordLastLogin::class,
        ],
    ];

    /**
     * Register any services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}
