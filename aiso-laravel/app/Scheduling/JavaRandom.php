<?php

namespace App\Scheduling;

/**
 * Bit-exact port of java.util.Random (the 48-bit LCG), so the randomised dispatching rule produces the same plan as
 * the Spring backend for the same input.
 */
final class JavaRandom
{
    private const MULTIPLIER = 0x5DEECE66D;
    private const MASK = (1 << 48) - 1;

    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = ($seed ^ self::MULTIPLIER) & self::MASK;
    }

    /** seed * MULTIPLIER + 0xB, mod 2^48, without overflowing 64-bit integers. */
    private function step(): void
    {
        $lo = $this->seed & 0xFFFFFF;
        $hi = ($this->seed >> 24) & 0xFFFFFF;
        $low = $lo * self::MULTIPLIER;                                  // < 2^59
        $high = (($hi * self::MULTIPLIER) & 0xFFFFFF) << 24;            // < 2^48
        $this->seed = (($low + $high + 0xB) & self::MASK);
    }

    private function next(int $bits): int
    {
        $this->step();

        return $this->seed >> (48 - $bits);
    }

    /** Uniform double in [0, 1), identical to java.util.Random#nextDouble. */
    public function nextDouble(): float
    {
        return ((($this->next(26)) << 27) + $this->next(27)) * (1.0 / (1 << 53));
    }
}
