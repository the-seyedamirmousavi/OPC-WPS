<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Writes append-only audit events (event id, timestamp, actor, action, entity, previous/new value, reason, project).
 */
class AuditService
{
    public function __construct(private readonly LanguageService $lang)
    {
    }

    public function record(?string $actorId, string $action, string $entityType, ?string $entityId,
                           mixed $previous, mixed $next, ?string $reason): void
    {
        AuditEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'occurred_at' => CarbonImmutable::now(),
            'actor_id' => $actorId,
            'action_name' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'previous_value' => self::clip($previous, 4000),
            'new_value' => self::clip($next, 4000),
            'reason' => self::clip($reason === null ? null : $this->lang->forStorage($reason), 1000),
            'project_id' => SystemSetting::current()->project_id,
        ]);
    }

    private static function clip(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $s = (string) $value;

        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 3) . '...';
    }
}
