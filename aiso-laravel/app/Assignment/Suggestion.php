<?php

namespace App\Assignment;

/**
 * Output of an assignment strategy: proposed picks, operations left unassigned and why.
 *
 * @param Pick[]   $picks
 * @param Skip[]   $skips
 * @param string[] $warnings
 */
final class Suggestion
{
    public function __construct(
        public readonly array $picks,
        public readonly array $skips,
        public readonly string $summary,
        public readonly array $warnings = [],
        public readonly ?string $model = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {
    }

    public static function of(array $picks, array $skips, string $summary): self
    {
        return new self($picks, $skips, $summary);
    }
}
