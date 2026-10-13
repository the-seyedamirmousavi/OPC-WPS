<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\VendorAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the vendor console API. Anyone without a live vendor session, including signed-in owners and managers, gets
 * exactly the answer an unknown path gets (404), so the console cannot be discovered by probing.
 */
class EnsureVendor
{
    public function __construct(private readonly VendorAuth $vendor)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->vendor->active($request)) {
            throw new ApiException(404, 'Not found');
        }

        return $next($request);
    }
}
