<?php

namespace App\Providers;

use Cbox\LaravelQueueMonitor\LaravelQueueMonitor;
use Illuminate\Support\ServiceProvider;

class QueueMonitorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        LaravelQueueMonitor::auth(fn ($request): bool => (bool) $request->user()?->is_superadmin && (bool) $request->user()?->is_active);
    }
}
