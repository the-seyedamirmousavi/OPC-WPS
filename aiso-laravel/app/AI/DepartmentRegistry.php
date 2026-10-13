<?php

namespace App\AI;

use App\AI\Contracts\DepartmentHandler;
use App\Models\AppUser;
use App\Models\Department;
use App\Services\DepartmentService;

/**
 * The source of truth for what the AI agent may reach. A department is offered to a user only when the user may open it
 * (DepartmentService) and the user's role is allowed for its kind (config aiso.agent.kinds). Intents and operations are
 * read from the config only; anything else is refused.
 */
class DepartmentRegistry
{
    public function __construct(private readonly DepartmentService $departments)
    {
    }

    /** @return array<string, array{slug: string, name: string, summary: string, kind: string, department: Department}> keyed by slug */
    public function forUser(AppUser $user): array
    {
        $ids = array_column($this->departments->menu($user), 'id');
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Department::query()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->get() as $d) {
            $kind = self::kindOf($d);
            $config = $this->kind($kind);
            if (!$user->allows($config['capability'] ?? null) || !$d->isActive()) {
                continue;
            }
            $slug = self::slugOf($d);
            $out[$slug] = [
                'slug' => $slug,
                'name' => $d->name,
                'summary' => $kind === 'general' && $d->description ? "{$config['summary']}. {$d->description}" : $config['summary'],
                'kind' => $kind,
                'department' => $d,
            ];
        }

        return $out;
    }

    public function find(AppUser $user, string $slug): ?array
    {
        return $this->forUser($user)[$slug] ?? null;
    }

    /** @return array<string, mixed> */
    public function kind(string $kind): array
    {
        return config("aiso.agent.kinds.{$kind}");
    }

    public function handler(string $kind): DepartmentHandler
    {
        return app($this->kind($kind)['handler']);
    }

    public static function kindOf(Department $d): string
    {
        return $d->isHr() ? 'hr' : ($d->isProduction() ? 'production' : 'general');
    }

    public static function slugOf(Department $d): string
    {
        return $d->isHr() ? 'hr' : ($d->isProduction() ? 'production' : "dept-{$d->id}");
    }
}
