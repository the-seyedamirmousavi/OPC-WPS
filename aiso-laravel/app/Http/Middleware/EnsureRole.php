<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role check for a route group, e.g. ->middleware('role:OWNER,MANAGER'). The role comes from the database, never from
 * the client.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if ($user === null || !in_array($user->user_role->value, $roles, true)) {
            throw new ApiException(403, 'Access denied');
        }

        return $next($request);
    }
}
