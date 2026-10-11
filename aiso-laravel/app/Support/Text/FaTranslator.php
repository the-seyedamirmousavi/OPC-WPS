<?php

namespace App\Support\Text;

use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * Persian rendering of the texts the system itself generates (validation and error messages, import findings,
 * notifications, assignment reasons). The source texts stay in English in the code; this class maps them to Persian at
 * the output boundary. Text that matches no rule (for example free text written by users) is returned unchanged.
 *
 * Templates use {n} for group n as is, {sn} for an operation status, {mn} for an assignment method, {tn} for a nested
 * system text and {dn} for a Tehran time written as "yyyy-MM-dd HH:mm" (rendered as a Solar Hijri date and time).
 */
final class FaTranslator
{
    private const STATUS = [
        'NOT_READY' => 'آماده نیست', 'READY' => 'آماده', 'ASSIGNED' => 'تخصیص‌یافته', 'IN_PROGRESS' => 'در حال انجام',
        'BLOCKED' => 'مسدود', 'COMPLETED' => 'تکمیل‌شده', 'CANCELLED' => 'لغوشده',
    ];
    private const MODE = ['ALGORITHM' => 'الگوریتم', 'LLM' => 'هوش مصنوعی'];

    /** [pattern (without anchors, matched as a whole), template]. Order matters: the first match wins. */
    private const RULES = [
        // ---- validation ------------------------------------------------------------------------------------------
        ['(\w+): must not be blank', '{1}: نباید خالی باشد'],
        ['(\w+): must not be empty', '{1}: نباید خالی باشد'],
        ['(\w+): must be less than or equal to (\d+)', '{1}: باید حداکثر {2} باشد'],
        ['(\w+): must be greater than or equal to (\d+)', '{1}: باید حداقل {2} باشد'],
        // ---- authentication & users ------------------------------------------------------------------------------
        ['Invalid user id or password', 'شناسه کاربر یا رمز عبور نادرست است'],
        ['Current password is incorrect', 'رمز عبور فعلی نادرست است'],
        ['Password must be at least 8 characters', 'رمز عبور باید حداقل ۸ کاراکتر باشد'],
        ['The OWNER account cannot be created here', 'حساب مالک از این مسیر قابل ایجاد نیست'],
        ["User id '(.+)' already exists", 'شناسه کاربر «{1}» از قبل وجود دارد'],
        ['The OWNER account cannot be demoted or disabled', 'حساب مالک را نمی‌توان تنزل داد یا غیرفعال کرد'],
        ['Ownership cannot be granted through the API', 'واگذاری مالکیت از این مسیر ممکن نیست'],
        ["Only the owner can reset the owner's password", 'فقط خود مالک می‌تواند رمز مالک را بازنشانی کند'],
        ['User (\S+) still has (\d+) open task\(s\)\. Reassign or unassign them first\.',
            'کاربر {1} هنوز {2} وظیفهٔ باز دارد. ابتدا آن‌ها را لغو تخصیص یا دوباره تخصیص دهید.'],
        ['User (\S+) not found', 'کاربر {1} پیدا نشد'],
        ['Unknown user (\S+)', 'کاربر {1} ناشناس است'],
        ['User (\S+) is not an active executive user', 'کاربر {1} یک کاربر اجرایی فعال نیست'],
        ['The system is suspended by the owner\.', 'سیستم توسط مالک تعلیق شده است.'],
        ['The record was changed by someone else\. Reload and try again\.', 'این رکورد را شخص دیگری تغییر داده است. صفحه را دوباره بارگذاری کنید.'],
        ['Unexpected server error', 'خطای غیرمنتظرهٔ سرور'],
        ['The server is not reachable', 'سرور در دسترس نیست'],
        // ---- operations ------------------------------------------------------------------------------------------
        ['Operation (\S+) not found', 'عملیات {1} پیدا نشد'],
        ['Operation (\S+) is (\w+), expected (\w+)', 'عملیات {1} در وضعیت «{s2}» است، اما باید «{s3}» باشد'],
        ['Operation (\S+) is (\w+) and cannot be assigned', 'عملیات {1} در وضعیت «{s2}» است و قابل تخصیص نیست'],
        ['This operation is not assigned to you', 'این عملیات به شما تخصیص داده نشده است'],
        ['Progress must be between 0 and 100', 'پیشرفت باید بین ۰ و ۱۰۰ باشد'],
        ['Only assigned or in-progress operations can be reported as blocked', 'فقط عملیات «تخصیص‌یافته» یا «در حال انجام» را می‌توان مسدود گزارش کرد'],
        ['A reason is required to cancel an operation', 'برای لغو عملیات نوشتن علت الزامی است'],
        ['A reason is required for a reset', 'برای بازنشانی نوشتن علت الزامی است'],
        ['A reason is required', 'نوشتن علت الزامی است'],
        ['A note is required', 'نوشتن توضیح الزامی است'],
        ['Operation is already (\w+)', 'عملیات از قبل در وضعیت «{s1}» است'],
        ['Completion is already approved', 'اتمام از قبل تأیید شده است'],
        ['Type RESET in the confirm field to proceed', 'برای ادامه، عبارت RESET را در کادر تأیید بنویسید'],
        ['maxActiveTasksPerUser must be between 1 and 50', 'سقف وظایف فعال هر کاربر باید بین ۱ و ۵۰ باشد'],
        ['Operation is no longer READY', 'عملیات دیگر در وضعیت «آماده» نیست'],
        // ---- manager FORCE ---------------------------------------------------------------------------------------
        ['FORCE rejected: (.+)', 'اجبار مدیر پذیرفته نشد: {t1}'],
        ['Forced start (\S+ \S+) of (\S+) is in the past', 'شروع اجباری {2} در {d1} در گذشته است'],
        ['(\S+) cannot start at (\S+ \S+): its predecessors \((.*)\) allow (\S+ \S+) at the earliest',
            '{1} نمی‌تواند در {d2} شروع شود: پیش‌نیازهایش ({3}) زودتر از {d4} اجازه نمی‌دهند'],
        ['(\S+) cannot start at (\S+ \S+): resource (.+) \(capacity (\d+)\) is taken by (.*) at that time',
            '{1} نمی‌تواند در {d2} شروع شود: منبع «{3}» (ظرفیت {4}) در آن زمان در اختیار {5} است'],
        ['No schedule was found in which (\S+) starts at (\S+ \S+); it is planned for (\S+ \S+)\. A feasible schedule may still exist\.',
            'برنامه‌ای پیدا نشد که در آن {1} در {d2} شروع شود؛ برای {d3} برنامه‌ریزی شده است. ممکن است برنامهٔ شدنی دیگری وجود داشته باشد.'],
        ['(\S+) cannot start at (\S+ \S+): the FORCE on (\S+) \(start (\S+ \S+)\) needs it earlier',
            '{1} نمی‌تواند در {d2} شروع شود: اجبار قدیمی‌تر روی {3} (شروع {d4}) آن را زودتر لازم دارد'],
        ['No schedule was found in which (\S+) starts at (\S+ \S+) without making the older FORCE on (\S+) impossible; it is planned for (\S+ \S+)\.',
            'برنامه‌ای پیدا نشد که در آن {1} در {d2} شروع شود و اجبار قدیمی‌تر روی {3} ناممکن نشود؛ برای {d4} برنامه‌ریزی شده است.'],
        ['Operation (\S+) is (\w+); its start cannot be forced', 'عملیات {1} در وضعیت «{s2}» است؛ زمان شروع آن را نمی‌توان اجباری کرد'],
        ['Operation (\S+) is (\w+) and cannot be forced', 'عملیات {1} در وضعیت «{s2}» است و قابل اجبار نیست'],
        ['Resource (.+) of (\S+) is inactive', 'منبع «{1}» برای {2} غیرفعال است'],
        ['Operation (\S+) is (\w+) with executor (\S+); the executor cannot change now',
            'عملیات {1} در وضعیت «{s2}» و در دست {3} است؛ مجری اکنون قابل تغییر نیست'],
        ['Give a forced start, a forced executor or both', 'زمان شروع اجباری، مجری اجباری یا هر دو را وارد کنید'],
        ['It would make the FORCE on (\S+) impossible: (.+)', 'اجبار روی {1} را ناممکن می‌کند: {t2}'],
        ['The forced executor (\S+) is no longer an active executive user', 'مجری اجباری {1} دیگر کاربر اجرایی فعال نیست'],
        ['Overrides the executor fixed in the OPC sheet \((\S+)\)', 'مجری تعیین‌شده در برگهٔ OPC ({1}) نادیده گرفته می‌شود'],
        ['User (\S+) already has (\d+) active tasks \(limit (\d+)\); the FORCE overrides the limit',
            'کاربر {1} هم‌اکنون {2} وظیفهٔ فعال دارد (سقف {3})؛ اجبار مدیر از این سقف عبور می‌کند'],
        ['Project (.+) \(priority (\d+)\) would finish (\S+) h later', 'پروژهٔ «{1}» (اولویت {2}) {3} ساعت دیرتر تمام می‌شود'],
        ['Operation (\S+) is assigned to (\S+); it will be reassigned to (\S+)', 'عملیات {1} به {2} تخصیص دارد؛ به {3} منتقل می‌شود'],
        ['(\d+) already assigned operation\(s\) would move', '{1} عملیات تخصیص‌یافته جابه‌جا می‌شود'],
        ['(\d+) operation\(s\) run ahead of higher-priority work to make the forced start possible',
            '{1} عملیات برای شدنی شدن شروع اجباری، جلوتر از کارهای پراولویت‌تر انجام می‌شود'],
        ['Operation (\S+) is forced to executor (\S+) by the manager\. Change or remove the FORCE first\.',
            'عملیات {1} به اجبار مدیر به {2} سپرده شده است. ابتدا اجبار را تغییر دهید یا حذف کنید.'],
        ['Operation (\S+) is forced to executor (\S+) by the manager', 'عملیات {1} به اجبار مدیر به {2} سپرده شده است'],
        ['Forced executor (\S+) is unavailable: (.*)', 'مجری اجباری {1} در دسترس نیست: {t2}'],
        ['No FORCE on operation (\S+)', 'اجباری روی عملیات {1} وجود ندارد'],
        ["Start must be a date and time like 2026-10-08 14:30, 1405/07/16 14:30 or an ISO instant, got '(.*)'",
            'زمان شروع باید تاریخ و ساعت باشد، مانند ۱۴۰۵/۰۷/۱۶ ۱۴:۳۰، اما «{1}» دریافت شد'],
        ['Planned start of (\S+) moved to (\S+ \S+) by a manager FORCE', 'شروع برنامه‌ریزی‌شدهٔ {1} با اجبار مدیر به {d2} منتقل شد'],
        ['FORCE: (.*)', 'اجبار مدیر: {1}'],
        // ---- projects --------------------------------------------------------------------------------------------
        ['Project (\S+) not found', 'پروژه {1} پیدا نشد'],
        ['Project (\S+) still has (\d+) unfinished operation\(s\)\. Complete or cancel them first\.',
            'پروژه {1} هنوز {2} عملیات ناتمام دارد. ابتدا آن‌ها را تکمیل یا لغو کنید.'],
        ['A ranking is required', 'ارسال اولویت‌بندی الزامی است'],
        ['Project (\S+) appears twice in the ranking', 'پروژه {1} دو بار در اولویت‌بندی آمده است'],
        ['Unknown or inactive project (\S+) in the ranking', 'پروژهٔ {1} در اولویت‌بندی ناشناس یا غیرفعال است'],
        ['The ranking must include every active project; missing: (.+)', 'اولویت‌بندی باید همهٔ پروژه‌های فعال را شامل شود؛ کم است: {1}'],
        ["Due date must be YYYY-MM-DD, a Jalali date like 1405/07/25, or an ISO instant, got '(.*)'",
            'تاریخ سررسید باید به‌صورت میلادی (YYYY-MM-DD)، شمسی (مثل ۱۴۰۵/۰۷/۲۵) یا ISO باشد؛ مقدار دریافتی: «{1}»'],
        // ---- Excel import ----------------------------------------------------------------------------------------
        ['The file is empty', 'فایل خالی است'],
        ['Unrecognised workbook\. Expected the master template \(sheets Resources, OPC, \.\.\.\) or the simple layout \(sheets opc and source\)\.',
            'قالب فایل شناخته نشد. باید قالب اصلی (شیت‌های Resources و OPC یا «منابع» و «عملیات») یا قالب ساده (شیت‌های opc و source) باشد.'],
        ['The file is not a readable Excel workbook: (.*)', 'فایل یک فایل Excel قابل‌خواندن نیست: {1}'],
        ["Required sheet '(.+)' is missing", 'شیت الزامی «{1}» وجود ندارد'],
        ["Sheet '(.+)' is not in the file; existing (.+) data is left unchanged\.", 'شیت «{1}» در فایل نیست؛ داده‌های موجود «{2}» بدون تغییر می‌مانند.'],
        ["Required column '(.+)' is missing", 'ستون الزامی «{1}» وجود ندارد'],
        ['(\w+) is required', '{1} الزامی است'],
        ['Both ids are required', 'هر دو شناسه الزامی است'],
        ['Capacity must be a whole number >= 1', 'ظرفیت باید عدد صحیح و حداقل ۱ باشد'],
        ['Simultaneous resources must be >= 1', 'تعداد منابع همزمان باید حداقل ۱ باشد'],
        ["'(.*)' is not a number", '«{1}» عدد نیست'],
        ['Time cannot be negative', 'زمان نمی‌تواند منفی باشد'],
        ['Quantity cannot be negative', 'تعداد نمی‌تواند منفی باشد'],
        ['Status must be ACTIVE or INACTIVE', 'وضعیت باید «فعال» یا «غیرفعال» باشد'],
        ["Unknown status '(.*)'", 'وضعیت «{1}» ناشناس است'],
        ['Initial status must be empty, NOT_READY, READY, COMPLETED or CANCELLED \(got (.*)\)',
            'وضعیت اولیه باید خالی یا یکی از «آماده نیست»، «آماده»، «تکمیل‌شده»، «لغوشده» باشد (دریافت شد: {1})'],
        ['Dependency_Type must be FINISH_TO_START or START_TO_START \(got (.*)\)',
            'نوع وابستگی باید «پایان به شروع» یا «شروع به شروع» باشد (دریافت شد: {1})'],
        ['Is_Mandatory must be TRUE or FALSE', 'مقدار «الزامی» باید «بله» یا «خیر» باشد'],
        ['Active_Status must be TRUE/FALSE or ACTIVE/INACTIVE', 'مقدار «وضعیت فعال» باید «بله/خیر» یا «فعال/غیرفعال» باشد'],
        ['Role must be one of OWNER, MANAGER, USER_1, USER_2, USER_3', 'نقش باید یکی از «مالک»، «مدیر»، «کاربر ۱»، «کاربر ۲»، «کاربر ۳» باشد'],
        ['The Settings sheet must contain exactly one data row', 'شیت تنظیمات باید دقیقاً یک ردیف داده داشته باشد'],
        ["Duplicate id '(.*)' \(first used in row (\d+)\)", 'شناسهٔ «{1}» تکراری است (اولین بار در ردیف {2} آمده)'],
        ["Duplicate resource name '(.*)'", 'نام منبع «{1}» تکراری است'],
        ['Duplicate row number (.*)', 'شمارهٔ ردیف {1} تکراری است'],
        ['The OWNER account cannot be created or changed by an import', 'حساب مالک را نمی‌توان با ورود Excel ایجاد یا تغییر داد'],
        ['Only the OWNER can import a MANAGER', 'فقط مالک می‌تواند «مدیر» را با ورود Excel اضافه کند'],
        ['Only the OWNER can change the MANAGER account', 'فقط مالک می‌تواند حساب مدیر را تغییر دهد'],
        ["User '(.*)' does not exist or is not an executive user", 'کاربر «{1}» وجود ندارد یا کاربر اجرایی نیست'],
        ["User '(.*)' does not exist", 'کاربر «{1}» وجود ندارد'],
        ["Resource '(.*)' does not exist", 'منبع «{1}» وجود ندارد'],
        ["Resource '(.*)' is not defined in sheet '(.*)'", 'منبع «{1}» در شیت «{2}» تعریف نشده است'],
        ["Item '(.*)' does not exist in the BOM", 'قطعهٔ «{1}» در شیت قطعات (BOM) وجود ندارد'],
        ["Operation '(.*)' does not exist", 'عملیات «{1}» وجود ندارد'],
        ["Predecessor '(.*)' does not exist", 'پیش‌نیاز «{1}» وجود ندارد'],
        ['Predecessor row (.*) does not exist', 'ردیف پیش‌نیاز {1} وجود ندارد'],
        ['An operation cannot be its own predecessor', 'یک عملیات نمی‌تواند پیش‌نیاز خودش باشد'],
        ['Duplicate predecessor (\S+) for (\S+)', 'پیش‌نیاز {1} برای {2} تکراری است'],
        ['Dependency cycle: (.*)\. A real loop in the process must be resolved by the manager, it is not removed automatically\.',
            'حلقهٔ وابستگی: {1}. حلقهٔ واقعی در فرایند باید توسط مدیر حل شود؛ سیستم آن را خودکار حذف نمی‌کند.'],
        ['Operation (\S+) is (\w+); its resource cannot be changed', 'عملیات {1} در وضعیت «{s2}» است؛ منبع آن قابل تغییر نیست'],
        ['Operation (\S+) is (\w+); its predecessors cannot be changed', 'عملیات {1} در وضعیت «{s2}» است؛ پیش‌نیازهای آن قابل تغییر نیستند'],
        ['(\d+) existing operation\(s\) are not in this file and were left unchanged\.', '{1} عملیات موجود در این فایل نبودند و بدون تغییر ماندند.'],
        ["Operation '(.*)' already belongs to project (.*); operation ids must be unique across projects",
            'عملیات «{1}» از قبل متعلق به پروژهٔ {2} است؛ شناسهٔ عملیات باید در همهٔ پروژه‌ها یکتا باشد'],
        ["Predecessor '(.*)' belongs to project (.*); dependencies cannot cross projects",
            'پیش‌نیاز «{1}» متعلق به پروژهٔ {2} است؛ وابستگی بین پروژه‌ها مجاز نیست'],
        ["Operation '(.*)' belongs to project (.*)", 'عملیات «{1}» متعلق به پروژهٔ {2} است'],
        ['Resource (\S+) is shared by all projects: its capacity changes from (\d+) to (\d+)\.',
            'منبع {1} بین همهٔ پروژه‌ها مشترک است: ظرفیت آن از {2} به {3} تغییر می‌کند.'],
        ["Project '(.*)' does not exist", 'پروژهٔ «{1}» وجود ندارد'],
        ["Project '(.*)' is archived", 'پروژهٔ «{1}» بایگانی شده است'],
        ['Project id may only contain letters, digits, dot, dash and underscore', 'شناسهٔ پروژه فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد'],
        ["Project id '(.*)' already exists", 'شناسهٔ پروژه «{1}» از قبل وجود دارد'],
        ['Several projects are active\. Choose the target project \(projectId\) or create a new one \(newProjectName\)\.',
            'چند پروژه فعال است. پروژهٔ مقصد را انتخاب کنید یا پروژهٔ جدید بسازید.'],
        ["Ranking entry '(.*)' is duplicated or not an active project", 'مورد «{1}» در اولویت‌بندی تکراری است یا پروژهٔ فعال نیست'],
        ['The ranking must list every active project and NEW exactly once', 'اولویت‌بندی باید هر پروژهٔ فعال و پروژهٔ جدید را دقیقاً یک بار شامل شود'],
        // ---- notifications ---------------------------------------------------------------------------------------
        ['New task: (\S+) \((.*)\)', 'وظیفهٔ جدید: {1} ({2})'],
        ['(\S+) was taken off your list\.', '{1} از فهرست وظایف شما برداشته شد.'],
        ['(\S+) was reassigned\.', '{1} به کاربر دیگری تخصیص داده شد.'],
        ['Operation (\S+) \((.*)\) is ready to be executed\.', 'عملیات {1} ({2}) آمادهٔ اجراست.'],
        ['(\d+) operation\(s\) became READY: (.*)', '{1} عملیات آماده شد: {2}'],
        ['(\S+) completed (\S+) \((.*)\)', '{1} عملیات {2} ({3}) را تکمیل کرد'],
        ['(\S+) \((.*)\) is blocked: (.*)', 'عملیات {1} ({2}) مسدود شد: {3}'],
        ['(\S+) \((.*)\) was unblocked\.', 'انسداد عملیات {1} ({2}) رفع شد.'],
        ['(\S+) \((.*)\) was cancelled: (.*)', 'عملیات {1} ({2}) لغو شد: {3}'],
        // ---- history notes / audit reasons -----------------------------------------------------------------------
        ['Assigned to (\S+) - (.*)', 'تخصیص به {1} — {t2}'],
        ['Assigned to (\S+)', 'تخصیص به {1}'],
        ['Unassigned from (\S+)', 'لغو تخصیص از {1}'],
        ['Unassigned', 'لغو تخصیص'],
        ['Approved (ALGORITHM|LLM) proposal: (.*)', 'پیشنهاد {m1} تأیید شد: {t2}'],
        ['Manual assignment: (.*)', 'تخصیص دستی: {t1}'],
        ['Manual assignment', 'تخصیص دستی'],
        ['Manually assigned', 'تخصیص دستی انجام شد'],
        ['Rejected by manager', 'توسط مدیر رد شد'],
        ['Not applied: (.*)', 'اعمال نشد: {t1}'],
        ['Excel import', 'ورود از Excel'],
        ['All mandatory prerequisites satisfied', 'همهٔ پیش‌نیازهای الزامی برقرار شد'],
        ['A prerequisite is no longer satisfied', 'یک پیش‌نیاز دیگر برقرار نیست'],
        ['New project (\S+) added', 'پروژهٔ جدید {1} اضافه شد'],
        // ---- assignment: summaries, skip reasons, warnings -------------------------------------------------------
        ['No READY operations\.', 'عملیات آمادهای وجود ندارد.'],
        ['(\d+) of (\d+) READY operations proposed by the dispatching rule\.', '{1} از {2} عملیات آماده با قاعدهٔ توزیع پیشنهاد شد.'],
        ['(\d+) of (\d+) READY operations proposed by the dispatching rule; (\d+) left unassigned\.',
            '{1} از {2} عملیات آماده با قاعدهٔ توزیع پیشنهاد شد؛ {3} مورد بدون تخصیص ماند.'],
        ['Resource (.+) has no free slot \((\d+) of (\d+) busy\)', 'منبع «{1}» اسلات آزاد ندارد ({2} از {3} مشغول)'],
        ['Resource (.+) is inactive', 'منبع «{1}» غیرفعال است'],
        ['Resource (.+) is at capacity \((\d+)/(\d+)\)', 'ظرفیت منبع «{1}» پر است ({2}/{3})'],
        ['Fixed executor (\S+) is unavailable: (.*)', 'مجری ثابت {1} در دسترس نیست: {t2}'],
        ['No executive user is below the active-task limit \((\d+)\)', 'هیچ کاربر اجرایی زیر سقف وظایف فعال ({1}) نیست'],
        ['User (\S+) is unknown, inactive or not an executive user', 'کاربر {1} ناشناس، غیرفعال یا غیراجرایی است'],
        ['Operation (\S+) is fixed to executor (\S+)', 'عملیات {1} به مجری {2} ثابت شده است'],
        ['User (\S+) already has (\d+) active tasks \(limit (\d+)\)', 'کاربر {1} هم‌اکنون {2} وظیفهٔ فعال دارد (سقف {3})'],
        ['Operation (\S+) is not READY', 'عملیات {1} در وضعیت «آماده» نیست'],
        ['LLM mode failed \((.*)\)\. The algorithm produced these proposals instead\.',
            'روش هوش مصنوعی ناموفق بود ({t1}). این پیشنهادها با الگوریتم تولید شد.'],
        ['ANTHROPIC_API_KEY is not configured on the server', 'کلید ANTHROPIC_API_KEY روی سرور تنظیم نشده است'],
        ['LLM API call failed: (.*)', 'فراخوانی سرویس هوش مصنوعی ناموفق بود: {1}'],
        ['AISO_LLM_MODEL is not set on the server', 'متغیر AISO_LLM_MODEL روی سرور تنظیم نشده است'],
        ['The model declined the request \(stop reason: refusal\)', 'مدل درخواست را نپذیرفت (refusal)'],
        ['The model returned no structured output', 'مدل خروجی ساختاریافته‌ای برنگرداند'],
        ['LLM assignment failed: (.*)', 'تخصیص با هوش مصنوعی ناموفق بود: {t1}'],
        ['Dropped (\S+) -> (\S+): (.*)', 'پیشنهاد {1} ← {2} حذف شد: {t3}'],
        ['Ignored duplicate assignment of (\S+)', 'تخصیص تکراری {1} نادیده گرفته شد'],
        ['Model suggestion rejected: (.*)', 'پیشنهاد مدل رد شد: {t1}'],
        ['Not addressed by the model', 'مدل به آن نپرداخت'],
        ['Left unassigned by the model', 'مدل آن را بدون تخصیص گذاشت'],
        ['Chosen by the model', 'انتخاب مدل'],
        ['Given this slot by the project-priority rule', 'طبق قاعدهٔ اولویت پروژه این اسلات را گرفت'],
        ['Priority rule: (\S+) \(project priority (\d+)\) took the slot of (\S+) \(project priority (\d+)\) on (.*)',
            'قاعدهٔ اولویت: {1} (اولویت پروژه {2}) اسلات {3} (اولویت پروژه {4}) را روی «{5}» گرفت'],
        // ---- assignment reason produced by the algorithm ---------------------------------------------------------
        ['Rank (\d+) \(chain ([\d.]+) h\); (.+) slot (\d+) of (\d+); (.+?): fixed executor in OPC',
            'رتبه {1} (زنجیره {2} ساعت)؛ «{3}» اسلات {4} از {5}؛ {6}: مجری ثابت در فایل'],
        ['Rank (\d+) \(chain ([\d.]+) h\); (.+) slot (\d+) of (\d+); (.+?): forced by the manager',
            'رتبه {1} (زنجیره {2} ساعت)؛ «{3}» اسلات {4} از {5}؛ {6}: به اجبار مدیر'],
        ['Rank (\d+) \(chain ([\d.]+) h\); (.+) slot (\d+) of (\d+); (.+?): responsible user of (.+), load ([\d.]+) h',
            'رتبه {1} (زنجیره {2} ساعت)؛ «{3}» اسلات {4} از {5}؛ {6}: کاربر مسئول «{7}»، بار {8} ساعت'],
        ['Rank (\d+) \(chain ([\d.]+) h\); (.+) slot (\d+) of (\d+); (.+?): lowest load \(([\d.]+) h, (\d+) active\)',
            'رتبه {1} (زنجیره {2} ساعت)؛ «{3}» اسلات {4} از {5}؛ {6}: کمترین بار ({7} ساعت، {8} وظیفهٔ فعال)'],
    ];

