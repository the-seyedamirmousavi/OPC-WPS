<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function list(): JsonResponse
    {
        return response()->json($this->users->list());
    }

    /** The role is a key of the company's roles; the service checks that it exists and can be given to a person. */
    public function create(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'id' => ['nullable', 'regex:/\A[A-Za-z0-9_.\-]{2,64}\z/'],
            'fullName' => 'required|string',
            'role' => 'required|string|max:20',
            'messengerId' => 'nullable|string',
            'contactInfo' => 'nullable|string',
            'password' => 'required|string',
        ]);

        return response()->json($this->users->create($v['id'] ?? null, $v['fullName'], $v['role'],
            $v['messengerId'] ?? null, $v['contactInfo'] ?? null, $v['password'], $this->actor($request)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'fullName' => 'nullable|string',
            'role' => 'nullable|string|max:20',
            'active' => 'nullable|boolean',
            'messengerId' => 'nullable|string',
            'contactInfo' => 'nullable|string',
        ]);

        return response()->json($this->users->update($id, $v['fullName'] ?? null, $v['role'] ?? null, $v['active'] ?? null,
            $v['messengerId'] ?? null, $v['contactInfo'] ?? null, $this->actor($request)));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->users->delete($id, $this->actor($request));

        return response()->json(null, 204);
    }

    public function resetPassword(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['newPassword' => 'required|string']);
        $this->users->resetPassword($id, $v['newPassword'], $this->actor($request));

        return null;
    }
}
