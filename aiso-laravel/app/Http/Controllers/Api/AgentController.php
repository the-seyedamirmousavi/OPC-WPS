<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AI agents in the Agents tab of human resources. Owner only; managers make agents through the assistant. */
class AgentController extends Controller
{
    public function __construct(private readonly AgentService $agents)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->agents->list($this->actor($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'required|string|max:100',
            'instructions' => 'required|string|max:1000',
            'scope' => 'nullable|array',
            'scope.*' => 'string|max:40',
        ]);

        return response()->json($this->agents->create($this->actor($request), $v['name'], $v['instructions'], $v['scope'] ?? null));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'nullable|string|max:100',
            'instructions' => 'nullable|string|max:1000',
            'scope' => 'nullable|array',
            'scope.*' => 'string|max:40',
            'active' => 'nullable|boolean',
        ]);

        return response()->json($this->agents->update($this->actor($request), $id, $v['name'] ?? null, $v['instructions'] ?? null,
            // an empty list is a real answer (no department), so it is taken as sent
            $request->has('scope') ? ($v['scope'] ?? []) : null, $v['active'] ?? null));
    }

    public function tasks(Request $request, string $id): JsonResponse
    {
        return response()->json($this->agents->tasks($this->actor($request), $id));
    }

    public function giveTask(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, ['task' => 'required|string|max:1000']);

        return response()->json($this->agents->assignTask($this->actor($request), $id, $v['task']));
    }
}
