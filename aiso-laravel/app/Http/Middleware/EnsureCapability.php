<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capability check for a route group, e.g. ->middleware('can:production.view'). The user passes when the role holds any
 * one of the listed capabilities (the owner holds all). The capabilities come from the database, never from the client.
 */
class EnsureCapability
{
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();
        if ($user === null || !array_filter($capabilities, fn (string $c) => $user->hasCapability($c))) {
            throw new ApiException(403, 'Access denied');
        }

        return $next($request);
    }
}
