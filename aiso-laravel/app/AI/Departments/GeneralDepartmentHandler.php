<?php

namespace App\AI\Departments;

use App\Enums\SuggestionStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentSuggestion;
use App\Services\DashboardService;
use App\Services\DepartmentService;

/**
 * Any other department: its people, the resources it keeps up to date and its suggestions. It reads through
 * DepartmentService, so the user's department permissions apply exactly as they do in the panel.
 */
class GeneralDepartmentHandler extends BaseHandler
{
    public function __construct(private readonly DepartmentService $departments)
    {
    }

    protected function capability(): ?string
    {
        return null;
    }

    protected function read(string $intent, array $params, AppUser $user, Department $department): array
    {
        // the audit log is not about one department: owner only (the intent's own capability), and it does not need the panel
        if ($intent === 'audit_recent') {
            return $this->auditRecent($params);
        }
        // detail() refuses a user who may not open the department
        $panel = $this->departments->detail($department->id, $user);
        $limit = self::limit($params);

        return match ($intent) {
            'overview' => ['data' => [
                'name' => $panel['name'],
                'members' => $panel['memberCount'],
                'resources' => $panel['resourceCount'],
                'stale_resources' => $panel['staleResourceCount'],
                'pending_suggestions' => $panel['pendingSuggestions'],
                'review_days' => $panel['reviewDays'],
                'people' => array_map(fn (array $m) => ['name' => $m['fullName'], 'role' => $m['role']], array_slice($panel['members'], 0, 50)),
            ]],
            'list_resources' => self::cut(array_map(fn (array $r) => [
                'id' => $r['id'], 'name' => $r['name'], 'capacity' => $r['capacity'], 'status' => $r['status'],
                'confirmed_at' => $r['reviewedAt'], 'stale' => $r['stale'],
            ], $panel['resources']), $limit),
            'search' => self::search((string) $params['query'], [
                ...array_map(fn (array $r) => ['id' => $r['id'], 'text' => "{$r['id']} {$r['name']} {$r['resourceType']}",
                    'row' => ['type' => 'resource', 'id' => $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'stale' => $r['stale']]], $panel['resources']),
                ...array_map(fn (array $s) => ['id' => '', 'text' => "{$s['title']} {$s['body']}",
                    'row' => ['type' => 'suggestion', 'id' => (string) $s['id'], 'title' => $s['title'], 'status' => $s['status']]], $panel['suggestions']),
            ], $limit),
            'list_suggestions' => self::cut(array_map(fn (array $s) => [
                'id' => $s['id'], 'title' => $s['title'], 'status' => $s['status'], 'author' => $s['authorName'],
                'created_at' => $s['createdAt'], 'manager_note' => $s['managerNote'],
            ], array_values(array_filter($panel['suggestions'], fn (array $s) => empty($params['status']) || $s['status'] === $params['status']))), $limit),
        };
    }

    /** The newest audit events, optionally about one entity or one actor. The same view as the audit screen. */
    private function auditRecent(array $params): array
    {
        $limit = self::limit($params);
        $q = AuditEvent::query()->orderByDesc('occurred_at')->limit($limit + 1);
        if (!empty($params['entity_type'])) {
            $q->where('entity_type', $params['entity_type']);
        }
        if (!empty($params['entity_id'])) {
            $q->where('entity_id', $params['entity_id']);
        }
        if (!empty($params['actor_id'])) {
            $q->where('actor_id', $params['actor_id']);
        }

        return self::cut($q->get()->map(fn (AuditEvent $e) => DashboardService::auditView($e)), $limit);
    }

    /** Every one goes through DepartmentService, which checks the department permissions and that the item is the department's. */
    protected function run(string $operation, array $params, AppUser $user, Department $department): array
    {
        return match ($operation) {
            'confirm_resource', 'update_resource' => $this->resource($operation, $params, $user, $department),
            'submit_suggestion' => $this->suggest($params, $user, $department),
            'decide_suggestion' => $this->decide($params, $user, $department),
            default => throw ApiException::badRequest("Operation {$operation} is not available here"),
        };
    }

    private function resource(string $operation, array $params, AppUser $user, Department $department): array
    {
        // an empty change only confirms that the resource is up to date
        $fields = $operation === 'confirm_resource' ? [] : array_intersect_key($params, array_flip(['name', 'capacity', 'status', 'description']));
        $r = $this->departments->updateResource($department->id, (string) $params['resource_id'], $fields, $user);

        return ['resource_id' => $r['id'], 'confirmed_at' => $r['reviewedAt']];
    }

    private function suggest(array $params, AppUser $user, Department $department): array
    {
        $s = $this->departments->submitSuggestion($department->id, $params['title'], $params['body'], $params['resource_id'] ?? null, $user);

        return ['suggestion_id' => $s['id'], 'status' => $s['status']];
    }

    private function decide(array $params, AppUser $user, Department $department): array
    {
        $s = DepartmentSuggestion::query()->find($params['suggestion_id']);
        if ($s === null || (int) $s->department_id !== (int) $department->id) {
            throw ApiException::notFound("Suggestion {$params['suggestion_id']} is not in department {$department->name}");
        }
        $done = $this->departments->decide((int) $params['suggestion_id'], SuggestionStatus::from($params['decision']), $params['note'] ?? null, $user);

        return ['suggestion_id' => $done['id'], 'status' => $done['status']];
    }
}
