<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Solar Hijri (Jalali / Persian) calendar helpers. The arithmetic conversion (valid for the years in use here) is
 * implemented directly. Dates are shown in Iran's time zone.
 */
final class Jalali
{
    public const TEHRAN = 'Asia/Tehran';

    private const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    private const G_D_M = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    /** @return array{0: int, 1: int, 2: int} year, month, day in the Jalali calendar */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400)
            + $gd + self::G_D_M[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);

        return [$jy, $jm, $jd];
    }

    /** The Gregorian date (midnight, Tehran) of a Jalali date. */
    public static function toGregorian(int $jy, int $jm, int $jd): CarbonImmutable
    {
        $y = $jy + 1595;
        $days = -355668 + 365 * $y + intdiv($y, 33) * 8 + intdiv(($y % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $sal = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        while ($gm < 13 && $gd > $sal[$gm]) {
            $gd -= $sal[$gm];
            $gm++;
        }

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, self::TEHRAN);
    }

    /** "1405/07/12" with Persian digits (date only). */
    public static function date(?\DateTimeInterface $t): string
    {
        return $t === null ? '' : self::digits(self::plain($t));
    }

    /** "1405/07/12 09:11" in Tehran time, Persian digits. */
    public static function dateTime(?\DateTimeInterface $t): string
    {
        if ($t === null) {
            return '';
        }
        $z = CarbonImmutable::instance($t)->setTimezone(self::TEHRAN);

        return self::digits(self::plain($z) . $z->format(' H:i'));
    }

    /** "12 مهر 1405" */
    public static function longDate(?\DateTimeInterface $t): string
    {
        if ($t === null) {
            return '';
        }
        $z = CarbonImmutable::instance($t)->setTimezone(self::TEHRAN);
        [$y, $m, $d] = self::fromGregorian((int) $z->year, (int) $z->month, (int) $z->day);

        return self::digits("{$d} " . self::MONTHS[$m - 1] . " {$y}");
    }

    /**
     * Parses "1405/07/12", "1405-7-12" or the same with Persian digits. Returns null if the text is not a Jalali date.
     */
    public static function parseDate(?string $text): ?CarbonImmutable
    {
        if ($text === null) {
            return null;
        }
        $t = self::latin(trim($text));
        if (preg_match('/\A(1[2-9]\d\d)[\/\-.](\d{1,2})[\/\-.](\d{1,2})\z/', $t, $m) !== 1) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > ($mo <= 6 ? 31 : 30)) {
            return null;
        }

        return self::toGregorian($y, $mo, $d);
    }

    /** Latin digits to Persian digits. */
    public static function digits(string $s): string
    {
        return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** Persian and Arabic-Indic digits to Latin digits. */
    public static function latin(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private static function plain(\DateTimeInterface $t): string
    {
        $z = CarbonImmutable::instance($t)->setTimezone(self::TEHRAN);
        [$y, $m, $d] = self::fromGregorian((int) $z->year, (int) $z->month, (int) $z->day);

        return sprintf('%04d/%02d/%02d', $y, $m, $d);
    }
}
