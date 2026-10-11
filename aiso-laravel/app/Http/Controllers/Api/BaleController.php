<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\BaleLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's own Bale connection. The chat id is never returned; only whether one is connected. */
class BaleController extends Controller
{
    public function __construct(private readonly BaleLinkService $links)
    {
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'connected' => $this->links->isConnected($this->actor($request)),
            'platform' => SystemSetting::current()->messenger_platform,
        ]);
    }

    public function linkCode(Request $request): JsonResponse
    {
        $code = $this->links->createLinkCode($this->actor($request));

        return response()->json(['code' => $code, 'expiresInMinutes' => BaleLinkService::CODE_MINUTES]);
    }

    public function unlink(Request $request): ?JsonResponse
    {
        $this->links->unlink($this->actor($request));

        return null;
    }
}
