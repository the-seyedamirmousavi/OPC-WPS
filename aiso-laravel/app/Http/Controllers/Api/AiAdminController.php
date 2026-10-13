<?php

namespace App\Http\Controllers\Api;

use App\AI\ActionExecutor;
use App\AI\ActionPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What the owner lets the assistant do, and the requests that wait for the owner's approval. Owner only. */
class AiAdminController extends Controller
{
    public function __construct(private readonly ActionExecutor $actions, private readonly ActionPolicy $policy)
    {
    }

    public function approvals(): JsonResponse
    {
        return response()->json($this->actions->awaitingOwner());
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, ['note' => 'nullable|string|max:500']);

        return response()->json($this->actions->ownerDecide($this->actor($request), (int) $id, true, $v['note'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, ['note' => 'nullable|string|max:500']);

        return response()->json($this->actions->ownerDecide($this->actor($request), (int) $id, false, $v['note'] ?? null));
    }

    public function policies(): JsonResponse
    {
        return response()->json($this->policy->catalogue());
    }

    public function savePolicies(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'writesEnabled' => 'required|boolean',
            'actions' => 'present|array',
            'actions.*.kind' => 'required|string|max:20',
            'actions.*.action' => 'required|string|max:60',
            'actions.*.mode' => ['required', Rule::in([ActionPolicy::DENY, ActionPolicy::AUTO, ActionPolicy::SELF, ActionPolicy::OWNER])],
        ]);

        return response()->json($this->policy->save($this->actor($request), (bool) $v['writesEnabled'], $v['actions']));
    }
}
