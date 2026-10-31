<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cookie;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's logout response, plus the `last_team_id` cookie.
 *
 * SetActiveTeam writes that cookie with `forever()`, so logging out otherwise
 * leaves the factory behind for whoever logs in next on the same browser —
 * /dashboard redirects on it, dropping them straight into someone else's team.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        // Forget through the same CookieJar that set it so the path and domain
        // defaults line up; a mismatch leaves the original cookie in place.
        $forgetTeam = Cookie::forget('last_team_id');

        return $request->wantsJson()
            ? (new JsonResponse('', 204))->withCookie($forgetTeam)
            : redirect(Fortify::redirects('logout', '/'))->withCookie($forgetTeam);
    }
}
