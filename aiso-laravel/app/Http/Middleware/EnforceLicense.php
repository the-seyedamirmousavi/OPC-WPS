<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the licence to every signed-in request. BLOCKED ends the session (the client returns to the sign-in page,
 * which then explains why); READ_ONLY keeps reading available and refuses changes, like a suspension does.
 * Without a company record, or while the licence is valid, this does nothing.
 */
class EnforceLicense
{
    public function __construct(private readonly LicenseService $license)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->license->state();
        if ($state['access'] === LicenseService::FULL || $request->is('api/auth/logout')) {
            return $next($request);
        }
        if ($state['access'] === LicenseService::BLOCKED) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw new ApiException(401, (string) $state['message']);
        }
        // READ_ONLY
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $request->is('api/auth/*')) {
            return $next($request);
        }

        throw new ApiException(423, (string) $state['message']);
    }
}
