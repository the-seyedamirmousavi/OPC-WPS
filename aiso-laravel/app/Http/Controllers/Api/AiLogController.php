<?php

namespace App\Http\Controllers\Api;

use App\AI\AgentOrchestrator;
use App\AI\Dialogue\DialogueEngine;
use App\AI\SensitiveDataFilter;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\AiRunStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's view of the assistant: every question with the exact messages it was given and what it answered, step by
 * step. Read only. Values are passed through the same sensitive-data filter the assistant's own answers use.
 */
class AiLogController extends Controller
{
    /** The newest runs, without their steps. */
    public function runs(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        $rows = AiRun::query()->with('user:id,full_name')->orderByDesc('id')->limit($limit)->get();

        return response()->json($rows->map(fn (AiRun $r) => [
            'id' => $r->id,
            'createdAt' => $r->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'user' => $r->user_id,
            'userName' => $r->user?->full_name,
            'question' => SensitiveDataFilter::scrub((string) $r->question),
            'status' => $r->status,
            'rounds' => (int) $r->rounds,
            'tokensIn' => (int) $r->tokens_in,
            'tokensOut' => (int) $r->tokens_out,
            'durationMs' => (int) $r->duration_ms,
            'error' => $r->error === null ? null : SensitiveDataFilter::scrub($r->error),
        ])->values());
    }

    /** One run with each step: the message sent (`input`), the answer (`output`), and any error. */
    public function run(int $id): JsonResponse
    {
        $run = AiRun::query()->with('user:id,full_name')->find($id);
        if ($run === null) {
            throw ApiException::notFound("Run {$id} not found");
        }
        $steps = AiRunStep::query()->where('run_id', $id)->orderBy('id')->get();

        return response()->json([
            'id' => $run->id,
            'createdAt' => $run->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'user' => $run->user_id,
            'userName' => $run->user?->full_name,
            'question' => SensitiveDataFilter::scrub((string) $run->question),
            'status' => $run->status,
            'rounds' => (int) $run->rounds,
            'tokensIn' => (int) $run->tokens_in,
            'tokensOut' => (int) $run->tokens_out,
            'durationMs' => (int) $run->duration_ms,
            'error' => $run->error === null ? null : SensitiveDataFilter::scrub($run->error),
            'steps' => $steps->map(fn (AiRunStep $s) => [
                'id' => $s->id,
                'stage' => $s->stage,
                'department' => $s->department,
                'input' => $s->input === null ? null : SensitiveDataFilter::scrub($s->input),
                'output' => $s->output === null ? null : SensitiveDataFilter::scrub($s->output),
                'tokensIn' => (int) $s->tokens_in,
                'tokensOut' => (int) $s->tokens_out,
                'durationMs' => (int) $s->duration_ms,
                'error' => $s->error === null ? null : SensitiveDataFilter::scrub($s->error),
                'createdAt' => $s->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ])->values(),
        ]);
    }

    /** The fixed instructions every message starts with (the same text the assistant receives). */
    public function prompt(): JsonResponse
    {
        $dialogue = config('aiso.agent.engine') === 'dialogue';

        return response()->json([
            'text' => $dialogue ? DialogueEngine::systemPrompt() : AgentOrchestrator::systemPrompt(),
            'engine' => $dialogue ? 'dialogue' : 'legacy',
            'note' => $dialogue
                ? 'Each message to the assistant has these instructions first, then a small JSON: the message, the dialogue state, the rights, the tool list and the results read so far.'
                : 'Each message to the assistant has these instructions first, then its own JSON (question, departments, briefing, data).',
        ]);
    }
}
