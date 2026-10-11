<?php

namespace App\Http\Controllers\Api;

use App\Exports\ExcelService;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Notification;
use App\Services\DashboardService;
use App\Services\LanguageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Dashboards, the performance report, the audit log and the in-app inbox. */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly ExcelService $excel,
        private readonly LanguageService $language,
    ) {
    }

    public function manager(): JsonResponse
    {
        return response()->json($this->dashboards->manager());
    }

    public function owner(): JsonResponse
    {
        return response()->json($this->dashboards->owner());
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($this->dashboards->user($this->actor($request)->id));
    }

    public function userPerformance(): JsonResponse
    {
        return response()->json($this->dashboards->performanceAll());
    }

    public function export(Request $request): Response
    {
        $lang = $request->query('lang');
        $fa = $lang === null ? $this->language->requestIsPersian() : strcasecmp((string) $lang, 'fa') === 0;

        return ImportController::download($this->excel->report($fa), $fa ? 'AISO-گزارش.xlsx' : 'AISO-report.xlsx');
    }

    public function audit(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 500);
        $query = AuditEvent::query()->orderByDesc('occurred_at')->limit($limit);
        if ($request->query('entityType') !== null && $request->query('entityId') !== null) {
            $query->where('entity_type', $request->query('entityType'))->where('entity_id', $request->query('entityId'));
        } elseif ($request->query('actorId') !== null) {
            $query->where('actor_id', $request->query('actorId'));
        }

        return response()->json($query->get()->map(fn (AuditEvent $e) => DashboardService::auditView($e))->values());
    }

    // ---- notifications (own inbox) -------------------------------------------------------------------------------

    public function myNotifications(Request $request): JsonResponse
    {
        $rows = Notification::query()->where('recipient_id', $this->actor($request)->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();

        return response()->json($rows->map(fn (Notification $n) => DashboardService::notificationView($n))->values());
    }

    public function readAll(Request $request): ?JsonResponse
    {
        Notification::query()->where('recipient_id', $this->actor($request)->id)->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now()]);

        return null;
    }

    public function read(Request $request, int $id): ?JsonResponse
    {
        Notification::query()->whereKey($id)->where('recipient_id', $this->actor($request)->id)
            ->update(['read_at' => CarbonImmutable::now()]);

        return null;
    }

    /** Messenger deliveries that failed - visible to the owner and manager. */
    public function failed(): JsonResponse
    {
        $rows = Notification::query()->where('delivery_status', 'FAILED')->orderByDesc('created_at')->limit(100)->get();

        return response()->json($rows->map(fn (Notification $n) => DashboardService::notificationView($n))->values());
    }
}
