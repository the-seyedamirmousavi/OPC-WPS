<?php

namespace App\AI;

use App\Exceptions\ApiException;
use App\Models\AiActionPolicy;
use App\Models\AppUser;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Carbon\CarbonImmutable;

/**
 * What the owner has allowed the assistant to do. Every action has one mode:
 *  - DENY:  never (the assistant is not even told about it)
 *  - AUTO:  runs at once (only for low-risk actions)
 *  - SELF:  the person who asked confirms it
 *  - OWNER: the owner must approve it first (when the owner asks, the owner's own confirmation is the approval)
 * A master switch turns every write off: the assistant then only reads. Without a choice, the mode follows the risk.
 */
class ActionPolicy
{
    public const DENY = 'DENY';
    public const AUTO = 'AUTO';
    public const SELF = 'SELF';
    public const OWNER = 'OWNER';

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function writesEnabled(): bool
    {
        return (bool) SystemSetting::current()->ai_writes_enabled;
    }

    /** The mode of one action now. */
    public function modeFor(string $kind, string $action, string $risk): string
    {
        if (!$this->writesEnabled()) {
            return self::DENY;
        }
        $chosen = AiActionPolicy::query()->where('kind', $kind)->where('action', $action)->value('mode');
        if (is_string($chosen) && in_array($chosen, [self::DENY, self::AUTO, self::SELF, self::OWNER], true)
            && ($chosen !== self::AUTO || $risk === 'low')) {
            return $chosen;
        }

        return self::defaultFor($risk);
    }

    public static function defaultFor(string $risk): string
    {
        return match ($risk) {
            'low' => self::AUTO,
            'medium' => self::SELF,
            default => self::OWNER,
        };
    }

    /**
     * Every action the config offers, with its current mode, for the owner's screen.
     *
     * @return array{writesEnabled: bool, actions: array<int, array{kind: string, action: string, risk: string, mode: string, default: string}>}
     */
    public function catalogue(): array
    {
        $chosen = AiActionPolicy::query()->get()->mapWithKeys(fn (AiActionPolicy $p) => ["{$p->kind}|{$p->action}" => $p->mode])->all();
        $rows = [];
        foreach (config('aiso.agent.kinds') as $kind => $spec) {
            foreach ($spec['actions'] as $action => $a) {
                $mode = $chosen["{$kind}|{$action}"] ?? null;
                $rows[] = [
                    'kind' => $kind, 'action' => $action, 'risk' => $a['risk'],
                    'mode' => $mode !== null && ($mode !== self::AUTO || $a['risk'] === 'low') ? $mode : self::defaultFor($a['risk']),
                    'default' => self::defaultFor($a['risk']),
                ];
            }
        }

        return ['writesEnabled' => $this->writesEnabled(), 'actions' => $rows];
    }

    /**
     * Saves the owner's choices.
     *
     * @param array<int, array{kind: string, action: string, mode: string}> $modes
     */
    public function save(AppUser $owner, bool $writesEnabled, array $modes): array
    {
        foreach ($modes as $m) {
            $spec = config("aiso.agent.kinds.{$m['kind']}.actions.{$m['action']}");
            if ($spec === null) {
                throw ApiException::badRequest("Unknown action {$m['kind']}.{$m['action']}");
            }
            if ($m['mode'] === self::AUTO && $spec['risk'] !== 'low') {
                throw ApiException::badRequest('Only a low-risk action can run without confirmation');
            }
        }

        $before = $this->summary();
        SystemSetting::current()->update(['ai_writes_enabled' => $writesEnabled, 'updated_at' => CarbonImmutable::now()]);
        foreach ($modes as $m) {
            AiActionPolicy::query()->updateOrCreate(['kind' => $m['kind'], 'action' => $m['action']],
                ['mode' => $m['mode'], 'updated_at' => CarbonImmutable::now()]);
        }
        $this->audit->record($owner->id, 'AI_POLICY_UPDATED', 'AiPolicy', null, $before, $this->summary(), null);

        return $this->catalogue();
    }

    private function summary(): string
    {
        $c = $this->catalogue();

        return ($c['writesEnabled'] ? 'writes on' : 'writes off') . '; ' . implode(', ', array_map(
            static fn (array $a) => "{$a['kind']}.{$a['action']}={$a['mode']}", $c['actions']));
    }
}
