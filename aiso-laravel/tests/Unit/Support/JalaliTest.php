<?php

namespace Tests\Unit\Support;

use App\Support\Jalali;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Port of JalaliTest.java. */
class JalaliTest extends TestCase
{
    public function test_known_dates_convert_both_ways(): void
    {
        // Nowruz 1404 = 21 March 2025; the app showed 12 Mehr 1405 on 4 October 2026
        $this->assertSame([1404, 1, 1], Jalali::fromGregorian(2025, 3, 21));
        $this->assertSame([1405, 7, 12], Jalali::fromGregorian(2026, 10, 4));
        $this->assertSame([1403, 1, 1], Jalali::fromGregorian(2024, 3, 20));
        $this->assertSame('2026-10-04', Jalali::toGregorian(1405, 7, 12)->format('Y-m-d'));
        $this->assertSame('2025-03-20', Jalali::toGregorian(1403, 12, 30)->format('Y-m-d')); // 1403 is a leap year
    }

    public function test_round_trip_over_several_years(): void
    {
        for ($d = CarbonImmutable::parse('2020-01-01', 'UTC'); $d->lessThan('2032-01-01'); $d = $d->addDay()) {
            [$y, $m, $day] = Jalali::fromGregorian((int) $d->year, (int) $d->month, (int) $d->day);
            $this->assertSame($d->format('Y-m-d'), Jalali::toGregorian($y, $m, $day)->format('Y-m-d'));
        }
    }

    public function test_formats_with_persian_digits_in_tehran_time(): void
    {
        $i = CarbonImmutable::parse('2026-10-04T05:41:00Z'); // 09:11 in Tehran (+03:30)
        $this->assertSame('۱۴۰۵/۰۷/۱۲ ۰۹:۱۱', Jalali::dateTime($i));
        $this->assertSame('۱۴۰۵/۰۷/۱۲', Jalali::date($i));
        $this->assertSame('۱۲ مهر ۱۴۰۵', Jalali::longDate($i));
        $this->assertSame('', Jalali::date(null));
    }

    public function test_parses_jalali_input_with_persian_or_latin_digits(): void
    {
        $this->assertSame('2026-10-04', Jalali::parseDate('1405/07/12')?->format('Y-m-d'));
        $this->assertSame('2026-10-04', Jalali::parseDate('۱۴۰۵/۰۷/۱۲')?->format('Y-m-d'));
        $this->assertSame('2026-10-04', Jalali::parseDate('1405-7-12')?->format('Y-m-d'));
        $this->assertNull(Jalali::parseDate('2026-10-04'));
        $this->assertNull(Jalali::parseDate('1405/13/01'));
        $this->assertNull(Jalali::parseDate('abc'));
    }
}
