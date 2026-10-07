package com.aiso.imports;

import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Persian names for the master workbook: sheet names, column headers and enum values. They are accepted on import
 * (so a Persian-speaking client can work with a fully Persian file) and used when the template/report is produced
 * in Persian. Matching ignores case, spaces, underscores and half-spaces (ZWNJ).
 */
public final class FaNames {

    /** canonical sheet name -> Persian sheet name */
    public static final Map<String, String> SHEETS = new LinkedHashMap<>();
    /** canonical column -> Persian header (a column may appear in several sheets with the same Persian header) */
    public static final Map<String, String> COLUMNS = new LinkedHashMap<>();

    private static final Map<String, String> SHEET_ALIAS = new HashMap<>();   // normalized Persian -> canonical
    private static final Map<String, String> COLUMN_ALIAS = new HashMap<>();  // normalized Persian -> canonical
    private static final Map<String, String> VALUES = new HashMap<>();        // normalized Persian -> canonical enum/boolean

    static {
        SHEETS.put("Resources", "منابع");
        SHEETS.put("BOM", "قطعات");
        SHEETS.put("OPC", "عملیات");
        SHEETS.put("Predecessors", "پیش‌نیازها");
        SHEETS.put("Users", "کاربران");
        SHEETS.put("Settings", "تنظیمات");

        COLUMNS.put("Resource_ID", "شناسه منبع");
        COLUMNS.put("Resource_Name", "نام منبع");
        COLUMNS.put("Resource_Type", "نوع منبع");
        COLUMNS.put("Responsible_User_ID", "شناسه کاربر مسئول");
        COLUMNS.put("Capacity", "ظرفیت");
        COLUMNS.put("Status", "وضعیت");
        COLUMNS.put("Description", "توضیحات");
        COLUMNS.put("Item_ID", "شناسه قطعه");
        COLUMNS.put("Item_Name", "نام قطعه");
        COLUMNS.put("Quantity", "تعداد");
        COLUMNS.put("Unit", "واحد");
        COLUMNS.put("Supply_Type", "نوع تأمین");
        COLUMNS.put("Related_Operation_ID", "شناسه عملیات مرتبط");
        COLUMNS.put("Operation_ID", "شناسه عملیات");
        COLUMNS.put("Operation_Name", "نام عملیات");
        COLUMNS.put("Preparation_Time", "زمان آماده‌سازی");
        COLUMNS.put("Transport_Time", "زمان حمل");
        COLUMNS.put("Setup_Time", "زمان راه‌اندازی");
        COLUMNS.put("Direct_Time", "زمان مستقیم");
        COLUMNS.put("Operation_Status", "وضعیت عملیات");
        COLUMNS.put("Predecessor_Operation_ID", "شناسه عملیات پیش‌نیاز");
        COLUMNS.put("Dependency_Type", "نوع وابستگی");
        COLUMNS.put("Start_Condition", "شرط شروع");
        COLUMNS.put("Is_Mandatory", "الزامی");
        COLUMNS.put("User_ID", "شناسه کاربر");
        COLUMNS.put("Full_Name", "نام کامل");
        COLUMNS.put("Role", "نقش");
        COLUMNS.put("Messenger_ID", "شناسه پیام‌رسان");
        COLUMNS.put("Active_Status", "وضعیت فعال");
        COLUMNS.put("Contact_Info", "اطلاعات تماس");
        COLUMNS.put("Project_ID", "شناسه پروژه");
        COLUMNS.put("Project_Name", "نام پروژه");
        COLUMNS.put("Owner_ID", "شناسه مالک");
        COLUMNS.put("Manager_ID", "شناسه مدیر");
        COLUMNS.put("Messenger_Platform", "پلتفرم پیام‌رسان");
        COLUMNS.put("System_Status", "وضعیت سیستم");
        COLUMNS.put("Data_Version", "نسخه داده");
        COLUMNS.put("Configuration_Version", "نسخه پیکربندی");

        SHEETS.forEach((en, fa) -> SHEET_ALIAS.put(SheetReader.normalize(fa), en));
        COLUMNS.forEach((en, fa) -> COLUMN_ALIAS.put(SheetReader.normalize(fa), en));

        value("ACTIVE", "فعال", "درست", "بله", "بلی", "true");
        value("INACTIVE", "غیرفعال", "نادرست", "خیر", "false");
        value("FINISH_TO_START", "پایان به شروع", "پایان-به-شروع");
        value("START_TO_START", "شروع به شروع", "شروع-به-شروع");
        value("NOT_READY", "آماده نیست");
        value("READY", "آماده");
        value("COMPLETED", "تکمیل شده", "تکمیل", "انجام شده");
        value("CANCELLED", "لغو شده", "لغو");
        value("OWNER", "مالک");
        value("MANAGER", "مدیر");
        value("USER_1", "کاربر 1", "کاربر ۱");
        value("USER_2", "کاربر 2", "کاربر ۲");
        value("USER_3", "کاربر 3", "کاربر ۳");
    }

    private FaNames() {
    }

    private static void value(String canonical, String... persian) {
        for (String p : persian) {
            VALUES.put(SheetReader.normalize(p), canonical);
        }
    }

    /** Canonical (English) column name for a normalized Persian header, or null. */
    static String canonicalColumn(String normalizedHeader) {
        return COLUMN_ALIAS.get(normalizedHeader);
    }

    /** All spellings of a sheet name that should be accepted (English first). */
    static List<String> sheetNames(String canonical) {
        String fa = SHEETS.get(canonical);
        return fa == null ? List.of(canonical) : List.of(canonical, fa);
    }

    /**
     * Maps a Persian enum/boolean spelling to its canonical value; anything else is returned unchanged so the caller's
     * normal validation (and error message) still applies.
     */
    static String value(String text) {
        if (text == null) {
            return null;
        }
        String mapped = VALUES.get(SheetReader.normalize(text));
        return mapped == null ? text : mapped;
    }
}
