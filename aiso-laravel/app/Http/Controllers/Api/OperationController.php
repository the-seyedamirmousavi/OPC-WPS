<?php

namespace App\Http\Controllers\Api;

use App\Models\AppRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\OperationService;
use App\Services\OperationViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationController extends Controller
{
    public function __construct(
        private readonly OperationViewService $views,
        private readonly OperationService $ops,
    ) {
    }

    /** Management list with optional filters (status, resourceId, projectId, userId, free text q). */
    public function list(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'status' => 'nullable|string',
            'resourceId' => 'nullable|string',
            'projectId' => 'nullable|string',
            'userId' => 'nullable|string',
            'q' => 'nullable|string',
        ]);
        $needle = isset($v['q']) ? mb_strtolower($v['q']) : null;

        $rows = array_filter($this->views->all(), static function (array $row) use ($v, $needle): bool {
            return (!isset($v['status']) || $row['status'] === $v['status'])
                && (!isset($v['resourceId']) || $row['resourceId'] === $v['resourceId'])
                && (!isset($v['projectId']) || $row['projectId'] === $v['projectId'])
                && (!isset($v['userId']) || $row['assignedUserId'] === $v['userId'])
                && ($needle === null || str_contains(mb_strtolower($row['id']), $needle)
                    || str_contains(mb_strtolower($row['name']), $needle));
        });

        return response()->json(array_values($rows));
    }

    public function detail(Request $request, string $id): JsonResponse
    {
        $d = $this->views->detail($id);
        $me = $this->actor($request);
        if ($me->receivesTasks() && $me->id !== $d['operation']['assignedUserId']) {
            throw ApiException::forbidden('This operation is not assigned to you');
        }

        return response()->json($d);
    }

    public function myTasks(Request $request): JsonResponse
    {
        return response()->json($this->views->forUser($this->actor($request)->id));
    }

    // ---- executor actions ----------------------------------------------------------------------------------------

    public function start(Request $request, string $id): ?JsonResponse
    {
        $this->ops->start($id, $this->actor($request));

        return null;
    }

    public function progress(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['percent' => 'required|integer|min:0|max:100', 'note' => 'nullable|string']);
        $this->ops->progress($id, (int) $v['percent'], $v['note'] ?? null, $this->actor($request));

        return null;
    }

    public function complete(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['note' => 'nullable|string']);
        $this->ops->complete($id, $v['note'] ?? null, $this->actor($request));

        return null;
    }

    public function block(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['reason' => 'required|string']);
        $this->ops->block($id, $v['reason'], $this->actor($request));

        return null;
    }

    /** Any signed-in user may comment; executors only on their own operations (checked by the service). */
    public function comment(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['reason' => 'required|string']);
        $this->ops->comment($id, $v['reason'], $this->actor($request));

        return null;
    }

    // ---- manager actions -----------------------------------------------------------------------------------------

    public function unblock(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['note' => 'nullable|string']);
        $this->ops->unblock($id, $v['note'] ?? null, $this->actor($request));

        return null;
    }

    public function cancel(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['reason' => 'required|string']);
        $this->ops->cancel($id, $v['reason'], $this->actor($request));

        return null;
    }

    public function approve(Request $request, string $id): ?JsonResponse
    {
        $v = $this->input($request, ['note' => 'nullable|string']);
        $this->ops->approve($id, $v['note'] ?? null, $this->actor($request));

        return null;
    }

    public function unassign(Request $request, string $id): ?JsonResponse
    {
        $this->ops->unassign($id, $this->actor($request));

        return null;
    }
}
