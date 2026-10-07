package com.aiso.service;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Persian rendering of the texts the system itself generates (validation and error messages, import findings,
 * notifications, assignment reasons). The source texts stay in English in the code; this class maps them to Persian at
 * the output boundary. Text that matches no rule (for example free text written by users) is returned unchanged.
 * <p>
 * Templates use {@code {n}} for group n as is, {@code {sn}} for an operation status and {@code {mn}} for an
 * assignment method.
 */
public final class FaTranslator {

    private record Rule(Pattern pattern, String template) {
    }

    private static final Map<String, String> STATUS = Map.of(
            "NOT_READY", "آماده نیست", "READY", "آماده", "ASSIGNED", "تخصیص‌یافته", "IN_PROGRESS", "در حال انجام",
            "BLOCKED", "مسدود", "COMPLETED", "تکمیل‌شده", "CANCELLED", "لغوشده");
    private static final Map<String, String> MODE = Map.of("ALGORITHM", "الگوریتم", "LLM", "هوش مصنوعی");

    private static final Pattern TOKEN = Pattern.compile("\\{([smt]?)(\\d)}");
    private static final Pattern PROJECT_SUFFIX = Pattern.compile("; project (.+) \\(priority (\\d+)\\)$");
    private static final List<Rule> RULES = new ArrayList<>();

    private static void r(String regex, String template) {
        RULES.add(new Rule(Pattern.compile(regex, Pattern.DOTALL), template));
    }

