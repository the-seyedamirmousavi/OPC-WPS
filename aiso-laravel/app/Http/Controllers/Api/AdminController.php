<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssignmentMode as Mode;
use App\Enums\SystemStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Imports\ImportService;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly ImportService $importer,
    ) {
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->admin->view());
    }

    public function update(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'projectName' => 'nullable|string',
            'messengerPlatform' => 'nullable|string',
            'maxActiveTasksPerUser' => 'nullable|integer',
            'requireCompletionApproval' => 'nullable|boolean',
            'language' => 'nullable|string',
        ]);

        return response()->json($this->admin->update($v['projectName'] ?? null, $v['messengerPlatform'] ?? null,
            isset($v['maxActiveTasksPerUser']) ? (int) $v['maxActiveTasksPerUser'] : null,
            $v['requireCompletionApproval'] ?? null, $v['language'] ?? null, $this->actor($request)));
    }

    /** Switches between the algorithm and the LLM for task assignment. */
    public function assignmentMode(Request $request): JsonResponse
    {
        $v = $this->input($request, ['mode' => ['required', Rule::in(array_column(Mode::cases(), 'value'))]]);

        return response()->json($this->admin->setAssignmentMode(Mode::from($v['mode']), $this->actor($request)));
    }

    public function systemStatus(Request $request): JsonResponse
    {
        $v = $this->input($request, ['status' => ['required', Rule::in(array_column(SystemStatus::cases(), 'value'))],
            'reason' => 'nullable|string']);

        return response()->json($this->admin->setSystemStatus(SystemStatus::from($v['status']), $v['reason'] ?? null,
            $this->actor($request)));
    }

    public function reset(Request $request): ?JsonResponse
    {
        $v = $this->input($request, ['reason' => 'nullable|string', 'confirm' => 'nullable|string']);
        if (($v['confirm'] ?? null) !== 'RESET') {
            throw ApiException::badRequest('Type RESET in the confirm field to proceed');
        }
        $this->admin->reset($v['reason'] ?? null, $this->actor($request));

        return null;
    }

    /** Loads the bundled sample (the simple Excel layout) so the system can be tried immediately. */
    public function demoData(Request $request): JsonResponse
    {
        return response()->json($this->importer->processSample(true, $this->actor($request)));
    }
}
