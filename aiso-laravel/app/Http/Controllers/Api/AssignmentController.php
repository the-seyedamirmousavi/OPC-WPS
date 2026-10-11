<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssignmentMode;
use App\Http\Controllers\Controller;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignments)
    {
    }

    /** The mode is optional: when omitted the mode configured in Settings is used. */
    public function suggest(Request $request): JsonResponse
    {
        $v = $this->input($request, ['mode' => ['nullable', Rule::in(array_column(AssignmentMode::cases(), 'value'))]]);
        $mode = isset($v['mode']) ? AssignmentMode::from($v['mode']) : null;

        return response()->json($this->assignments->suggest($mode, $this->actor($request)));
    }

    public function latest(): JsonResponse
    {
        return response()->json($this->assignments->latest());
    }

    public function runs(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);

        return response()->json($this->assignments->history($limit));
    }

    public function approve(Request $request): JsonResponse
    {
        $v = $this->input($request, ['proposalIds' => 'required|array|min:1', 'proposalIds.*' => 'integer']);

        return response()->json($this->assignments->approve(array_map('intval', $v['proposalIds']), $this->actor($request)));
    }

    public function reject(Request $request): JsonResponse
    {
        $v = $this->input($request, ['proposalIds' => 'required|array|min:1', 'proposalIds.*' => 'integer', 'note' => 'nullable|string']);

        return response()->json($this->assignments->reject(array_map('intval', $v['proposalIds']), $v['note'] ?? null,
            $this->actor($request)));
    }

    /** Manager override: assign a specific user regardless of what was proposed. */
    public function manual(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['userId' => 'required|string', 'note' => 'nullable|string']);
        $this->assignments->manualAssign($id, $v['userId'], $v['note'] ?? null, $this->actor($request));

        return null;
    }
}