    static {
        // ---- validation (Bean Validation) -----------------------------------------------------------------
        r("(\\w+): must not be blank", "{1}: نباید خالی باشد");
        r("(\\w+): must not be empty", "{1}: نباید خالی باشد");
        r("(\\w+): must be less than or equal to (\\d+)", "{1}: باید حداکثر {2} باشد");
        r("(\\w+): must be greater than or equal to (\\d+)", "{1}: باید حداقل {2} باشد");

        // ---- authentication & users -------------------------------------------------------------------------
        r("Invalid user id or password", "شناسه کاربر یا رمز عبور نادرست است");
        r("Current password is incorrect", "رمز عبور فعلی نادرست است");
        r("Password must be at least 8 characters", "رمز عبور باید حداقل ۸ کاراکتر باشد");
        r("The OWNER account cannot be created here", "حساب مالک از این مسیر قابل ایجاد نیست");
        r("User id '(.+)' already exists", "شناسه کاربر «{1}» از قبل وجود دارد");
        r("The OWNER account cannot be demoted or disabled", "حساب مالک را نمی‌توان تنزل داد یا غیرفعال کرد");
        r("Ownership cannot be granted through the API", "واگذاری مالکیت از این مسیر ممکن نیست");
        r("Only the owner can reset the owner's password", "فقط خود مالک می‌تواند رمز مالک را بازنشانی کند");
        r("User (\\S+) still has (\\d+) open task\\(s\\)\\. Reassign or unassign them first\\.",
                "کاربر {1} هنوز {2} وظیفهٔ باز دارد. ابتدا آن‌ها را لغو تخصیص یا دوباره تخصیص دهید.");
        r("User (\\S+) not found", "کاربر {1} پیدا نشد");
        r("Unknown user (\\S+)", "کاربر {1} ناشناس است");
        r("User (\\S+) is not an active executive user", "کاربر {1} یک کاربر اجرایی فعال نیست");
        r("The system is suspended by the owner\\.", "سیستم توسط مالک تعلیق شده است.");
        r("The record was changed by someone else\\. Reload and try again\\.", "این رکورد را شخص دیگری تغییر داده است. صفحه را دوباره بارگذاری کنید.");
        r("Unexpected server error", "خطای غیرمنتظرهٔ سرور");
        r("The server is not reachable", "سرور در دسترس نیست");

        // ---- operations ---------------------------------------------------------------------------------------
        r("Operation (\\S+) not found", "عملیات {1} پیدا نشد");
        r("Operation (\\S+) is (\\w+), expected (\\w+)", "عملیات {1} در وضعیت «{s2}» است، اما باید «{s3}» باشد");
        r("Operation (\\S+) is (\\w+) and cannot be assigned", "عملیات {1} در وضعیت «{s2}» است و قابل تخصیص نیست");
        r("This operation is not assigned to you", "این عملیات به شما تخصیص داده نشده است");
        r("Progress must be between 0 and 100", "پیشرفت باید بین ۰ و ۱۰۰ باشد");
        r("Only assigned or in-progress operations can be reported as blocked", "فقط عملیات «تخصیص‌یافته» یا «در حال انجام» را می‌توان مسدود گزارش کرد");
        r("A reason is required to cancel an operation", "برای لغو عملیات نوشتن علت الزامی است");
        r("A reason is required for a reset", "برای بازنشانی نوشتن علت الزامی است");
        r("A reason is required", "نوشتن علت الزامی است");
        r("A note is required", "نوشتن توضیح الزامی است");
        r("Operation is already (\\w+)", "عملیات از قبل در وضعیت «{s1}» است");
        r("Completion is already approved", "اتمام از قبل تأیید شده است");
        r("Type RESET in the confirm field to proceed", "برای ادامه، عبارت RESET را در کادر تأیید بنویسید");
        r("maxActiveTasksPerUser must be between 1 and 50", "سقف وظایف فعال هر کاربر باید بین ۱ و ۵۰ باشد");
        r("Operation is no longer READY", "عملیات دیگر در وضعیت «آماده» نیست");

        // ---- projects ---------------------------------------------------------------------------------------
        r("Project (\\S+) not found", "پروژه {1} پیدا نشد");
        r("Project (\\S+) still has (\\d+) unfinished operation\\(s\\)\\. Complete or cancel them first\\.",
                "پروژه {1} هنوز {2} عملیات ناتمام دارد. ابتدا آن‌ها را تکمیل یا لغو کنید.");
        r("A ranking is required", "ارسال اولویت‌بندی الزامی است");
        r("Project (\\S+) appears twice in the ranking", "پروژه {1} دو بار در اولویت‌بندی آمده است");
        r("Unknown or inactive project (\\S+) in the ranking", "پروژهٔ {1} در اولویت‌بندی ناشناس یا غیرفعال است");
        r("The ranking must include every active project; missing: (.+)", "اولویت‌بندی باید همهٔ پروژه‌های فعال را شامل شود؛ کم است: {1}");
        r("Due date must be YYYY-MM-DD, a Jalali date like 1405/07/25, or an ISO instant, got '(.*)'",
                "تاریخ سررسید باید به‌صورت میلادی (YYYY-MM-DD)، شمسی (مثل ۱۴۰۵/۰۷/۲۵) یا ISO باشد؛ مقدار دریافتی: «{1}»");

        // ---- Excel import -------------------------------------------------------------------------------------
        r("The file is empty", "فایل خالی است");
        r("Unrecognised workbook\\. Expected the master template \\(sheets Resources, OPC, \\.\\.\\.\\) or the simple layout \\(sheets opc and source\\)\\.",
                "قالب فایل شناخته نشد. باید قالب اصلی (شیت‌های Resources و OPC یا «منابع» و «عملیات») یا قالب ساده (شیت‌های opc و source) باشد.");
        r("The file is not a readable Excel workbook: (.*)", "فایل یک فایل Excel قابل‌خواندن نیست: {1}");
        r("Required sheet '(.+)' is missing", "شیت الزامی «{1}» وجود ندارد");
        r("Sheet '(.+)' is not in the file; existing (.+) data is left unchanged\\.", "شیت «{1}» در فایل نیست؛ داده‌های موجود «{2}» بدون تغییر می‌مانند.");
        r("Required column '(.+)' is missing", "ستون الزامی «{1}» وجود ندارد");
        r("(\\w+) is required", "{1} الزامی است");
        r("Both ids are required", "هر دو شناسه الزامی است");
        r("Capacity must be a whole number >= 1", "ظرفیت باید عدد صحیح و حداقل ۱ باشد");
        r("Simultaneous resources must be >= 1", "تعداد منابع همزمان باید حداقل ۱ باشد");
        r("'(.*)' is not a number", "«{1}» عدد نیست");
        r("Time cannot be negative", "زمان نمی‌تواند منفی باشد");
        r("Quantity cannot be negative", "تعداد نمی‌تواند منفی باشد");
        r("Status must be ACTIVE or INACTIVE", "وضعیت باید «فعال» یا «غیرفعال» باشد");
        r("Unknown status '(.*)'", "وضعیت «{1}» ناشناس است");
        r("Initial status must be empty, NOT_READY, READY, COMPLETED or CANCELLED \\(got (.*)\\)",
                "وضعیت اولیه باید خالی یا یکی از «آماده نیست»، «آماده»، «تکمیل‌شده»، «لغوشده» باشد (دریافت شد: {1})");
        r("Dependency_Type must be FINISH_TO_START or START_TO_START \\(got (.*)\\)",
                "نوع وابستگی باید «پایان به شروع» یا «شروع به شروع» باشد (دریافت شد: {1})");
        r("Is_Mandatory must be TRUE or FALSE", "مقدار «الزامی» باید «بله» یا «خیر» باشد");
        r("Active_Status must be TRUE/FALSE or ACTIVE/INACTIVE", "مقدار «وضعیت فعال» باید «بله/خیر» یا «فعال/غیرفعال» باشد");
        r("Role must be one of OWNER, MANAGER, USER_1, USER_2, USER_3", "نقش باید یکی از «مالک»، «مدیر»، «کاربر ۱»، «کاربر ۲»، «کاربر ۳» باشد");
        r("The Settings sheet must contain exactly one data row", "شیت تنظیمات باید دقیقاً یک ردیف داده داشته باشد");
        r("Duplicate id '(.*)' \\(first used in row (\\d+)\\)", "شناسهٔ «{1}» تکراری است (اولین بار در ردیف {2} آمده)");
        r("Duplicate resource name '(.*)'", "نام منبع «{1}» تکراری است");
        r("Duplicate row number (.*)", "شمارهٔ ردیف {1} تکراری است");
        r("The OWNER account cannot be created or changed by an import", "حساب مالک را نمی‌توان با ورود Excel ایجاد یا تغییر داد");
        r("Only the OWNER can import a MANAGER", "فقط مالک می‌تواند «مدیر» را با ورود Excel اضافه کند");
        r("Only the OWNER can change the MANAGER account", "فقط مالک می‌تواند حساب مدیر را تغییر دهد");
        r("User '(.*)' does not exist or is not an executive user", "کاربر «{1}» وجود ندارد یا کاربر اجرایی نیست");
        r("User '(.*)' does not exist", "کاربر «{1}» وجود ندارد");
        r("Resource '(.*)' does not exist", "منبع «{1}» وجود ندارد");
        r("Resource '(.*)' is not defined in sheet '(.*)'", "منبع «{1}» در شیت «{2}» تعریف نشده است");
        r("Item '(.*)' does not exist in the BOM", "قطعهٔ «{1}» در شیت قطعات (BOM) وجود ندارد");
        r("Operation '(.*)' does not exist", "عملیات «{1}» وجود ندارد");
        r("Predecessor '(.*)' does not exist", "پیش‌نیاز «{1}» وجود ندارد");
        r("Predecessor row (.*) does not exist", "ردیف پیش‌نیاز {1} وجود ندارد");
        r("An operation cannot be its own predecessor", "یک عملیات نمی‌تواند پیش‌نیاز خودش باشد");
        r("Duplicate predecessor (\\S+) for (\\S+)", "پیش‌نیاز {1} برای {2} تکراری است");
        r("Dependency cycle: (.*)\\. A real loop in the process must be resolved by the manager, it is not removed automatically\\.",
                "حلقهٔ وابستگی: {1}. حلقهٔ واقعی در فرایند باید توسط مدیر حل شود؛ سیستم آن را خودکار حذف نمی‌کند.");
        r("Operation (\\S+) is (\\w+); its resource cannot be changed", "عملیات {1} در وضعیت «{s2}» است؛ منبع آن قابل تغییر نیست");
        r("Operation (\\S+) is (\\w+); its predecessors cannot be changed", "عملیات {1} در وضعیت «{s2}» است؛ پیش‌نیازهای آن قابل تغییر نیستند");
        r("(\\d+) existing operation\\(s\\) are not in this file and were left unchanged\\.", "{1} عملیات موجود در این فایل نبودند و بدون تغییر ماندند.");
        r("Operation '(.*)' already belongs to project (.*); operation ids must be unique across projects",
                "عملیات «{1}» از قبل متعلق به پروژهٔ {2} است؛ شناسهٔ عملیات باید در همهٔ پروژه‌ها یکتا باشد");
        r("Predecessor '(.*)' belongs to project (.*); dependencies cannot cross projects",
                "پیش‌نیاز «{1}» متعلق به پروژهٔ {2} است؛ وابستگی بین پروژه‌ها مجاز نیست");
        r("Operation '(.*)' belongs to project (.*)", "عملیات «{1}» متعلق به پروژهٔ {2} است");
        r("Resource (\\S+) is shared by all projects: its capacity changes from (\\d+) to (\\d+)\\.",
                "منبع {1} بین همهٔ پروژه‌ها مشترک است: ظرفیت آن از {2} به {3} تغییر می‌کند.");
        r("Project '(.*)' does not exist", "پروژهٔ «{1}» وجود ندارد");
        r("Project '(.*)' is archived", "پروژهٔ «{1}» بایگانی شده است");
        r("Project id may only contain letters, digits, dot, dash and underscore", "شناسهٔ پروژه فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد");
        r("Project id '(.*)' already exists", "شناسهٔ پروژه «{1}» از قبل وجود دارد");
        r("Several projects are active\\. Choose the target project \\(projectId\\) or create a new one \\(newProjectName\\)\\.",
                "چند پروژه فعال است. پروژهٔ مقصد را انتخاب کنید یا پروژهٔ جدید بسازید.");
        r("Ranking entry '(.*)' is duplicated or not an active project", "مورد «{1}» در اولویت‌بندی تکراری است یا پروژهٔ فعال نیست");
        r("The ranking must list every active project and NEW exactly once", "اولویت‌بندی باید هر پروژهٔ فعال و پروژهٔ جدید را دقیقاً یک بار شامل شود");

        // ---- notifications ------------------------------------------------------------------------------------
        r("New task: (\\S+) \\((.*)\\)", "وظیفهٔ جدید: {1} ({2})");
        r("(\\S+) was taken off your list\\.", "{1} از فهرست وظایف شما برداشته شد.");
        r("(\\S+) was reassigned\\.", "{1} به کاربر دیگری تخصیص داده شد.");
        r("Operation (\\S+) \\((.*)\\) is ready to be executed\\.", "عملیات {1} ({2}) آمادهٔ اجراست.");
        r("(\\d+) operation\\(s\\) became READY: (.*)", "{1} عملیات آماده شد: {2}");
        r("(\\S+) completed (\\S+) \\((.*)\\)", "{1} عملیات {2} ({3}) را تکمیل کرد");
        r("(\\S+) \\((.*)\\) is blocked: (.*)", "عملیات {1} ({2}) مسدود شد: {3}");
        r("(\\S+) \\((.*)\\) was unblocked\\.", "انسداد عملیات {1} ({2}) رفع شد.");
        r("(\\S+) \\((.*)\\) was cancelled: (.*)", "عملیات {1} ({2}) لغو شد: {3}");

        // ---- history notes / audit reasons ----------------------------------------------------------------------
        r("Assigned to (\\S+) - (.*)", "تخصیص به {1} — {t2}");
        r("Assigned to (\\S+)", "تخصیص به {1}");
        r("Unassigned from (\\S+)", "لغو تخصیص از {1}");
        r("Unassigned", "لغو تخصیص");
        r("Approved (ALGORITHM|LLM) proposal: (.*)", "پیشنهاد {m1} تأیید شد: {t2}");
        r("Manual assignment: (.*)", "تخصیص دستی: {t1}");
        r("Manual assignment", "تخصیص دستی");
        r("Manually assigned", "تخصیص دستی انجام شد");
        r("Rejected by manager", "توسط مدیر رد شد");
        r("Not applied: (.*)", "اعمال نشد: {t1}");
        r("Excel import", "ورود از Excel");
        r("All mandatory prerequisites satisfied", "همهٔ پیش‌نیازهای الزامی برقرار شد");
        r("A prerequisite is no longer satisfied", "یک پیش‌نیاز دیگر برقرار نیست");
        r("New project (\\S+) added", "پروژهٔ جدید {1} اضافه شد");

        // ---- assignment: summaries, skip reasons, warnings --------------------------------------------------------
        r("No READY operations\\.", "عملیات آمادهای وجود ندارد.");
        r("(\\d+) of (\\d+) READY operations proposed by the dispatching rule\\.", "{1} از {2} عملیات آماده با قاعدهٔ توزیع پیشنهاد شد.");
        r("(\\d+) of (\\d+) READY operations proposed by the dispatching rule; (\\d+) left unassigned\\.",
                "{1} از {2} عملیات آماده با قاعدهٔ توزیع پیشنهاد شد؛ {3} مورد بدون تخصیص ماند.");
        r("Resource (.+) has no free slot \\((\\d+) of (\\d+) busy\\)", "منبع «{1}» اسلات آزاد ندارد ({2} از {3} مشغول)");
        r("Resource (.+) is inactive", "منبع «{1}» غیرفعال است");
        r("Resource (.+) is at capacity \\((\\d+)/(\\d+)\\)", "ظرفیت منبع «{1}» پر است ({2}/{3})");
        r("Fixed executor (\\S+) is unavailable: (.*)", "مجری ثابت {1} در دسترس نیست: {t2}");
        r("No executive user is below the active-task limit \\((\\d+)\\)", "هیچ کاربر اجرایی زیر سقف وظایف فعال ({1}) نیست");
        r("User (\\S+) is unknown, inactive or not an executive user", "کاربر {1} ناشناس، غیرفعال یا غیراجرایی است");
        r("Operation (\\S+) is fixed to executor (\\S+)", "عملیات {1} به مجری {2} ثابت شده است");
        r("User (\\S+) already has (\\d+) active tasks \\(limit (\\d+)\\)", "کاربر {1} هم‌اکنون {2} وظیفهٔ فعال دارد (سقف {3})");
        r("Operation (\\S+) is not READY", "عملیات {1} در وضعیت «آماده» نیست");
        r("LLM mode failed \\((.*)\\)\\. The algorithm produced these proposals instead\\.",
                "روش هوش مصنوعی ناموفق بود ({t1}). این پیشنهادها با الگوریتم تولید شد.");
        r("ANTHROPIC_API_KEY is not configured on the server", "کلید ANTHROPIC_API_KEY روی سرور تنظیم نشده است");
        r("LLM API call failed: (.*)", "فراخوانی سرویس هوش مصنوعی ناموفق بود: {1}");
        r("AISO_LLM_MODEL is not set on the server", "متغیر AISO_LLM_MODEL روی سرور تنظیم نشده است");
        r("The model declined the request \\(stop reason: refusal\\)", "مدل درخواست را نپذیرفت (refusal)");
        r("The model returned no structured output", "مدل خروجی ساختاریافته‌ای برنگرداند");
        r("LLM assignment failed: (.*)", "تخصیص با هوش مصنوعی ناموفق بود: {t1}");
        r("Dropped (\\S+) -> (\\S+): (.*)", "پیشنهاد {1} ← {2} حذف شد: {t3}");
        r("Ignored duplicate assignment of (\\S+)", "تخصیص تکراری {1} نادیده گرفته شد");
        r("Model suggestion rejected: (.*)", "پیشنهاد مدل رد شد: {t1}");
        r("Not addressed by the model", "مدل به آن نپرداخت");
        r("Left unassigned by the model", "مدل آن را بدون تخصیص گذاشت");
        r("Chosen by the model", "انتخاب مدل");
        r("Given this slot by the project-priority rule", "طبق قاعدهٔ اولویت پروژه این اسلات را گرفت");
        r("Priority rule: (\\S+) \\(project priority (\\d+)\\) took the slot of (\\S+) \\(project priority (\\d+)\\) on (.*)",
                "قاعدهٔ اولویت: {1} (اولویت پروژه {2}) اسلات {3} (اولویت پروژه {4}) را روی «{5}» گرفت");

        // ---- assignment reason produced by the algorithm ----------------------------------------------------------
        r("Rank (\\d+) \\(chain ([\\d.]+) h\\); (.+) slot (\\d+) of (\\d+); (.+?): fixed executor in OPC",
                "رتبه {1} (زنجیره {2} ساعت)؛ «{3}» اسلات {4} از {5}؛ {6}: مجری ثابت در فایل");
        r("Rank (\\d+) \\(chain ([\\d.]+) h\\); (.+) slot (\\d+) of (\\d+); (.+?): responsible user of (.+), load ([\\d.]+) h",
                "رتبه {1} (زنجیره {2} ساعت)؛ «{3}» اسلات {4} از {5}؛ {6}: کاربر مسئول «{7}»، بار {8} ساعت");
        r("Rank (\\d+) \\(chain ([\\d.]+) h\\); (.+) slot (\\d+) of (\\d+); (.+?): lowest load \\(([\\d.]+) h, (\\d+) active\\)",
                "رتبه {1} (زنجیره {2} ساعت)؛ «{3}» اسلات {4} از {5}؛ {6}: کمترین بار ({7} ساعت، {8} وظیفهٔ فعال)");
    }

