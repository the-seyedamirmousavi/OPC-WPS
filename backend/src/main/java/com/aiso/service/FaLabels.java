package com.aiso.service;

import com.aiso.domain.OperationStatus;
import com.aiso.domain.ProjectStatus;

import java.util.Map;

/** Persian display names for codes that appear in exports. */
public final class FaLabels {

    private static final Map<OperationStatus, String> STATUS = Map.of(
            OperationStatus.NOT_READY, "آماده نیست", OperationStatus.READY, "آماده",
            OperationStatus.ASSIGNED, "تخصیص‌یافته", OperationStatus.IN_PROGRESS, "در حال انجام",
            OperationStatus.BLOCKED, "مسدود", OperationStatus.COMPLETED, "تکمیل‌شده", OperationStatus.CANCELLED, "لغوشده");

    private static final Map<String, String> IMPORT_STATUS = Map.of(
            "REJECTED", "رد شد", "VALID", "اعتبارسنجی شد", "APPLIED", "اعمال شد", "PRIORITY_REQUIRED", "نیازمند اولویت‌بندی");

    private static final Map<String, String> ENTITY = Map.ofEntries(
            Map.entry("Operation", "عملیات"), Map.entry("Resource", "منبع"), Map.entry("User", "کاربر"),
            Map.entry("Project", "پروژه"), Map.entry("Settings", "تنظیمات"), Map.entry("System", "سیستم"),
            Map.entry("Import", "ورود اطلاعات"), Map.entry("AssignmentRun", "اجرای تخصیص"));

    private static final Map<String, String> ACTION = Map.ofEntries(
            Map.entry("STATUS_CHANGE", "تغییر وضعیت"), Map.entry("ASSIGN", "تخصیص"), Map.entry("PROGRESS", "ثبت پیشرفت"),
            Map.entry("COMMENT", "توضیح"), Map.entry("COMPLETION_APPROVED", "تأیید اتمام"),
            Map.entry("OPERATION_CREATED", "ایجاد عملیات"), Map.entry("OPERATION_UPDATED", "به‌روزرسانی عملیات"),
            Map.entry("RESOURCE_CREATED", "ایجاد منبع"), Map.entry("RESOURCE_UPDATED", "به‌روزرسانی منبع"),
            Map.entry("USER_CREATED", "ایجاد کاربر"), Map.entry("USER_UPDATED", "به‌روزرسانی کاربر"),
            Map.entry("PASSWORD_RESET", "بازنشانی رمز"), Map.entry("PASSWORD_CHANGED", "تغییر رمز"),
            Map.entry("IMPORT_APPLIED", "ورود اطلاعات اعمال شد"), Map.entry("SETTINGS_CHANGED", "تغییر تنظیمات"),
            Map.entry("ASSIGNMENT_MODE_CHANGED", "تغییر روش تخصیص"), Map.entry("SYSTEM_STATUS_CHANGED", "تغییر وضعیت سیستم"),
            Map.entry("SYSTEM_RESET", "بازنشانی سیستم"), Map.entry("ASSIGNMENT_SUGGESTED", "پیشنهاد تخصیص"),
            Map.entry("PROJECT_CREATED", "ایجاد پروژه"), Map.entry("PROJECT_PRIORITIES_CHANGED", "تغییر اولویت پروژه‌ها"),
            Map.entry("PROJECT_UPDATED", "به‌روزرسانی پروژه"));

    private FaLabels() {
    }

    public static String status(OperationStatus s) {
        return STATUS.getOrDefault(s, s.name());
    }

    public static String projectStatus(ProjectStatus s) {
        return s == ProjectStatus.ACTIVE ? "فعال" : "بایگانی‌شده";
    }

    public static String importStatus(String s) {
        return IMPORT_STATUS.getOrDefault(s, s);
    }

    public static String entity(String s) {
        return ENTITY.getOrDefault(s, s);
    }

    public static String action(String s) {
        return ACTION.getOrDefault(s, s);
    }

    public static String yesNo(boolean b) {
        return b ? "بله" : "خیر";
    }
}
