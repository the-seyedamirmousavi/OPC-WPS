<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Services\ProjectService;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects scheduled together on shared resources. A project is created by importing a file (POST /api/import); here
 * they are ranked, previewed, renamed, given due dates and archived.
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly SchedulingService $scheduling,
    ) {
    }

    public function list(): JsonResponse
    {
        return response()->json($this->projects->list());
    }

    /** Applies a new priority order of the active projects. The schedule is recomputed from it immediately. */
    public function priorities(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'ranking' => 'required|array|min:1',
            'ranking.*' => 'string',
            'dueDates' => 'nullable|array',
        ]);

        return response()->json($this->projects->setPriorities($v['ranking'], $v['dueDates'] ?? null, $this->actor($request)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'nullable|string',
            'dueDate' => 'nullable|string',
            'clearDueDate' => 'nullable|boolean',
            'status' => ['nullable', Rule::in(array_column(ProjectStatus::cases(), 'value'))],
        ]);

        return response()->json($this->projects->update($id, $v['name'] ?? null, $v['dueDate'] ?? null,
            (bool) ($v['clearDueDate'] ?? false), isset($v['status']) ? ProjectStatus::from($v['status']) : null,
            $this->actor($request)));
    }

    /** What would change if the active projects were ranked this way? Nothing is stored. */
    public function preview(Request $request): JsonResponse
    {
        $v = $this->input($request, ['ranking' => 'required|array|min:1', 'ranking.*' => 'string']);

        return response()->json($this->scheduling->previewRanking($v['ranking']));
    }

    /** Current optimised schedule metrics next to the naive baseline. */
    public function overview(): JsonResponse
    {
        return response()->json($this->scheduling->overview());
    }
}
