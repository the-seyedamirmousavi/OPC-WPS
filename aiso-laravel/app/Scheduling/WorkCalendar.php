<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Working time. The planner counts hours of work; this turns them into dates and back, skipping nights, days off and
 * holidays: 8 hours of work started on Wednesday at 14:00 (08:00-16:00, Thursday 08:00-12:00) end on Thursday at 12:00.
 *
 * Configured in config aiso.calendar (AISO_WORK_CALENDAR). When it is off, time is continuous (24/7), as before.
 */
final class WorkCalendar
{
    /** No more than this many days are searched for working time (a calendar without working days is refused). */
    private const MAX_DAYS = 3660;

    /**
     * @param array<int, list<array{0: int, 1: int}>> $days day of week (0 = Sunday ... 6 = Saturday) => working intervals in minutes
     * @param array<string, true> $holidays dates (Y-m-d, local) without work
     */
    public function __construct(private readonly array $days, private readonly string $timezone, private readonly array $holidays = [])
    {
    }

    private static ?self $current = null;
    private static bool $loaded = false;

    /** The configured calendar, or null when working time is off (continuous time). */
    public static function current(): ?self
    {
        if (!self::$loaded) {
            self::$current = self::fromConfig((array) config('aiso.calendar'));
            self::$loaded = true;
        }

        return self::$current;
    }

    /** Forgets the cached calendar (after the configuration changed, e.g. in tests). */
    public static function reset(): void
    {
        self::$loaded = false;
        self::$current = null;
    }

    public static function fromConfig(array $c): ?self
    {
        if (empty($c['enabled'])) {
            return null;
        }
        $names = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $days = [];
        foreach ((array) ($c['days'] ?? []) as $name => $spans) {
            $dow = $names[strtolower((string) $name)] ?? null;
            if ($dow === null) {
                continue;
            }
            foreach ((array) $spans as $span) {
                [$from, $to] = array_map(static function (string $hm): int {
                    [$h, $m] = array_map('intval', explode(':', $hm) + [1 => 0]);

                    return $h * 60 + $m;
                }, [(string) $span[0], (string) $span[1]]);
                if ($to > $from) {
                    $days[$dow][] = [$from, $to];
                }
            }
        }
        if ($days === []) {
            return null;
        }
        foreach ($days as &$spans) {
            usort($spans, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        }
        unset($spans);

        return new self($days, (string) ($c['timezone'] ?? 'UTC'), array_fill_keys(array_map('strval', (array) ($c['holidays'] ?? [])), true));
    }

    /** The moment $hours of work after $from are done (0 hours: the first working moment at or after $from). */
    public function add(\DateTimeInterface $from, float $hours): CarbonImmutable
    {
        $t = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $left = max(0.0, $hours) * 60.0; // minutes
        $day = $t->startOfDay();
        for ($i = 0; $i < self::MAX_DAYS; $i++, $day = $day->addDay()) {
            foreach ($this->spans($day) as [$a, $b]) {
                $begin = max($t, $a);
                if ($begin >= $b) {
                    continue;
                }
                $avail = ($b->getTimestamp() - $begin->getTimestamp()) / 60.0;
                if ($left <= $avail + 1e-9) {
                    return $begin->addSeconds((int) round($left * 60))->utc();
                }
                $left -= $avail;
            }
        }

        return $t->addMinutes((int) round($left))->utc();
    }

    /** Hours of work between two moments (negative when $to is before $from). */
    public function between(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $a = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $b = CarbonImmutable::instance($to)->setTimezone($this->timezone);
        if ($b < $a) {
            return -$this->between($b, $a);
        }
        $minutes = 0.0;
        $day = $a->startOfDay();
        for ($i = 0; $i < self::MAX_DAYS && $day < $b; $i++, $day = $day->addDay()) {
            foreach ($this->spans($day) as [$s, $e]) {
                $lo = max($a, $s);
                $hi = min($b, $e);
                if ($hi > $lo) {
                    $minutes += ($hi->getTimestamp() - $lo->getTimestamp()) / 60.0;
                }
            }
        }

        return $minutes / 60.0;
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> the working intervals of one local day */
    private function spans(CarbonImmutable $day): array
    {
        if (isset($this->holidays[$day->format('Y-m-d')])) {
            return [];
        }
        $out = [];
        foreach ($this->days[$day->dayOfWeek] ?? [] as [$from, $to]) {
            $out[] = [$day->addMinutes($from), $day->addMinutes($to)];
        }

        return $out;
    }
}
