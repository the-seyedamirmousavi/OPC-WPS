package com.aiso.service;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.ZonedDateTime;

/**
 * Solar Hijri (Jalali / Persian) calendar helpers. The JDK has no Persian calendar, so the arithmetic conversion
 * (valid for the years in use here) is implemented directly. Dates are shown in Iran's time zone.
 */
public final class Jalali {

    public static final ZoneId TEHRAN = ZoneId.of("Asia/Tehran");

    private static final String[] MONTHS = {"فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
            "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"};

    private static final int[] G_D_M = {0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334};

    private Jalali() {
    }

    /** @return {year, month, day} in the Jalali calendar */
    public static int[] fromGregorian(int gy, int gm, int gd) {
        int gy2 = gm > 2 ? gy + 1 : gy;
        int days = 355666 + 365 * gy + (gy2 + 3) / 4 - (gy2 + 99) / 100 + (gy2 + 399) / 400 + gd + G_D_M[gm - 1];
        int jy = -1595 + 33 * (days / 12053);
        days %= 12053;
        jy += 4 * (days / 1461);
        days %= 1461;
        if (days > 365) {
            jy += (days - 1) / 365;
            days = (days - 1) % 365;
        }
        int jm = days < 186 ? 1 + days / 31 : 7 + (days - 186) / 30;
        int jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
        return new int[]{jy, jm, jd};
    }

    public static LocalDate toGregorian(int jy, int jm, int jd) {
        int y = jy + 1595;
        int days = -355668 + 365 * y + (y / 33) * 8 + ((y % 33) + 3) / 4 + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
        int gy = 400 * (days / 146097);
        days %= 146097;
        if (days > 36524) {
            days--;
            gy += 100 * (days / 36524);
            days %= 36524;
            if (days >= 365) {
                days++;
            }
        }
        gy += 4 * (days / 1461);
        days %= 1461;
        if (days > 365) {
            gy += (days - 1) / 365;
            days = (days - 1) % 365;
        }
        int gd = days + 1;
        boolean leap = (gy % 4 == 0 && gy % 100 != 0) || gy % 400 == 0;
        int[] sal = {0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};
        int gm = 0;
        while (gm < 13 && gd > sal[gm]) {
            gd -= sal[gm];
            gm++;
        }
        return LocalDate.of(gy, gm, gd);
    }

    public static int[] of(LocalDate d) {
        return fromGregorian(d.getYear(), d.getMonthValue(), d.getDayOfMonth());
    }

    /** "1405/07/12" with Persian digits, e.g. "۱۴۰۵/۰۷/۱۲" (date only). */
    public static String date(Instant instant) {
        if (instant == null) {
            return "";
        }
        return digits(plain(instant.atZone(TEHRAN)));
    }

    /** "۱۴۰۵/۰۷/۱۲ ۰۹:۱۱" in Tehran time. */
    public static String dateTime(Instant instant) {
        if (instant == null) {
            return "";
        }
        ZonedDateTime z = instant.atZone(TEHRAN);
        return digits(plain(z) + String.format(" %02d:%02d", z.getHour(), z.getMinute()));
    }

    /** "۱۲ مهر ۱۴۰۵" */
    public static String longDate(Instant instant) {
        if (instant == null) {
            return "";
        }
        int[] j = of(instant.atZone(TEHRAN).toLocalDate());
        return digits(j[2] + " " + MONTHS[j[1] - 1] + " " + j[0]);
    }

    /** Parses "1405/07/12", "1405-7-12" or the same with Persian digits. Returns null if it is not a Jalali date. */
    public static LocalDate parse(String text) {
        if (text == null) {
            return null;
        }
        String t = latin(text.trim());
        java.util.regex.Matcher m = java.util.regex.Pattern.compile("^(1[2-9]\\d\\d)[/\\-.](\\d{1,2})[/\\-.](\\d{1,2})$").matcher(t);
        if (!m.matches()) {
            return null;
        }
        int y = Integer.parseInt(m.group(1));
        int mo = Integer.parseInt(m.group(2));
        int d = Integer.parseInt(m.group(3));
        if (mo < 1 || mo > 12 || d < 1 || d > (mo <= 6 ? 31 : mo <= 11 ? 30 : 30)) {
            return null;
        }
        return toGregorian(y, mo, d);
    }

    /** Latin digits to Persian digits. */
    public static String digits(String s) {
        StringBuilder b = new StringBuilder(s.length());
        for (char c : s.toCharArray()) {
            b.append(c >= '0' && c <= '9' ? (char) ('۰' + (c - '0')) : c);
        }
        return b.toString();
    }

    /** Persian and Arabic-Indic digits to Latin digits. */
    public static String latin(String s) {
        StringBuilder b = new StringBuilder(s.length());
        for (char c : s.toCharArray()) {
            if (c >= '۰' && c <= '۹') {
                b.append((char) ('0' + (c - '۰')));
            } else if (c >= '٠' && c <= '٩') {
                b.append((char) ('0' + (c - '٠')));
            } else {
                b.append(c);
            }
        }
        return b.toString();
    }

    private static String plain(ZonedDateTime z) {
        int[] j = of(z.toLocalDate());
        return String.format("%04d/%02d/%02d", j[0], j[1], j[2]);
    }
}
