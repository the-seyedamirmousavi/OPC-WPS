package com.aiso.service;

import com.aiso.domain.ImportLog;
import com.aiso.imports.FaNames;
import com.aiso.repo.AuditEventRepository;
import com.aiso.repo.ImportLogRepository;
import com.aiso.service.Dashboards.UserPerformance;
import com.aiso.service.Views.OperationView;
import org.apache.poi.ss.usermodel.CellStyle;
import org.apache.poi.ss.usermodel.Font;
import org.apache.poi.ss.usermodel.Row;
import org.apache.poi.ss.usermodel.Sheet;
import org.apache.poi.ss.usermodel.Workbook;
import org.apache.poi.xssf.usermodel.XSSFWorkbook;
import org.springframework.data.domain.PageRequest;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.time.Instant;
import java.util.List;

/**
 * Produces the downloadable Excel files: the standard import template and the status report.
 * Both exist in English and in Persian. The Persian files use Persian sheet names and headers, Persian values for
 * statuses, Solar Hijri (Jalali) dates in Tehran time and right-to-left sheets. The importer accepts the Persian
 * names, so a Persian template can be filled in and uploaded as it is.
 */
@Service
public class ExcelService {

    private final OperationViewService operations;
    private final DashboardService dashboards;
    private final AuditEventRepository audit;
    private final ImportLogRepository imports;
    private final SchedulingService scheduling;
    private final ProjectDataService dataService;
    private final PlanService planService;

    public ExcelService(OperationViewService operations, DashboardService dashboards, AuditEventRepository audit,
                        ImportLogRepository imports, SchedulingService scheduling, ProjectDataService dataService,
                        PlanService planService) {
        this.operations = operations;
        this.dashboards = dashboards;
        this.audit = audit;
        this.imports = imports;
        this.scheduling = scheduling;
        this.dataService = dataService;
        this.planService = planService;
    }

    // ------------------------------------------------------------------------------------------------ template

