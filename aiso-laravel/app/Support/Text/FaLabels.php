<?php

namespace App\Support\Text;

/** Persian display names for codes that appear in exports and in the UI. */
final class FaLabels
{
    private const STATUS = [
        'NOT_READY' => 'آماده نیست', 'READY' => 'آماده',
        'ASSIGNED' => 'تخصیص‌یافته', 'IN_PROGRESS' => 'در حال انجام',
        'BLOCKED' => 'مسدود', 'COMPLETED' => 'تکمیل‌شده', 'CANCELLED' => 'لغوشده',
    ];
    private const IMPORT_STATUS = [
        'REJECTED' => 'رد شد', 'VALID' => 'اعتبارسنجی شد', 'APPLIED' => 'اعمال شد', 'PRIORITY_REQUIRED' => 'نیازمند اولویت‌بندی',
    ];
    private const ENTITY = [
        'Operation' => 'عملیات', 'Resource' => 'منبع', 'User' => 'کاربر', 'Project' => 'پروژه',
        'Settings' => 'تنظیمات', 'System' => 'سیستم', 'Import' => 'ورود اطلاعات', 'AssignmentRun' => 'اجرای تخصیص',
    ];
    private const ACTION = [
        'STATUS_CHANGE' => 'تغییر وضعیت', 'ASSIGN' => 'تخصیص', 'PROGRESS' => 'ثبت پیشرفت',
        'COMMENT' => 'توضیح', 'COMPLETION_APPROVED' => 'تأیید اتمام',
        'OPERATION_CREATED' => 'ایجاد عملیات', 'OPERATION_UPDATED' => 'به‌روزرسانی عملیات',
        'RESOURCE_CREATED' => 'ایجاد منبع', 'RESOURCE_UPDATED' => 'به‌روزرسانی منبع',
        'USER_CREATED' => 'ایجاد کاربر', 'USER_UPDATED' => 'به‌روزرسانی کاربر',
        'PASSWORD_RESET' => 'بازنشانی رمز', 'PASSWORD_CHANGED' => 'تغییر رمز',
        'IMPORT_APPLIED' => 'ورود اطلاعات اعمال شد', 'SETTINGS_CHANGED' => 'تغییر تنظیمات',
        'ASSIGNMENT_MODE_CHANGED' => 'تغییر روش تخصیص', 'SYSTEM_STATUS_CHANGED' => 'تغییر وضعیت سیستم',
        'SYSTEM_RESET' => 'بازنشانی سیستم', 'ASSIGNMENT_SUGGESTED' => 'پیشنهاد تخصیص',
        'PROJECT_CREATED' => 'ایجاد پروژه', 'PROJECT_PRIORITIES_CHANGED' => 'تغییر اولویت پروژه‌ها',
        'PROJECT_UPDATED' => 'به‌روزرسانی پروژه', 'FORCE_SET' => 'ثبت اجبار مدیر',
        'FORCE_REMOVED' => 'حذف اجبار مدیر', 'PLAN_REOPTIMIZED' => 'بهینه‌سازی دوبارهٔ برنامه',
        'RESCHEDULE' => 'تغییر زمان برنامه‌ریزی‌شده',
    ];

    private function __construct()
    {
    }

    public static function status(string $s): string
    {
        return self::STATUS[$s] ?? $s;
    }

    public static function projectStatus(string $s): string
    {
        return $s === 'ACTIVE' ? 'فعال' : 'بایگانی‌شده';
    }

    public static function importStatus(string $s): string
    {
        return self::IMPORT_STATUS[$s] ?? $s;
    }

    public static function entity(string $s): string
    {
        return self::ENTITY[$s] ?? $s;
    }

    public static function action(string $s): string
    {
        return self::ACTION[$s] ?? $s;
    }

    public static function yesNo(bool $b): string
    {
        return $b ? 'بله' : 'خیر';
    }
}
