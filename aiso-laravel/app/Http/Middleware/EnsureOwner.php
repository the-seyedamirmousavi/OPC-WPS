<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The owner only: system administration, roles and the assistant's policies. Ownership is an identity, never a grant. */
class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || !$user->isOwner()) {
            throw new ApiException(403, 'Access denied');
        }

        return $next($request);
    }
}
