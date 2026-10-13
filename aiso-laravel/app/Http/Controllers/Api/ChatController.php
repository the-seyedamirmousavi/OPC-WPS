<?php

namespace App\Http\Controllers\Api;

use App\AI\ActionExecutor;
use App\Http\Controllers\Controller;
use App\Services\ChatImportService;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat, private readonly ActionExecutor $actions)
    {
    }

    /** One turn: the new message; the server keeps the history. A new conversation when no id is sent. */
    public function send(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'conversationId' => 'nullable|integer',
            'message' => 'required|string|max:' . ChatService::MAX_CHARS,
            // optional: the client's id for this message, so a retry is answered from what was stored
            'requestId' => 'nullable|string|max:64',
        ]);

        return response()->json($this->chat->send($this->actor($request), $v['conversationId'] ?? null, $v['message'], $v['requestId'] ?? null));
    }

    /**
     * An Excel file sent to the assistant (multipart: file, optional conversationId and projectId). The answer is a preview
     * and, when something can be imported, an import proposal; nothing is saved to the plan before it is confirmed.
     */
    public function upload(Request $request): JsonResponse
    {
        $conversation = $request->input('conversationId');
        $project = trim((string) $request->input('projectId', ''));

        return response()->json(app(ChatImportService::class)->upload($this->actor($request), $request->file('file'),
            $conversation === null || $conversation === '' ? null : (int) $conversation, $project === '' ? null : $project));
    }

    /** The user confirms an action the assistant proposed. Only the user it was proposed to can. */
    public function confirmAction(Request $request, string $id): JsonResponse
    {
        return response()->json($this->actions->confirm($this->actor($request), (int) $id));
    }

    public function cancelAction(Request $request, string $id): JsonResponse
    {
        return response()->json($this->actions->cancel($this->actor($request), (int) $id));
    }

    public function conversations(Request $request): JsonResponse
    {
        return response()->json($this->chat->conversations($this->actor($request)));
    }

    public function conversation(Request $request, string $id): JsonResponse
    {
        return response()->json($this->chat->conversation($this->actor($request), (int) $id));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->chat->delete($this->actor($request), (int) $id);

        return response()->json(null, 204);
    }
}
