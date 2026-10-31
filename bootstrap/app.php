<?php

use App\Http\Middleware\LogSlowApiRequest;
use App\Http\Middleware\ResolveActiveTeam;
use App\Http\Middleware\SetActiveTeam;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CoiioT customization to trust IPs of CloudFlare Proxies
        $middleware->trustProxies(at: [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ]);
        $middleware->alias([
            'active.team' => SetActiveTeam::class,
        ]);

        // Resolves the team every web request runs in, including the routes with
        // no {team} segment that 'active.team' can't cover.
        $middleware->web(append: [
            ResolveActiveTeam::class,
        ]);
        $middleware->api(append: [
            LogSlowApiRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
