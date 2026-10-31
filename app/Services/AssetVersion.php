<?php

namespace App\Services;

/**
 * Cache-busting URLs for the hand-maintained assets under public/.
 *
 * Those files carry no build hash — unlike the Vite bundle — and Cloudflare
 * sits in front of the app, so an edited script or stylesheet can keep serving
 * from cache after a deploy. The page markup is regenerated per request, so it
 * moves on while the asset does not: markup calling a function the cached file
 * has never heard of is what that looks like from the browser.
 *
 * Stamping the file's modification time onto the URL makes every deploy that
 * changes a file ask for a URL no cache has seen.
 */
class AssetVersion
{
    public static function url(string $path): string
    {
        $absolute = public_path($path);

        // A missing file is the deployment's problem, not this helper's — link it unversioned.
        if (! is_file($absolute)) {
            return asset($path);
        }

        return asset($path).'?v='.filemtime($absolute);
    }
}
