<?php

namespace App\Http\Controllers\Api;

use App\Models\AppRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\RoleService;
use App\Services\VendorAuth;
use App\Services\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The software vendor's console API. Every route here is behind the "vendor" middleware (404 for everyone else). */
class VendorController extends Controller
{
    public function __construct(private readonly VendorService $vendor, private readonly VendorAuth $auth)
    {
    }

    public function me(): JsonResponse
    {
        return response()->json(['vendor' => true, 'idleMinutes' => (int) config('aiso.vendor.idle_minutes')]);
    }

    public function logout(Request $request): ?JsonResponse
    {
        $this->auth->end($request);

        return null;
    }

    // ---- company and licence

    public function overview(): JsonResponse
    {
        return response()->json($this->vendor->overview());
    }

    public function saveCompany(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'required|string|max:200',
            'legalName' => 'nullable|string|max:200',
            'contactName' => 'nullable|string|max:200',
            'contactPhone' => 'nullable|string|max:50',
            'contactEmail' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json($this->vendor->saveCompany($v));
    }

    public function savePeriod(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'plan' => 'required|in:UNLIMITED,YEARLY,TRIAL,CUSTOM',
            'startsAt' => 'nullable|string|max:40',
            'days' => 'nullable|integer|min:1|max:3650',
            'expiresAt' => 'nullable|string|max:40',
        ]);

        return response()->json($this->vendor->savePeriod($v));
    }

    public function extend(Request $request): JsonResponse
    {
        $v = $this->input($request, ['days' => 'required|integer|min:1|max:3650']);

        return response()->json($this->vendor->extend((int) $v['days']));
    }

    public function saveRules(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'graceDays' => 'nullable|integer|min:0|max:365',
            'maxUsers' => 'nullable|integer|min:1|max:100000',
            'maxActiveProjects' => 'nullable|integer|min:1|max:100000',
            'allowLlm' => 'required|boolean',
            'allowBale' => 'required|boolean',
        ]);

        return response()->json($this->vendor->saveRules($v));
    }

    public function suspend(Request $request): JsonResponse
    {
        $v = $this->input($request, ['reason' => 'required|string|max:500']);

        return response()->json($this->vendor->suspend($v['reason']));
    }

    public function resume(): JsonResponse
    {
        return response()->json($this->vendor->resume());
    }

    public function events(): JsonResponse
    {
        return response()->json($this->vendor->events());
    }

    // ---- owner and users

    public function owner(): JsonResponse
    {
        return response()->json(['owner' => $this->vendor->owner()]);
    }

    public function setOwner(Request $request): JsonResponse
    {
        $v = $this->input($request, ['id' => 'required|string|max:64', 'fullName' => 'required|string|max:200', 'password' => 'nullable|string|max:200']);

        return response()->json($this->vendor->setOwner($v['id'], $v['fullName'], $v['password'] ?? null));
    }

    public function resetOwnerPassword(Request $request): JsonResponse
    {
        $v = $this->input($request, ['password' => 'nullable|string|max:200']);
        $owner = AppUser::query()->where('user_role', AppRole::OWNER)->orderBy('created_at')->first();
        if ($owner === null) {
            throw ApiException::notFound('There is no owner yet');
        }

        return response()->json($this->vendor->resetPassword($owner->id, $v['password'] ?? null));
    }

    public function users(): JsonResponse
    {
        return response()->json($this->vendor->users());
    }

    public function createUser(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'id' => 'required|string|max:64',
            'fullName' => 'required|string|max:200',
            'role' => ['required', 'string', 'max:20', Rule::in(RoleService::assignableKeys())],
            'password' => 'nullable|string|max:200',
        ]);

        return response()->json($this->vendor->createUser($v['id'], $v['fullName'], $v['role'], $v['password'] ?? null));
    }

    public function resetUserPassword(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, ['password' => 'nullable|string|max:200']);

        return response()->json($this->vendor->resetPassword($id, $v['password'] ?? null));
    }

    public function setActive(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, ['active' => 'required|boolean']);

        return response()->json($this->vendor->setActive($id, (bool) $v['active']));
    }

    // ---- starting settings

    public function settings(): JsonResponse
    {
        return response()->json($this->vendor->settings());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'projectName' => 'nullable|string|max:200',
            'language' => 'nullable|in:fa,en',
            'maxActiveTasksPerUser' => 'nullable|integer|min:1|max:50',
            'requireCompletionApproval' => 'nullable|boolean',
            'messengerPlatform' => 'nullable|in:IN_APP,BALE',
        ]);

        return response()->json($this->vendor->saveSettings($v));
    }
}