    private function __construct()
    {
    }

    /** Persian rendering of $text, or $text itself when no rule matches. Multi-part validation texts are split. */
    public static function translate(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }
        if (str_starts_with($text, 'Rank ')
            && preg_match('/; project (.+) \(priority (\d+)\)\z/us', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            return self::translate(substr($text, 0, $m[0][1])) . self::projectSuffix($m[1][0], $m[2][0]);
        }
        $exact = self::apply($text);
        if ($exact !== null) {
            return $exact;
        }
        if (str_contains($text, '; ')) {
            $out = '';
            $any = false;
            foreach (explode('; ', $text) as $part) {
                $t = self::apply($part);
                $any = $any || $t !== null;
                $out .= ($out === '' ? '' : '؛ ') . ($t ?? $part);
            }
            if ($any) {
                return $out;
            }
        }

        return $text;
    }

    private static function apply(string $text): ?string
    {
        foreach (self::RULES as [$pattern, $template]) {
            // "~" delimiter: several rules contain a literal "/" (for example "(\d+)/(\d+)")
            if (preg_match('~\A(?:' . $pattern . ')\z~us', $text, $m) === 1) {
                return self::render($template, $m);
            }
        }

        return null;
    }

    /** @param string[] $m match groups (index 0 = whole match) */
    private static function render(string $template, array $m): string
    {
        return preg_replace_callback('/\{([smtd]?)(\d)\}/u', static function (array $t) use ($m): string {
            $group = (int) $t[2];
            $v = $m[$group] ?? null;
            if ($v === null || $v === '') {
                return '';
            }

            return match ($t[1]) {
                's' => self::STATUS[$v] ?? $v,
                'm' => self::MODE[$v] ?? $v,
                't' => self::translate($v),
                'd' => self::jalaliTime($v),
                default => $v,
            };
        }, $template);
    }

    /** "2026-10-08 14:30" (Tehran) becomes "1405/07/16 14:30" in Persian digits; anything else is unchanged. */
    private static function jalaliTime(string $v): string
    {
        try {
            $t = CarbonImmutable::createFromFormat('Y-m-d H:i', $v, Jalali::TEHRAN);
            if ($t === false) {
                return $v;
            }

            return Jalali::dateTime($t);
        } catch (\Throwable) {
            return $v;
        }
    }

    /** Group 7/9 of the reason rules is the optional project suffix; this renders it. */
    public static function projectSuffix(?string $name, string $priority): string
    {
        return $name === null ? '' : '؛ پروژه «' . $name . '» (اولویت ' . $priority . ')';
    }
}
