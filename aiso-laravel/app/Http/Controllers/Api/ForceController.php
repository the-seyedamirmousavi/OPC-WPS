<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ForceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Manager FORCEs on the schedule: list, preview, set/replace, remove, and re-optimise from scratch. */
class ForceController extends Controller
{
    public function __construct(private readonly ForceService $forces)
    {
    }

    public function list(): JsonResponse
    {
        return response()->json($this->forces->board());
    }

    /** What the FORCE would change; nothing is stored. */
    public function preview(Request $request, string $operationId): JsonResponse
    {
        $v = $this->forceInput($request);

        return response()->json($this->forces->preview($operationId, $v['start'], $v['userId'], $v['reason']));
    }

    /** Sets or replaces the FORCE on the operation; 409 with the reason when it is impossible. */
    public function apply(Request $request, string $operationId): JsonResponse
    {
        $v = $this->forceInput($request);

        return response()->json($this->forces->apply($operationId, $v['start'], $v['userId'], $v['reason'], $this->actor($request)));
    }

    public function remove(Request $request, string $operationId): JsonResponse
    {
        return response()->json($this->forces->remove($operationId, $this->actor($request)));
    }

    /** Re-plans from scratch (FORCEs kept, no stability preference) and makes that the new reference plan. */
    public function reoptimize(Request $request): JsonResponse
    {
        return response()->json($this->forces->reoptimize($this->actor($request)));
    }

    /** @return array{start: ?string, userId: ?string, reason: ?string} */
    private function forceInput(Request $request): array
    {
        $v = $this->input($request, ['start' => 'nullable|string', 'userId' => 'nullable|string', 'reason' => 'nullable|string']);

        return ['start' => $v['start'] ?? null, 'userId' => $v['userId'] ?? null, 'reason' => $v['reason'] ?? null];
    }
}
