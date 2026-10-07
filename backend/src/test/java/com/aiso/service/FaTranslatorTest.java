package com.aiso.service;

import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;

class FaTranslatorTest {

    @Test
    void translatesErrorsWithParametersAndStatuses() {
        assertThat(FaTranslator.translate("Operation A is ASSIGNED, expected IN_PROGRESS"))
                .isEqualTo("عملیات A در وضعیت «تخصیص‌یافته» است، اما باید «در حال انجام» باشد");
        assertThat(FaTranslator.translate("Resource 'R-NOPE' does not exist")).isEqualTo("منبع «R-NOPE» وجود ندارد");
        assertThat(FaTranslator.translate("Duplicate id 'A' (first used in row 2)")).isEqualTo("شناسهٔ «A» تکراری است (اولین بار در ردیف 2 آمده)");
    }

    @Test
    void translatesMultiPartValidationMessages() {
        assertThat(FaTranslator.translate("userId: must not be blank; password: must not be blank"))
                .isEqualTo("userId: نباید خالی باشد؛ password: نباید خالی باشد");
    }

    @Test
    void translatesNestedTextsInHistoryNotes() {
        assertThat(FaTranslator.translate("Assigned to user1 - Approved ALGORITHM proposal: Not applied: Operation is no longer READY"))
                .isEqualTo("تخصیص به user1 — پیشنهاد الگوریتم تأیید شد: اعمال نشد: عملیات دیگر در وضعیت «آماده» نیست");
    }

    @Test
    void translatesAssignmentReasonsIncludingTheProjectSuffix() {
        String reason = "Rank 1 (chain 12.0 h); Machine R slot 1 of 5; Executive User 1: lowest load (0.0 h, 0 active); project Urgent job (priority 2)";
        assertThat(FaTranslator.translate(reason))
                .isEqualTo("رتبه 1 (زنجیره 12.0 ساعت)؛ «Machine R» اسلات 1 از 5؛ Executive User 1: کمترین بار (0.0 ساعت، 0 وظیفهٔ فعال)؛ پروژه «Urgent job» (اولویت 2)");
        assertThat(FaTranslator.translate("Rank 2 (chain 3.0 h); R slot 2 of 2; Ali: fixed executor in OPC"))
                .contains("مجری ثابت در فایل");
    }

    @Test
    void unknownTextAndUserTextAreLeftAlone() {
        assertThat(FaTranslator.translate("material arrived")).isEqualTo("material arrived");
        assertThat(FaTranslator.translate("مواد رسید")).isEqualTo("مواد رسید");
        assertThat(FaTranslator.translate(null)).isNull();
    }
}
