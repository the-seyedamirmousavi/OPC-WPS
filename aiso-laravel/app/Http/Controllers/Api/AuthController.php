<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\LicenseService;
use App\Services\UserService;
use App\Services\VendorAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    /** Failed sign-ins allowed per address and minute before further attempts are refused. */
    private const MAX_FAILED_ATTEMPTS = 10;

    public function __construct(
        private readonly UserService $users,
        private readonly VendorAuth $vendor,
        private readonly LicenseService $license,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $v = $this->input($request, ['userId' => 'required|string', 'password' => 'required|string']);
        $throttle = 'sign-in:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttle, self::MAX_FAILED_ATTEMPTS)) {
            throw new ApiException(429, 'Too many sign-in attempts. Try again in a minute.');
        }

        // The software vendor uses this same form: a match opens the separate vendor console and nothing else. When a device
        // token is configured the browser must carry it in its cookie, otherwise this is just a failed sign-in like any other.
        if ($this->vendor->tokenOk($request) && $this->vendor->matches($v['userId'], $v['password'])) {
            if ($this->vendor->totpRequired()) {
                // password correct, authenticator code still owed: nothing is granted yet and the failure counter stays
                $this->vendor->beginOtp($request);

                return response()->json(['vendor' => true, 'otp' => true]);
            }
            RateLimiter::clear($throttle);
            $this->vendor->start($request);

            return response()->json(['vendor' => true, 'expiresInSeconds' => (int) config('aiso.vendor.idle_minutes') * 60]);
        }

        $user = AppUser::query()->find(trim($v['userId']));
        // identical message for unknown user, wrong password and disabled account
        // an AI agent is a virtual user: nobody signs in as one
        if ($user === null || $user->isAgent() || !$user->isActive() || !Hash::check($v['password'], $user->password_hash)) {
            RateLimiter::hit($throttle, 60);
            throw new ApiException(401, 'Invalid user id or password');
        }
        $this->license->assertSignInAllowed();
        RateLimiter::clear($throttle);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'expiresInSeconds' => (int) config('session.lifetime') * 60,
            'user' => UserService::view($user),
        ]);
    }

    /** Second step of the vendor sign-in (only when an authenticator is configured): the 6-digit code. */
    public function vendorOtp(Request $request): JsonResponse
    {
        $v = $this->input($request, ['code' => 'required|string|max:20']);
        $throttle = 'sign-in:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttle, self::MAX_FAILED_ATTEMPTS)) {
            throw new ApiException(429, 'Too many sign-in attempts. Try again in a minute.');
        }
        // the checks run in this order on purpose: no pending password step, no code is even looked at (or burned)
        if (!$this->vendor->tokenOk($request) || !$this->vendor->pendingOtp($request) || !$this->vendor->verifyOtp($v['code'])) {
            RateLimiter::hit($throttle, 60);
            throw new ApiException(401, 'Invalid verification code');
        }
        RateLimiter::clear($throttle);
        $this->vendor->start($request);

        return response()->json(['vendor' => true, 'expiresInSeconds' => (int) config('aiso.vendor.idle_minutes') * 60]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(UserService::view($this->actor($request)));
    }

    public function changePassword(Request $request): ?JsonResponse
    {
        $v = $this->input($request, ['currentPassword' => 'required|string', 'newPassword' => 'required|string']);
        $this->users->changeOwnPassword($this->actor($request), $v['currentPassword'], $v['newPassword']);

        return null;
    }

    public function logout(Request $request): ?JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return null;
    }
}
