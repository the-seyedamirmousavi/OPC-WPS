<?php

namespace App\Imports;

use App\Imports\SheetReader;

/**
 * Persian names for the master workbook: sheet names, column headers and enum values. They are accepted on import (so a
 * Persian-speaking client can work with a fully Persian file) and used when the template or report is produced in
 * Persian. Matching ignores case, spaces, underscores and half-spaces (ZWNJ).
 */
final class FaNames
{
    /** canonical sheet name => Persian sheet name */
    public const SHEETS = [
        'Resources' => 'منابع',
        'BOM' => 'قطعات',
        'OPC' => 'عملیات',
        'Predecessors' => 'پیش‌نیازها',
        'Users' => 'کاربران',
        'Settings' => 'تنظیمات',
    ];

    /** canonical column => Persian header (a column may appear in several sheets with the same Persian header) */
    public const COLUMNS = [
        'Resource_ID' => 'شناسه منبع',
        'Resource_Name' => 'نام منبع',
        'Resource_Type' => 'نوع منبع',
        'Responsible_User_ID' => 'شناسه کاربر مسئول',
        'Capacity' => 'ظرفیت',
        'Status' => 'وضعیت',
        'Description' => 'توضیحات',
        'Item_ID' => 'شناسه قطعه',
        'Item_Name' => 'نام قطعه',
        'Quantity' => 'تعداد',
        'Unit' => 'واحد',
        'Supply_Type' => 'نوع تأمین',
        'Related_Operation_ID' => 'شناسه عملیات مرتبط',
        'Operation_ID' => 'شناسه عملیات',
        'Operation_Name' => 'نام عملیات',
        'Preparation_Time' => 'زمان آماده‌سازی',
        'Transport_Time' => 'زمان حمل',
        'Setup_Time' => 'زمان راه‌اندازی',
        'Direct_Time' => 'زمان مستقیم',
        'Operation_Status' => 'وضعیت عملیات',
        'Predecessor_Operation_ID' => 'شناسه عملیات پیش‌نیاز',
        'Dependency_Type' => 'نوع وابستگی',
        'Start_Condition' => 'شرط شروع',
        'Is_Mandatory' => 'الزامی',
        'User_ID' => 'شناسه کاربر',
        'Full_Name' => 'نام کامل',
        'Role' => 'نقش',
        'Messenger_ID' => 'شناسه پیام‌رسان',
        'Active_Status' => 'وضعیت فعال',
        'Contact_Info' => 'اطلاعات تماس',
        'Project_ID' => 'شناسه پروژه',
        'Project_Name' => 'نام پروژه',
        'Owner_ID' => 'شناسه مالک',
        'Manager_ID' => 'شناسه مدیر',
        'Messenger_Platform' => 'پلتفرم پیام‌رسان',
        'System_Status' => 'وضعیت سیستم',
        'Data_Version' => 'نسخه داده',
        'Configuration_Version' => 'نسخه پیکربندی',
    ];

    /** normalized Persian => canonical */
    private static array $sheetAlias = [];
    private static array $columnAlias = [];
    /** normalized Persian => canonical enum / boolean value */
    private static array $values = [];

    private function __construct()
    {
    }

    /** Builds the alias tables once (they depend on SheetReader::normalize). */
    private static function boot(): void
    {
        if (self::$sheetAlias !== []) {
            return;
        }
        foreach (self::SHEETS as $en => $fa) {
            self::$sheetAlias[SheetReader::normalize($fa)] = $en;
        }
        foreach (self::COLUMNS as $en => $fa) {
            self::$columnAlias[SheetReader::normalize($fa)] = $en;
        }
        $value = static function (string $canonical, string ...$persian): void {
            foreach ($persian as $p) {
                self::$values[SheetReader::normalize($p)] = $canonical;
            }
        };
        $value('ACTIVE', 'فعال', 'درست', 'بله', 'بلی', 'true');
        $value('INACTIVE', 'غیرفعال', 'نادرست', 'خیر', 'false');
        $value('FINISH_TO_START', 'پایان به شروع', 'پایان-به-شروع');
        $value('START_TO_START', 'شروع به شروع', 'شروع-به-شروع');
        $value('NOT_READY', 'آماده نیست');
        $value('READY', 'آماده');
        $value('COMPLETED', 'تکمیل شده', 'تکمیل', 'انجام شده');
        $value('CANCELLED', 'لغو شده', 'لغو');
        $value('OWNER', 'مالک');
        $value('MANAGER', 'مدیر');
        $value('USER_1', 'کاربر 1', 'کاربر ۱');
        $value('USER_2', 'کاربر 2', 'کاربر ۲');
        $value('USER_3', 'کاربر 3', 'کاربر ۳');
    }

    /** Persian header for a canonical column name (or the name itself when unknown). */
    public static function header(string $canonical): string
    {
        return self::COLUMNS[$canonical] ?? $canonical;
    }

    /** Canonical (English) column name for a normalized Persian header, or null. */
    public static function canonicalColumn(string $normalizedHeader): ?string
    {
        self::boot();

        return self::$columnAlias[$normalizedHeader] ?? null;
    }

    /** Canonical sheet name for a normalized sheet name (English or Persian), or null. */
    public static function canonicalSheet(string $normalized): ?string
    {
        self::boot();

        return self::$sheetAlias[$normalized] ?? null;
    }

    /** All spellings of a sheet name that should be accepted (English first). @return string[] */
    public static function sheetNames(string $canonical): array
    {
        return isset(self::SHEETS[$canonical]) ? [$canonical, self::SHEETS[$canonical]] : [$canonical];
    }

    /**
     * Maps a Persian enum/boolean spelling to its canonical value; anything else is returned unchanged so the caller's
     * normal validation (and error message) still applies.
     */
    public static function value(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        self::boot();
        $mapped = self::$values[SheetReader::normalize($text)] ?? null;

        return $mapped ?? $text;
    }
}
