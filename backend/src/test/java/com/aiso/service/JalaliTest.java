package com.aiso.service;

import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.time.LocalDate;

import static org.assertj.core.api.Assertions.assertThat;

class JalaliTest {

    @Test
    void knownDatesConvertBothWays() {
        // Nowruz 1404 = 21 March 2025; the app showed 12 Mehr 1405 on 4 October 2026
        assertThat(Jalali.of(LocalDate.of(2025, 3, 21))).containsExactly(1404, 1, 1);
        assertThat(Jalali.of(LocalDate.of(2026, 10, 4))).containsExactly(1405, 7, 12);
        assertThat(Jalali.of(LocalDate.of(2024, 3, 20))).containsExactly(1403, 1, 1);
        assertThat(Jalali.toGregorian(1405, 7, 12)).isEqualTo(LocalDate.of(2026, 10, 4));
        assertThat(Jalali.toGregorian(1403, 12, 30)).isEqualTo(LocalDate.of(2025, 3, 20)); // 1403 is a leap year
    }

    @Test
    void roundTripOverSeveralYears() {
        for (LocalDate d = LocalDate.of(2020, 1, 1); d.isBefore(LocalDate.of(2032, 1, 1)); d = d.plusDays(1)) {
            int[] j = Jalali.of(d);
            assertThat(Jalali.toGregorian(j[0], j[1], j[2])).as(d.toString()).isEqualTo(d);
        }
    }

    @Test
    void formatsWithPersianDigitsInTehranTime() {
        Instant i = Instant.parse("2026-10-04T05:41:00Z"); // 09:11 in Tehran (+03:30)
        assertThat(Jalali.dateTime(i)).isEqualTo("۱۴۰۵/۰۷/۱۲ ۰۹:۱۱");
        assertThat(Jalali.date(i)).isEqualTo("۱۴۰۵/۰۷/۱۲");
        assertThat(Jalali.longDate(i)).isEqualTo("۱۲ مهر ۱۴۰۵");
        assertThat(Jalali.date(null)).isEmpty();
    }

    @Test
    void parsesJalaliInputWithPersianOrLatinDigits() {
        assertThat(Jalali.parse("1405/07/12")).isEqualTo(LocalDate.of(2026, 10, 4));
        assertThat(Jalali.parse("۱۴۰۵/۰۷/۱۲")).isEqualTo(LocalDate.of(2026, 10, 4));
        assertThat(Jalali.parse("1405-7-12")).isEqualTo(LocalDate.of(2026, 10, 4));
        assertThat(Jalali.parse("2026-10-04")).isNull();
        assertThat(Jalali.parse("1405/13/01")).isNull();
        assertThat(Jalali.parse("abc")).isNull();
    }
}