    private FaTranslator() {
    }

    /** Persian rendering of {@code text}, or {@code text} itself when no rule matches. Multi-part validation texts are split. */
    public static String translate(String text) {
        if (text == null || text.isBlank()) {
            return text;
        }
        Matcher project = PROJECT_SUFFIX.matcher(text);
        if (text.startsWith("Rank ") && project.find()) {
            return translate(text.substring(0, project.start())) + projectSuffix(project.group(1), project.group(2));
        }
        String exact = apply(text);
        if (exact != null) {
            return exact;
        }
        if (text.contains("; ")) {
            String[] parts = text.split("; ");
            StringBuilder out = new StringBuilder();
            boolean any = false;
            for (String part : parts) {
                String t = apply(part);
                any |= t != null;
                out.append(out.length() == 0 ? "" : "؛ ").append(t != null ? t : part);
            }
            if (any) {
                return out.toString();
            }
        }
        return text;
    }

    private static String apply(String text) {
        for (Rule rule : RULES) {
            Matcher m = rule.pattern().matcher(text);
            if (m.matches()) {
                return render(rule.template(), m);
            }
        }
        return null;
    }

    private static String render(String template, Matcher m) {
        Matcher t = TOKEN.matcher(template);
        StringBuilder out = new StringBuilder();
        while (t.find()) {
            int group = Integer.parseInt(t.group(2));
            String v = group <= m.groupCount() ? m.group(group) : null;
            String rendered;
            if (v == null) {
                rendered = "";
            } else if ("s".equals(t.group(1))) {
                rendered = STATUS.getOrDefault(v, v);
            } else if ("m".equals(t.group(1))) {
                rendered = MODE.getOrDefault(v, v);
            } else if ("t".equals(t.group(1))) {
                rendered = translate(v);
            } else {
                rendered = v;
            }
            t.appendReplacement(out, Matcher.quoteReplacement(rendered));
        }
        t.appendTail(out);
        return out.toString();
    }

    /** Group 7/9 of the reason rules is the optional project suffix; this renders it. */
    static String projectSuffix(String name, String priority) {
        return name == null ? "" : "؛ پروژه «" + name + "» (اولویت " + priority + ")";
    }
}
