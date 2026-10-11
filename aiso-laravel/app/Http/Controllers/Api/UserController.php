<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function list(): JsonResponse
    {
        return response()->json($this->users->list());
    }

    public function create(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'id' => ['required', 'regex:/\A[A-Za-z0-9_.\-]{2,64}\z/'],
            'fullName' => 'required|string',
            'role' => ['required', Rule::in(array_column(Role::cases(), 'value'))],
            'messengerId' => 'nullable|string',
            'contactInfo' => 'nullable|string',
            'password' => 'required|string',
        ]);

        return response()->json($this->users->create($v['id'], $v['fullName'], Role::from($v['role']),
            $v['messengerId'] ?? null, $v['contactInfo'] ?? null, $v['password'], $this->actor($request)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'fullName' => 'nullable|string',
            'role' => ['nullable', Rule::in(array_column(Role::cases(), 'value'))],
            'active' => 'nullable|boolean',
            'messengerId' => 'nullable|string',
            'contactInfo' => 'nullable|string',
        ]);

        return response()->json($this->users->update($id, $v['fullName'] ?? null,
            isset($v['role']) ? Role::from($v['role']) : null, $v['active'] ?? null,
            $v['messengerId'] ?? null, $v['contactInfo'] ?? null, $this->actor($request)));
    }

    public function resetPassword(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['newPassword' => 'required|string']);
        $this->users->resetPassword($id, $v['newPassword'], $this->actor($request));

        return null;
    }
}
