<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeQueueMonitor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_superadmin || ! $request->user()?->is_active) {
            // The application's normal error layout requires a factory/team route.
            // This dashboard is global, so return a self-contained denial instead.
            return $request->expectsJson()
                ? response()->json(['message' => 'Superadmin access required.'], 403)
                : response('Superadmin access required.', 403);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
