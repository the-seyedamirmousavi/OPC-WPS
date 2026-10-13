<?php

namespace App\Http\Middleware;

use App\Models\AppRole;
use App\Enums\SystemStatus;
use App\Exceptions\ApiException;
use App\Models\SystemSetting;
use App\Services\LanguageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the system is SUSPENDED only the OWNER may change data. Reads stay available, and so does signing in.
 */
class CheckSuspension
{
    public function __construct(private readonly LanguageService $lang)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $request->is('api/auth/*')) {
            return $next($request);
        }
        $user = $request->user();
        if ($user !== null && $user->user_role === AppRole::OWNER) {
            return $next($request);
        }
        if (SystemSetting::current()->system_status === SystemStatus::SUSPENDED) {
            throw new ApiException(423, $this->lang->forRequest('The system is suspended by the owner.'));
        }

        return $next($request);
    }
}
