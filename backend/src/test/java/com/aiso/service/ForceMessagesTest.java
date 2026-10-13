package com.aiso.service;

import com.aiso.web.ApiException;
import org.junit.jupiter.api.Test;

import java.time.Instant;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

/** FORCE start-time input (Gregorian, Solar Hijri, ISO) and the Persian rendering of FORCE messages. */
class ForceMessagesTest {

    @Test
    void startTimeAcceptsTehranWallClockJalaliAndIso() {
        Instant expected = Instant.parse("2026-10-07T10:30:00Z"); // 14:00 in Tehran (UTC+03:30)
        assertThat(ForceService.parseStart("2026-10-07 14:00")).isEqualTo(expected);
        assertThat(ForceService.parseStart("2026-10-07T14:00")).isEqualTo(expected);
        assertThat(ForceService.parseStart("1405/07/15 14:00")).isEqualTo(expected);
        assertThat(ForceService.parseStart("۱۴۰۵/۰۷/۱۵ ۱۴:۰۰")).isEqualTo(expected);
        assertThat(ForceService.parseStart("2026-10-07T10:30:00Z")).isEqualTo(expected);
        assertThat(ForceService.parseStart("2026-10-07T14:00:00+03:30")).isEqualTo(expected);
        assertThat(ForceService.parseStart("  ")).isNull();
        assertThatThrownBy(() -> ForceService.parseStart("tomorrow")).isInstanceOf(ApiException.class);
        assertThatThrownBy(() -> ForceService.parseStart("2026-10-07 25:00")).isInstanceOf(ApiException.class);
    }

    @Test
    void forceMessagesAreTranslatedWithSolarHijriTimes() {
        assertThat(FaTranslator.translate("B cannot start at 2026-10-07 14:00: resource Machine R (capacity 1) is taken by A at that time"))
                .isEqualTo("B نمی‌تواند در ۱۴۰۵/۰۷/۱۵ ۱۴:۰۰ شروع شود: منبع «Machine R» (ظرفیت 1) در آن زمان در اختیار A است");
        assertThat(FaTranslator.translate("C cannot start at 2026-10-07 14:00: its predecessors (A, B) allow 2026-10-07 18:30 at the earliest"))
                .isEqualTo("C نمی‌تواند در ۱۴۰۵/۰۷/۱۵ ۱۴:۰۰ شروع شود: پیش‌نیازهایش (A, B) زودتر از ۱۴۰۵/۰۷/۱۵ ۱۸:۳۰ اجازه نمی‌دهند");
        assertThat(FaTranslator.translate("FORCE rejected: Forced start 2020-01-01 08:00 of A is in the past"))
                .isEqualTo("اجبار مدیر پذیرفته نشد: شروع اجباری A در ۱۳۹۸/۱۰/۱۱ ۰۸:۰۰ در گذشته است");
        assertThat(FaTranslator.translate("No schedule was found in which F starts at 2026-10-07 14:00; it is planned for 2026-10-08 09:00. A feasible schedule may still exist."))
                .startsWith("برنامه‌ای پیدا نشد که در آن F در ۱۴۰۵/۰۷/۱۵ ۱۴:۰۰ شروع شود؛ برای ۱۴۰۵/۰۷/۱۶ ۰۹:۰۰");
        assertThat(FaTranslator.translate("It would make the FORCE on X impossible: Forced start 2026-10-07 14:00 of X is in the past"))
                .isEqualTo("اجبار روی X را ناممکن می‌کند: شروع اجباری X در ۱۴۰۵/۰۷/۱۵ ۱۴:۰۰ در گذشته است");
        assertThat(FaTranslator.translate("A cannot start at 2026-10-07 14:00: the FORCE on C (start 2026-10-07 15:00) needs it earlier"))
                .isEqualTo("A نمی‌تواند در ۱۴۰۵/۰۷/۱۵ ۱۴:۰۰ شروع شود: اجبار قدیمی‌تر روی C (شروع ۱۴۰۵/۰۷/۱۵ ۱۵:۰۰) آن را زودتر لازم دارد");
        assertThat(FaTranslator.translate("No schedule was found in which G starts at 2026-10-07 14:00 without making the older FORCE on F impossible; it is planned for 2026-10-07 18:00."))
                .isEqualTo("برنامه‌ای پیدا نشد که در آن G در ۱۴۰۵/۰۷/۱۵ ۱۴:۰۰ شروع شود و اجبار قدیمی‌تر روی F ناممکن نشود؛ برای ۱۴۰۵/۰۷/۱۵ ۱۸:۰۰ برنامه‌ریزی شده است.");
        assertThat(FaTranslator.translate("Rank 1 (chain 4.0 h); R slot 1 of 1; Ali: forced by the manager"))
                .contains("به اجبار مدیر");
        assertThat(FaTranslator.translate("Operation B is forced to executor user3 by the manager. Change or remove the FORCE first."))
                .isEqualTo("عملیات B به اجبار مدیر به user3 سپرده شده است. ابتدا اجبار را تغییر دهید یا حذف کنید.");
    }
}