    /** The standard master template with one example row per sheet. */
    public byte[] template(boolean fa) {
        try (Workbook wb = new XSSFWorkbook()) {
            CellStyle head = headStyle(wb);
            Out o = new Out(wb, head, fa);
            if (fa) {
                o.sheet("منابع", h("Resource_ID", "Resource_Name", "Resource_Type", "Responsible_User_ID", "Capacity", "Status", "Description"),
                        List.of(List.of("R-WELD", "جوشکاری", "مرکز کاری", "user1", 2, "فعال", "دو ایستگاه جوش"),
                                List.of("R-PAINT", "رنگ‌کاری", "مرکز کاری", "", 1, "فعال", "")));
                o.sheet("قطعات", h("Item_ID", "Item_Name", "Quantity", "Unit", "Supply_Type", "Related_Operation_ID", "Description"),
                        List.of(List.of("ITM-101", "باکس سنگین", 2, "عدد", "ساخت", "OP-001", "")));
                o.sheet("عملیات", h("Operation_ID", "Operation_Name", "Item_ID", "Resource_ID", "Responsible_User_ID",
                                "Preparation_Time", "Transport_Time", "Setup_Time", "Direct_Time", "Operation_Status", "Description"),
                        List.of(List.of("OP-001", "جوشکاری باکس", "ITM-101", "R-WELD", "", 0, 0, 1, 16, "", ""),
                                List.of("OP-002", "رنگ‌کاری باکس", "ITM-101", "R-PAINT", "", 0, 1, 0.5, 6, "", "")));
                o.sheet("پیش‌نیازها", h("Operation_ID", "Predecessor_Operation_ID", "Dependency_Type", "Start_Condition", "Is_Mandatory", "Description"),
                        List.of(List.of("OP-002", "OP-001", "پایان به شروع", "", "بله", "")));
                o.sheet("کاربران", h("User_ID", "Full_Name", "Role", "Messenger_ID", "Active_Status", "Contact_Info"),
                        List.of(List.of("USER_1", "کاربر اجرایی ۱", "کاربر ۱", "", "فعال", ""),
                                List.of("USER_2", "کاربر اجرایی ۲", "کاربر ۲", "", "فعال", ""),
                                List.of("USER_3", "کاربر اجرایی ۳", "کاربر ۳", "", "فعال", "")));
                o.sheet("تنظیمات", h("Project_ID", "Project_Name", "Owner_ID", "Manager_ID", "Messenger_Platform", "System_Status",
                                "Data_Version", "Configuration_Version"),
                        List.of(List.of("P-001", "پروژهٔ نمونه", "owner", "manager", "IN_APP", "ACTIVE", "1", "1")));
            } else {
                o.sheet("Resources", List.of("Resource_ID", "Resource_Name", "Resource_Type", "Responsible_User_ID", "Capacity", "Status", "Description"),
                        List.of(List.of("R-WELD", "Welding", "WORK_CENTER", "USER_1", 2, "ACTIVE", "Two welding stations"),
                                List.of("R-PAINT", "Painting", "WORK_CENTER", "", 1, "ACTIVE", "")));
                o.sheet("BOM", List.of("Item_ID", "Item_Name", "Quantity", "Unit", "Supply_Type", "Related_Operation_ID", "Description"),
                        List.of(List.of("ITM-101", "Box HD", 2, "pcs", "MAKE", "OP-001", "")));
                o.sheet("OPC", List.of("Operation_ID", "Operation_Name", "Item_ID", "Resource_ID", "Responsible_User_ID",
                                "Preparation_Time", "Transport_Time", "Setup_Time", "Direct_Time", "Operation_Status", "Description"),
                        List.of(List.of("OP-001", "Weld box", "ITM-101", "R-WELD", "", 0, 0, 1, 16, "", ""),
                                List.of("OP-002", "Paint box", "ITM-101", "R-PAINT", "", 0, 1, 0.5, 6, "", "")));
                o.sheet("Predecessors", List.of("Operation_ID", "Predecessor_Operation_ID", "Dependency_Type", "Start_Condition", "Is_Mandatory", "Description"),
                        List.of(List.of("OP-002", "OP-001", "FINISH_TO_START", "", "TRUE", "")));
                o.sheet("Users", List.of("User_ID", "Full_Name", "Role", "Messenger_ID", "Active_Status", "Contact_Info"),
                        List.of(List.of("USER_1", "Executive User 1", "USER_1", "", "TRUE", ""),
                                List.of("USER_2", "Executive User 2", "USER_2", "", "TRUE", ""),
                                List.of("USER_3", "Executive User 3", "USER_3", "", "TRUE", "")));
                o.sheet("Settings", List.of("Project_ID", "Project_Name", "Owner_ID", "Manager_ID", "Messenger_Platform", "System_Status",
                                "Data_Version", "Configuration_Version"),
                        List.of(List.of("P-001", "Sample project", "owner", "manager", "IN_APP", "ACTIVE", "1", "1")));
            }
            return write(wb);
        } catch (IOException e) {
            throw new IllegalStateException(e);
        }
    }

    /** Persian headers for the canonical column names. */
    private static List<String> h(String... canonical) {
        return java.util.Arrays.stream(canonical).map(c -> FaNames.COLUMNS.getOrDefault(c, c)).toList();
    }

    // ------------------------------------------------------------------------------------------------- report

