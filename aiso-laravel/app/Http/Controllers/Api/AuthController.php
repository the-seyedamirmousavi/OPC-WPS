<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $v = $this->input($request, ['userId' => 'required|string', 'password' => 'required|string']);
        $user = AppUser::query()->find(trim($v['userId']));
        // identical message for unknown user, wrong password and disabled account
        if ($user === null || !$user->isActive() || !Hash::check($v['password'], $user->password_hash)) {
            throw new ApiException(401, 'Invalid user id or password');
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'expiresInSeconds' => (int) config('session.lifetime') * 60,
            'user' => UserService::view($user),
        ]);
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
