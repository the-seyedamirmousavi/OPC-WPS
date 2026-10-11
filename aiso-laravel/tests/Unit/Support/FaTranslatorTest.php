<?php

namespace Tests\Unit\Support;

use App\Support\Text\FaTranslator;
use PHPUnit\Framework\TestCase;

/** Port of FaTranslatorTest.java. */
class FaTranslatorTest extends TestCase
{
    public function test_translates_errors_with_parameters_and_statuses(): void
    {
        $this->assertSame('عملیات A در وضعیت «تخصیص‌یافته» است، اما باید «در حال انجام» باشد',
            FaTranslator::translate('Operation A is ASSIGNED, expected IN_PROGRESS'));
        $this->assertSame('منبع «R-NOPE» وجود ندارد', FaTranslator::translate("Resource 'R-NOPE' does not exist"));
        $this->assertSame('شناسهٔ «A» تکراری است (اولین بار در ردیف 2 آمده)',
            FaTranslator::translate("Duplicate id 'A' (first used in row 2)"));
    }

    public function test_translates_multi_part_validation_messages(): void
    {
        $this->assertSame('userId: نباید خالی باشد؛ password: نباید خالی باشد',
            FaTranslator::translate('userId: must not be blank; password: must not be blank'));
    }

    public function test_translates_nested_texts_in_history_notes(): void
    {
        $this->assertSame('تخصیص به user1 — پیشنهاد الگوریتم تأیید شد: اعمال نشد: عملیات دیگر در وضعیت «آماده» نیست',
            FaTranslator::translate('Assigned to user1 - Approved ALGORITHM proposal: Not applied: Operation is no longer READY'));
    }

    public function test_translates_assignment_reasons_including_the_project_suffix(): void
    {
        $reason = 'Rank 1 (chain 12.0 h); Machine R slot 1 of 5; Executive User 1: lowest load (0.0 h, 0 active); project Urgent job (priority 2)';
        $this->assertSame('رتبه 1 (زنجیره 12.0 ساعت)؛ «Machine R» اسلات 1 از 5؛ Executive User 1: کمترین بار (0.0 ساعت، 0 وظیفهٔ فعال)؛ پروژه «Urgent job» (اولویت 2)',
            FaTranslator::translate($reason));
        $this->assertStringContainsString('مجری ثابت در فایل',
            FaTranslator::translate('Rank 2 (chain 3.0 h); R slot 2 of 2; Ali: fixed executor in OPC'));
    }

    public function test_unknown_text_and_user_text_are_left_alone(): void
    {
        $this->assertSame('material arrived', FaTranslator::translate('material arrived'));
        $this->assertSame('مواد رسید', FaTranslator::translate('مواد رسید'));
        $this->assertNull(FaTranslator::translate(null));
    }
}
