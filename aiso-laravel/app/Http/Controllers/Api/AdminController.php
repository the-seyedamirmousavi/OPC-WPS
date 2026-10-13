<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssignmentMode as Mode;
use App\Enums\SystemStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Imports\ImportService;
use App\Messaging\BaleClient;
use App\Messaging\BaleException;
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

    /**
     * The AI service token and model. The token is write-only: it is stored encrypted and never returned.
     * clearKey removes the stored token.
     */
    public function llm(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'apiKey' => 'nullable|string|max:500',
            'model' => 'nullable|string|max:100',
            'clearKey' => 'nullable|boolean',
        ]);

        // an empty model field arrives as null (empty strings are converted); a model key that is present means "reset"
        $model = $request->exists('model') ? (string) ($v['model'] ?? '') : null;

        return response()->json($this->admin->setLlm($v['apiKey'] ?? null, $model,
            (bool) ($v['clearKey'] ?? false), $this->actor($request)));
    }

    /**
     * The Bale bot token. Write-only: it is stored encrypted and never returned; clearToken removes the stored one (the
     * environment token, if any, applies again).
     */
    public function bale(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'token' => 'nullable|string|max:200',
            'clearToken' => 'nullable|boolean',
        ]);

        return response()->json($this->admin->setBale($v['token'] ?? null, (bool) ($v['clearToken'] ?? false), $this->actor($request)));
    }

    /** Asks Bale who the bot is (getMe) with the token in use, so the owner knows the connection works. The token is not returned. */
    public function baleCheck(): JsonResponse
    {
        try {
            $me = app(BaleClient::class)->getMe();
        } catch (BaleException $e) {
            throw new ApiException(502, $e->getMessage());
        }

        return response()->json(['username' => (string) ($me['username'] ?? ''), 'isBot' => (bool) ($me['is_bot'] ?? false)]);
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