    /** Projects, operations, user performance, import history (with errors) and the recent audit trail. */
    @Transactional(readOnly = true)
    public byte[] report(boolean fa) {
        try (Workbook wb = new XSSFWorkbook()) {
            CellStyle head = headStyle(wb);
            Out o = new Out(wb, head, fa);

            ProjectData data = dataService.load();
            List<List<Object>> projects = scheduling.summaries(data, planService.plan(data)).stream()
                    .map(p -> List.<Object>of(p.id(), p.name(), p.priority(),
                            fa ? FaLabels.projectStatus(p.status()) : p.status().name(),
                            when(fa, p.dueDate(), false), p.totalOperations(), round(p.progressPercent()),
                            when(fa, p.finishAt(), true), round(p.tardinessHours())))
                    .toList();
            o.sheet(fa ? "پروژه‌ها" : "Projects",
                    fa ? List.of("شناسه پروژه", "نام", "اولویت", "وضعیت", "سررسید (شمسی)", "تعداد عملیات", "پیشرفت ٪",
                            "پایان پیش‌بینی (شمسی)", "تأخیر (ساعت)")
                            : List.of("Project_ID", "Name", "Priority", "Status", "Due_Date", "Operations", "Progress_%",
                            "Projected_Finish", "Tardiness_Hours"), projects);

            List<List<Object>> ops = operations.all().stream().map(v -> opRow(v, fa)).toList();
            o.sheet(fa ? "عملیات" : "Operations",
                    fa ? List.of("شناسه عملیات", "نام", "پروژه", "منبع", "وضعیت", "تخصیص به", "مدت (ساعت)", "پیشرفت ٪",
                            "مهلت (شمسی)", "پایان پیش‌بینی (شمسی)", "تأخیر", "ساعت تأخیر", "علت انسداد")
                            : List.of("Operation_ID", "Name", "Project", "Resource", "Status", "Assigned_To", "Total_Hours",
                            "Progress_%", "Planned_End", "Projected_End", "Delayed", "Delay_Hours", "Block_Reason"), ops);

            List<List<Object>> perf = dashboards.performance().stream().map(p -> perfRow(p, fa)).toList();
            o.sheet(fa ? "عملکرد کاربران" : "User_Performance",
                    fa ? List.of("شناسه کاربر", "نام", "تخصیص‌یافته", "در حال انجام", "مسدود", "تکمیل‌شده", "ساعت تکمیل‌شده",
                            "به‌موقع", "واقعی ÷ برنامه", "با تأخیر", "مشکلات گزارش‌شده")
                            : List.of("User_ID", "Name", "Assigned", "In_Progress", "Blocked", "Completed", "Completed_Hours",
                            "On_Time_Rate", "Actual_vs_Planned", "Late", "Block_Reports"), perf);

            List<List<Object>> imp = imports.findAllByOrderByImportedAtDesc(PageRequest.of(0, 200)).stream()
                    .map((ImportLog l) -> List.<Object>of(when(fa, l.getImportedAt(), true), l.getActorId(),
                            nz(l.getFileName()), nz(l.getFormat()), fa ? FaLabels.importStatus(l.getStatus()) : l.getStatus(),
                            l.getErrorCount(), nz(l.getDetails())))
                    .toList();
            o.sheet(fa ? "تاریخچه ورود" : "Import_History",
                    fa ? List.of("زمان (شمسی)", "عامل", "فایل", "قالب", "وضعیت", "تعداد خطا", "جزئیات خطا")
                            : List.of("Time", "Actor", "File", "Format", "Status", "Errors", "Error_Details"), imp);

            List<List<Object>> aud = audit.findAllByOrderByOccurredAtDesc(PageRequest.of(0, 1000)).stream()
                    .map(e -> List.<Object>of(e.getEventId(), when(fa, e.getOccurredAt(), true), nz(e.getActorId()),
                            fa ? FaLabels.action(e.getAction()) : e.getAction(),
                            fa ? FaLabels.entity(e.getEntityType()) : e.getEntityType(), nz(e.getEntityId()),
                            nz(e.getPreviousValue()), nz(e.getNewValue()), nz(e.getReason()), nz(e.getRelatedProjectId())))
                    .toList();
            o.sheet(fa ? "ممیزی" : "Audit",
                    fa ? List.of("شناسه رویداد", "زمان (شمسی)", "عامل", "اقدام", "نوع موجودیت", "شناسه موجودیت",
                            "مقدار قبلی", "مقدار جدید", "علت", "شناسه پروژه")
                            : List.of("Event_ID", "Timestamp", "Actor_ID", "Action", "Entity_Type", "Entity_ID", "Previous_Value",
                            "New_Value", "Reason", "Related_Project_ID"), aud);
            return write(wb);
        } catch (IOException e) {
            throw new IllegalStateException(e);
        }
    }

