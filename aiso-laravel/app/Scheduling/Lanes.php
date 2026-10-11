<?php

namespace App\Scheduling;

/**
 * Busy intervals per resource lane. Value semantics (PHP arrays are copied on write), so alternative placements can be
 * tried and discarded.
 */
final class Lanes
{
    /** @var array<string, array<int, list<array{0: float, 1: float}>>> */
    private array $busy = [];

    /** @param array<string, int> $capacities */
    public function __construct(private readonly array $capacities)
    {
    }

    public function copy(): self
    {
        $c = new self($this->capacities);
        $c->busy = $this->busy;

        return $c;
    }

    private function ensure(string $resourceId): void
    {
        if (!isset($this->busy[$resourceId])) {
            $n = max(1, (int) ($this->capacities[$resourceId] ?? 1));
            $this->busy[$resourceId] = array_fill(0, $n, []);
        }
    }

    /** @return array<int, list<array{0: float, 1: float}>> */
    public function lanes(string $resourceId): array
    {
        $this->ensure($resourceId);

        return $this->busy[$resourceId];
    }

    public function addInterval(string $resourceId, int $lane, float $start, float $end): void
    {
        $this->ensure($resourceId);
        $this->busy[$resourceId][$lane][] = [$start, $end];
        usort($this->busy[$resourceId][$lane], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    }

    /** @return array<string, array<int, list<array{0: float, 1: float}>>> */
    public function all(): array
    {
        return $this->busy;
    }
}
