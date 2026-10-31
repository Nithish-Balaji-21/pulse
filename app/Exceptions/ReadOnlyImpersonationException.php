<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an impersonation session that has not enabled write mode attempts
 * to mutate data.
 */
class ReadOnlyImpersonationException extends RuntimeException
{
    public function __construct(string $message = 'This impersonation session is read-only. Enable write mode in the impersonation banner to make changes.')
    {
        parent::__construct($message);
    }

    /**
     * Surface as a flash message rather than a 500 page — this is an expected
     * outcome of read-only mode, not a fault.
     */
    public function render(Request $request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $this->getMessage()], 403);
        }

        return back()->with('error', $this->getMessage());
    }
}