    private static List<Object> opRow(OperationView v, boolean fa) {
        return List.of(v.id(), v.name(), nz(v.projectName()), v.resourceName(), fa ? FaLabels.status(v.status()) : v.status().name(),
                nz(v.assignedUserName()), round(v.totalHours()), v.progressPercent(),
                when(fa, v.plannedEnd(), true), when(fa, v.projectedEnd(), true),
                fa ? FaLabels.yesNo(v.delayed()) : (v.delayed() ? "YES" : "NO"), round(v.delayHours()), nz(v.blockReason()));
    }

    private static List<Object> perfRow(UserPerformance p, boolean fa) {
        return List.of(p.userId(), nz(p.name()), p.assigned(), p.inProgress(), p.blocked(), p.completed(), round(p.completedHours()),
                p.onTimeRate() == null ? "" : round(p.onTimeRate() * 100) + "%",
                p.actualToPlanned() == null ? "" : round(p.actualToPlanned()), p.lateCount(), p.blockReports());
    }

    /** Persian: Solar Hijri date (and time) in Tehran time; English: ISO instant. */
    private static String when(boolean fa, Instant i, boolean withTime) {
        if (i == null) {
            return "";
        }
        if (!fa) {
            return i.toString();
        }
        return withTime ? Jalali.dateTime(i) : Jalali.date(i);
    }

    // ------------------------------------------------------------------------------------------------ plumbing

    private static final class Out {
        private final Workbook wb;
        private final CellStyle head;
        private final boolean rtl;

        Out(Workbook wb, CellStyle head, boolean rtl) {
            this.wb = wb;
            this.head = head;
            this.rtl = rtl;
        }

        void sheet(String name, List<String> headers, List<? extends List<?>> rows) {
            Sheet s = wb.createSheet(name);
            if (rtl) {
                s.setRightToLeft(true);
            }
            Row hr = s.createRow(0);
            for (int i = 0; i < headers.size(); i++) {
                var c = hr.createCell(i);
                c.setCellValue(headers.get(i));
                c.setCellStyle(head);
            }
            int r = 1;
            for (List<?> row : rows) {
                Row x = s.createRow(r++);
                for (int i = 0; i < row.size(); i++) {
                    Object v = row.get(i);
                    if (v instanceof Number n) {
                        x.createCell(i).setCellValue(n.doubleValue());
                    } else if (v != null && !v.toString().isEmpty()) {
                        String text = v.toString();
                        x.createCell(i).setCellValue(neutralize(text.length() > 32000 ? text.substring(0, 32000) : text));
                    }
                }
            }
            for (int i = 0; i < headers.size(); i++) {
                s.setColumnWidth(i, Math.min(60, Math.max(14, headers.get(i).length() + 6)) * 256);
            }
            s.createFreezePane(0, 1);
        }
    }

    /** Prevents spreadsheet formula injection from user-entered text. */
    private static String neutralize(String s) {
        char c = s.charAt(0);
        return (c == '=' || c == '+' || c == '-' || c == '@') && !isNumber(s) ? "'" + s : s;
    }

    private static boolean isNumber(String s) {
        try {
            Double.parseDouble(s);
            return true;
        } catch (NumberFormatException e) {
            return false;
        }
    }

    private static CellStyle headStyle(Workbook wb) {
        CellStyle st = wb.createCellStyle();
        Font f = wb.createFont();
        f.setBold(true);
        st.setFont(f);
        return st;
    }

    private static byte[] write(Workbook wb) throws IOException {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        wb.write(out);
        return out.toByteArray();
    }

    private static String nz(String s) {
        return s == null ? "" : s;
    }

    private static double round(double v) {
        return Math.round(v * 100.0) / 100.0;
    }
}
