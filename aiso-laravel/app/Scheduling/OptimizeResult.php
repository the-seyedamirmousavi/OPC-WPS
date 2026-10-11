<?php

namespace App\Scheduling;

/**
 * @param array<string, string> $rules        which rule produced each project's placement
 * @param Conflict[]            $conflicts    FORCEs that are not honoured
 * @param array<string, true>   $honoured     FORCEd operations placed exactly at their forced start
 * @param array<string, true>   $escalated    operations placed ahead of project priority so that a FORCE could be met
 */
final class OptimizeResult
{
    public function __construct(
        public readonly Plan $plan,
        public readonly array $rules,
        public readonly int $candidates,
        public readonly array $conflicts,
        public readonly array $honoured,
        public readonly array $escalated,
    ) {
    }
}
