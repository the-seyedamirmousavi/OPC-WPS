# AISO — سناریوهای تست (Test Scenarios)

> نسخهٔ سند: ۱.۰ — مبتنی بر کد فعلی مخزن (Backend: Spring Boot 4.1 / Frontend: Next.js 16).
> این سند از روی **پیاده‌سازی واقعی** نوشته شده است، نه فقط از روی مستندات. هر جا پیاده‌سازی با مستندات (`AISO.docx`، `README.md`، `docs/DESIGN.md`) فرق دارد، با علامت **⚠ تفاوت** مشخص شده است.
> `docs/DESIGN.md` نام فایل طراحی است (در درخواست «DESGIN.md» آمده بود؛ چنین فایلی وجود ندارد).

**راهنمای علامت‌ها**

| علامت | معنی |
|---|---|
| ✅ | خروجی مورد انتظار با اجرای واقعی روی نسخهٔ فعلی **تأیید شده** است (HTTP یا UI) |
| 🔎 | از روی کد استخراج شده و در بازبینی خودکار **اجرا نشده**؛ هنگام تست دستی باید تأیید شود |
| ⚠ تفاوت | رفتار واقعی با مستندات فرق دارد |
| 🚫 | قابلیتِ ذکرشده در مستندات/درخواست، در کد **وجود ندارد** |
| P0 / P1 / P2 | اولویت: P0 بحرانی، P1 مهم، P2 معمولی |

---

## فهرست

1. محیط تست · 2. داده‌های تست · 3. احراز هویت · 4. نقش‌ها و مجوزها · 5. مدیریت پروژه · 6. ورود Excel · 7. عملیات/وظایف · 8. زمان‌بندی · 9. الگوریتم تخصیص · 10. تأیید مدیر · 11. LLM · 12. گردش کار کاربر اجرایی · 13. امنیت · 14. UI/UX · 15. سناریوی جامع End-to-End · 16. رگرسیون · 17. قالب گزارش باگ · 18. چک‌لیست نهایی · 19. شکاف‌ها و تفاوت‌ها · 20. فایل‌های تست · 21. چند پروژهٔ همزمان و فارسی/تقویم شمسی

---

# ۱. محیط تست (Test Environment)

## ۱.۱ آدرس‌ها و پورت‌ها

| مورد | مقدار پیش‌فرض | توضیح |
|---|---|---|
| Backend (Spring Boot) | `http://localhost:8080` | با `SERVER_PORT` عوض می‌شود. روی بعضی سیستم‌ها پورت ۸۰۸۰ اشغال است (در بررسی ما اشغال بود و ۸۰۹۰ استفاده شد) |
| Frontend (Next.js) | `http://localhost:3000` | مرورگر فقط با همین آدرس کار می‌کند؛ Frontend درخواست‌های `/api/*` را به Backend پروکسی می‌کند |
| آدرس Backend برای Frontend | `AISO_API_URL` (پیش‌فرض `http://localhost:8080`) | اگر Backend روی پورت دیگری است این متغیر را قبل از اجرای Frontend تنظیم کنید |
| پایگاه داده | H2 فایلی: `backend/data/aiso` (پیش‌فرض) | برای تست تمیز: `AISO_DB_URL=jdbc:h2:mem:qa;MODE=PostgreSQL;DATABASE_TO_LOWER=TRUE;DB_CLOSE_DELAY=-1` (دادهٔ حافظه‌ای، با هر اجرا صفر). PostgreSQL با `AISO_DB_URL=jdbc:postgresql://...` |
| مهاجرت دیتابیس | Flyway — `backend/src/main/resources/db/migration/V1__init.sql` | `ddl-auto=validate` |

## ۱.۲ متغیرهای محیطی Backend

| متغیر | پیش‌فرض | کاربرد در تست |
|---|---|---|
| `SERVER_PORT` | 8080 | تغییر پورت |
| `AISO_DB_URL` / `AISO_DB_USER` / `AISO_DB_PASSWORD` | H2 فایلی / `sa` / خالی | انتخاب دیتابیس |
| `AISO_JWT_SECRET` | تصادفی در هر اجرا | کمتر از ۳۲ کاراکتر ⇒ Backend بالا نمی‌آید؛ بدون مقدار ⇒ با هر ری‌استارت همهٔ توکن‌ها باطل |
| `AISO_SEED_PASSWORD` | `ChangeMe!123` | رمز کاربران seed (فقط اولین اجرا، وقتی جدول کاربران خالی است) |
| `ANTHROPIC_API_KEY` | خالی | روشن شدن روش LLM (بخش ۱۱) |
| `AISO_LLM_MODEL` | (خالی) | شناسهٔ مدل؛ برای روش LLM **همراه با کلید الزامی** است (پیش‌فرض داخلی ندارد) |
| `AISO_LLM_TIMEOUTSECONDS` 🔎 | 120 | (binding آزاد Spring برای `aiso.llm.timeout-seconds`) تست timeout |
| `AISO_LLM_FALLBACKTOALGORITHM` 🔎 | true | `false` ⇒ خطای ۵۰۲ به‌جای fallback |

متغیرهای Frontend: `AISO_API_URL`؛ و `AISO_INSECURE_COOKIES=1` اگر Frontend را با `next start` روی HTTP و روی آدرسی غیر از localhost اجرا می‌کنید (در حالت production کوکی `secure` است).

## ۱.۳ اجرا

```powershell
./scripts/start-backend.ps1     # ترمینال ۱
./scripts/start-frontend.ps1    # ترمینال ۲  → http://localhost:3000
```

برای تست‌های API در این سند از **Git Bash** و `curl` استفاده شده است. ابتدا این توابع کمکی را یک‌بار در همان ترمینال بزنید:

```bash
API=http://localhost:8080/api          # اگر پورت عوض شده اینجا را عوض کنید
PW='ChangeMe!123'                       # یا مقدار AISO_SEED_PASSWORD
login() { curl -s -X POST $API/auth/login -H 'Content-Type: application/json' \
          -d "{\"userId\":\"$1\",\"password\":\"${2:-$PW}\"}" | sed -E 's/.*"token":"([^"]+)".*/\1/'; }
api()   { m=$1; p=$2; t=$3; shift 3; curl -s -w '\nHTTP %{http_code}\n' -X $m "$API$p" \
          -H "Authorization: Bearer $t" -H 'Content-Type: application/json' "$@"; }
upload(){ curl -s -w '\nHTTP %{http_code}\n' -X POST "$API/import?apply=${3:-true}" \
          -H "Authorization: Bearer $1" -F "file=@$2"; }
reset() { api POST /admin/reset $OWNER -d '{"reason":"qa","confirm":"RESET"}'; }
OWNER=$(login owner); MGR=$(login manager); U1=$(login user1); U2=$(login user2); U3=$(login user3)
```

> توکن‌ها ۸ ساعت اعتبار دارند. اگر Backend بدون `AISO_JWT_SECRET` ری‌استارت شد، دوباره `login` بزنید.

## ۱.۴ کاربران تست (seed)

با اولین اجرا (وقتی جدول کاربران خالی است) ساخته می‌شوند. رمز همه یکی است (`ChangeMe!123` مگر `AISO_SEED_PASSWORD` تنظیم شده باشد).

| User ID | نام | نقش | کاربرد در تست |
|---|---|---|---|
| `owner` | System Owner | OWNER | مالک؛ همهٔ دسترسی‌ها |
| `manager` | Project Manager | MANAGER | مدیر پروژه |
| `user1` | Executive User 1 | USER_1 | کاربر اجرایی |
| `user2` | Executive User 2 | USER_2 | کاربر اجرایی |
| `user3` | Executive User 3 | USER_3 | کاربر اجرایی |

نقش‌های موجود در کد: `OWNER`, `MANAGER`, `USER_1`, `USER_2`, `USER_3` (فقط `USER_*` «اجرایی» محسوب می‌شوند).

## ۱.۵ فایل‌های نمونه

فایل‌های Excel در پوشهٔ **`test-data/`** هستند و با `python test-data/generate_test_data.py` (فقط کتابخانهٔ استاندارد پایتون) دوباره ساخته می‌شوند. فهرست کامل در بخش ۲۰. علاوه بر آن:

| فایل | توضیح |
|---|---|
| `simple excel.xlsx` (ریشهٔ مخزن) | نمونهٔ واقعی شما (قالب ساده، شیت‌های `opc` و `source`، ۱۶ عملیات، ۶ منبع). نسخهٔ همسان آن داخل Backend و با دکمهٔ «بارگذاری داده نمونه» قابل بارگذاری است |
| قالب Master | از صفحهٔ «ورود اطلاعات Excel» ← «دریافت قالب» (API: `GET /api/import/template`) |

## ۱.۶ ⚠ پیش‌نیاز مهم: بازنشانی بین سناریوها

سناریوهای زمان‌بندی و تخصیص هر کدام یک فایل جدا دارند. **قبل از هر سناریو** دادهٔ عملیاتی را پاک کنید:
UI: کنسول مالک ← «کنترل داده» ← «بازنشانی داده‌های عملیاتی» ← نوشتن علت و عبارت `RESET`.
API: `reset` (تابع بالا).
بازنشانی فقط عملیات، منابع، قطعات، پیش‌نیازها، گزارش‌ها، پیشنهادها و اعلان‌ها را پاک می‌کند؛ **کاربران، تنظیمات و Audit باقی می‌مانند**. (کاربری که غیرفعال کرده‌اید غیرفعال می‌ماند — پس از تست‌های ASG-08..10 دوباره فعالش کنید.)

---

# ۲. داده‌های تست (Test Data)

## ۲.۱ داده‌های ثابت

| مورد | مقدار |
|---|---|
| کاربران | بخش ۱.۴ |
| سقف وظایف فعال هر کاربر (`maxActiveTasksPerUser`) | پیش‌فرض ۳ (قابل تغییر ۱..۵۰ توسط Owner) |
| امتیاز مسئول منبع (`responsible-bonus-hours`) | ۸ ساعت |
| تعریف مدت یک عملیات | `Preparation + Transport + Setup + Direct` (ساعت). در فایل‌های تست فقط `Direct_Time` پر است |

## ۲.۲ داده‌های قابل ورود دستی (برای تست‌هایی که فایل نمی‌خواهند)

| پروژه | عملیات | مدت (ساعت) | وابستگی | منبع (ظرفیت) |
|---|---|---|---|---|
| `P-001` «AISO Project» (پروژهٔ پیش‌فرضِ اولین ورود؛ چند پروژه در بخش ۲۱) | OP-1 «Cut plates» | 6 | — | R-CUT «Cutting» (1) |
| | OP-2 «Weld frame» | 8 | OP-1 | R-WELD «Welding» (2) — مسئول: `user1` |
| | OP-3 «Paint frame» | 4 | OP-2 | R-PAINT «Painting» (1) |

این همان فایل `I01-valid.xlsx` است. مجموع ساعت = 18؛ مسیر بحرانی = OP-1 → OP-2 → OP-3 (18 ساعت).

## ۲.۳ مجموعهٔ دادهٔ نمونهٔ واقعی (`simple excel.xlsx`)

۱۶ عملیات. عملیات بدون پیش‌نیاز (= READY پس از ورود): ردیف‌های 1، 4، 9، 10، 11 (شناسه‌ها `OP-001`, `OP-004`, `OP-009`, `OP-010`, `OP-011`). شش منبع: برونسپاری(1)، جوشکاری(2)، نقاشی(1)، خرید(1)، ماشینکاری(1)، مونتاژ(2). مجموع ساعت = ۲۵۵.
زنجیرهٔ بحرانی: OP-004 (36) → OP-005 (4) → OP-006 (16) → OP-007 (24) → OP-008 (4) → OP-014 (12) → OP-015 (16) = **۱۱۲ ساعت**.

---

# ۳. تست‌های احراز هویت (Authentication)

پیاده‌سازی: `POST /api/auth/login` (userId+password) ⇒ JWT (HS256، اعتبار ۴۸۰ دقیقه). Frontend توکن را در کوکی `aiso_token` با ویژگی `httpOnly` و `SameSite=Strict` نگه می‌دارد (JavaScript صفحه به آن دسترسی ندارد). مسیرهای UI با `proxy.ts` محافظت می‌شوند؛ مجوز واقعی همیشه در Backend بررسی می‌شود.

| ID | P | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|
| AUTH-01 | P0 | UI: `/login` ← User ID=`owner`، رمز صحیح ← «ورود» | هدایت به `/owner`؛ نوار بالا نام «System Owner» و نقش «مالک» ✅ |
| AUTH-02 | P0 | همین کار با `manager` / `user1` | `manager`→`/manager` ، `user1`→`/my` (صفحهٔ اصلی `/` بر اساس نقش هدایت می‌کند) ✅ |
| AUTH-03 | P0 | رمز اشتباه برای `owner` | پیام قرمز «Invalid user id or password»؛ API ⇒ **401** ✅ |
| AUTH-04 | P0 | User ID ناموجود (`ghost`) | **همان** پیام و همان 401 (افشا نشدن وجود/عدم وجود کاربر) ✅ |
| AUTH-05 | P1 | فیلدها خالی (API) | **400** با `userId: must not be blank; password: must not be blank` ✅ (در UI فیلدها `required` هستند) |
| AUTH-06 | P1 | کاربر غیرفعال با رمز درست (بخش ۴: غیرفعال‌سازی `user3`) | لاگین رد می‌شود: 401 با همان پیام عمومی ✅ |
| AUTH-07 | P0 | بعد از لاگین، صفحه را F5 کنید و تب را ببندید/باز کنید (`http://localhost:3000/`) | کاربر وارد می‌ماند (کوکی ۸ ساعته)؛ دوباره لاگین نمی‌خواهد |
| AUTH-08 | P0 | بدون لاگین مستقیم `http://localhost:3000/manager` را باز کنید | هدایت به `/login` (`proxy.ts`) ✅ |
| AUTH-09 | P1 | بعد از لاگین، `/login` را باز کنید | هدایت به `/` و سپس صفحهٔ نقش |
| AUTH-10 | P0 | منوی کاربر ← «خروج» | هدایت به `/login`؛ کوکی پاک می‌شود؛ بازگشت (Back) به صفحهٔ داخلی دوباره به `/login` می‌رود |
| AUTH-11 | P1 | ⚠ **رفتار واقعی logout**: توکن (JWT) را از قبل ذخیره کنید (`OLD=$U1`)، در UI خروج بزنید، سپس `api GET /my/tasks $OLD` | **200** — logout فقط کوکی را پاک می‌کند؛ JWT بدون وضعیت (stateless) است و تا انقضا یا غیرفعال‌شدن کاربر معتبر می‌ماند. 🔎 (طبق کد: `DELETE /api/session`) — مستند کنید که «ابطال سمت سرور» وجود ندارد |
| AUTH-12 | P0 | `api GET /operations ""` (بدون هدر Authorization) | **401** ✅ |
| AUTH-13 | P0 | توکن ساختگی `abc.def.ghi` | **401** ✅ |
| AUTH-14 | P0 | توکن معتبر با چهار حرف آخرِ امضا عوض‌شده | **401** ✅ |
| AUTH-15 | P1 | منقضی شدن: Backend را با `AISO_JWT_TTLMINUTES=1` 🔎 اجرا کنید، یک دقیقه صبر کنید، با توکن قدیمی درخواست بدهید | 401 |
| AUTH-16 | P1 | تغییر رمز: منوی کاربر ← «تغییر رمز عبور» (رمز فعلی صحیح، جدید ≥ ۸ کاراکتر) | پیام «رمز عبور تغییر کرد»؛ لاگین بعدی با رمز جدید موفق و با قدیمی 401 |
| AUTH-17 | P1 | تغییر رمز با رمز فعلی غلط (API `/auth/change-password`) | **400** `Current password is incorrect` ✅ |
| AUTH-18 | P2 | رمز جدید کوتاه (`short`) | **400** `Password must be at least 8 characters` ✅ |
| AUTH-19 | P1 | کاربر غیرفعال‌شده با **توکن قبلی** (مثلاً `user3`): Owner او را غیرفعال کند، سپس `api GET /my/tasks $U3` | **401** بلافاصله (نقش و فعال‌بودن در هر درخواست از دیتابیس خوانده می‌شود) ✅ |
| AUTH-20 | P2 | ری‌استارت Backend بدون `AISO_JWT_SECRET` | همهٔ توکن‌های قبلی 401 می‌شوند؛ UI به `/login` می‌رود |

---

# ۴. تست‌های نقش و مجوز (Role & Authorization)

## ۴.۱ ماتریس واقعی دسترسی (از `@PreAuthorize` و سرویس‌ها)

| قابلیت (Endpoint) | OWNER | MANAGER | USER_1..3 |
|---|:-:|:-:|:-:|
| مشاهدهٔ داشبورد مالک `GET /dashboard/owner` | ✅ | ❌ | ❌ |
| مشاهدهٔ داشبورد مدیر `GET /dashboard/manager` | ✅ | ✅ | ❌ |
| داشبورد کاربر `GET /dashboard/user` ، `GET /my/tasks` | ❌ | ❌ | ✅ |
| فهرست کاربران `GET /users` | ✅ | ✅ | ❌ |
| ساخت/ویرایش کاربر، ریست رمز (`POST/PATCH /users…`) | ✅ | ❌ | ❌ |
| تنظیمات: خواندن `GET /settings` | ✅ | ✅ | ❌ |
| تنظیمات: ویرایش `PATCH /settings` | ✅ | ❌ | ❌ |
| تغییر روش تخصیص `PUT /settings/assignment-mode` | ✅ | ✅ | ❌ |
| تعلیق/فعال‌سازی سیستم، Reset، داده نمونه | ✅ | ❌ | ❌ |
| ورود Excel، قالب، تاریخچه `/import/*` | ✅ | ✅ | ❌ |
| فهرست عملیات `GET /operations` | ✅ | ✅ | ❌ |
| جزئیات عملیات `GET /operations/{id}` | ✅ | ✅ | فقط عملیات **تخصیص‌یافته به خودش** |
| پیشنهاد/تأیید/رد تخصیص `/assignments/*` | ✅ | ✅ | ❌ |
| تخصیص دستی، لغو تخصیص، رفع انسداد، لغو عملیات، تأیید اتمام | ✅ | ✅ | ❌ |
| شروع، پیشرفت، اتمام، گزارش مشکل (`start/progress/complete/block`) | ❌ | ❌ | ✅ (فقط عملیات خودش) |
| توضیح `POST /operations/{id}/comment` | ✅ | ✅ | فقط عملیات خودش |
| گزارش‌ها `/reports/*`، `/audit`، `/notifications/failed` | ✅ | ✅ | ❌ |
| اعلان‌های خود `/notifications*`، تغییر رمز خود | ✅ | ✅ | ✅ |

> ⚠ **تفاوت/نکته:** `start/progress/complete/block` برای OWNER و MANAGER نیز ممنوع است (حتی اگر عملیات به آن‌ها تخصیص داده شود، چون تخصیص فقط به `USER_*` ممکن است). مستندات (`AISO.docx §6`) نوشته «مدیر تخصیص و بررسی می‌کند» که با این پیاده‌سازی سازگار است.

## ۴.۲ تست‌های UI (برای هر نقش)

| ID | P | نقش | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|---|
| ROL-01 | P0 | OWNER | لاگین؛ منوی کناری را بخوانید | ۹ گزینه: کنسول مالک، داشبورد مدیر، تخصیص وظایف، عملیات، برنامه‌ریزی، گزارش‌ها، ورود اطلاعات Excel، کاربران، گزارش ممیزی ✅ |
| ROL-02 | P0 | MANAGER | لاگین؛ منو | ۷ گزینه (بدون «کنسول مالک» و «کاربران») ✅ |
| ROL-03 | P0 | USER_1 | لاگین؛ منو | فقط «وظایف من» ✅ |
| ROL-04 | P0 | MANAGER | در آدرس‌بار `/owner` و `/owner/users` را باز کنید | هدایت به `/manager` (پنهان‌سازی سمت UI) |
| ROL-05 | P0 | USER_1 | `/manager` ، `/import` ، `/audit` را باز کنید | هدایت به `/my` |
| ROL-06 | P1 | OWNER | صفحهٔ «کاربران» ← «افزودن کاربر» (id=`qa1`، نام، نقش `USER_1`، رمز ≥ 8) | کاربر در جدول ظاهر می‌شود؛ لاگین با او ممکن است؛ در Audit `USER_CREATED` ثبت می‌شود |
| ROL-07 | P1 | OWNER | تغییر نقش `qa1` به «مدیر» از لیست کشویی | ذخیره می‌شود؛ منوی `qa1` در لاگین بعدی ۷ گزینه‌ای است |
| ROL-08 | P1 | OWNER | غیرفعال‌کردن `user1` وقتی **وظیفهٔ باز** دارد | خطا: `User user1 still has N open task(s). Reassign or unassign them first.` (409) ✅ |
| ROL-09 | P1 | OWNER | ردیف `owner` در صفحهٔ کاربران | نقش و تیک فعال برای مالک **غیرقابل تغییر** (غیرفعال در UI) |
| ROL-10 | P0 | USER_1 | وظیفهٔ مربوط به `user2` را با آدرس `/my` ببینید | فقط وظایف خودش نمایش داده می‌شود |

## ۴.۳ تست‌های API (Backend، بدون اتکا به UI)

> همه با `curl`/توابع بخش ۱.۳. نتیجهٔ ✅ یعنی در بازبینی خودکار دقیقاً همین کد HTTP دیده شد.

| ID | P | درخواست | نتیجهٔ مورد انتظار |
|---|---|---|---|
| ROL-11 | P0 | `api GET /operations $U1` | 403 ✅ |
| ROL-12 | P0 | `api GET /dashboard/manager $U1` | 403 ✅ |
| ROL-13 | P0 | `api GET /users $U1` | 403 ✅ |
| ROL-14 | P0 | `api PUT /settings/assignment-mode $U1 -d '{"mode":"LLM"}'` | 403 ✅ |
| ROL-15 | P0 | `api POST /assignments/suggest $U1 -d '{}'` | 403 ✅ |
| ROL-16 | P0 | `api GET /audit $U1` | 403 ✅ |
| ROL-17 | P0 | `api POST /users $MGR -d '{"id":"x1","fullName":"X","role":"USER_1","password":"abcdefgh1"}'` | 403 ✅ |
| ROL-18 | P0 | `api PATCH /settings $MGR -d '{"projectName":"hack"}'` | 403 ✅ |
| ROL-19 | P0 | `api GET /dashboard/owner $MGR` | 403 ✅ |
| ROL-20 | P0 | `api POST /admin/reset $MGR -d '{"reason":"x","confirm":"RESET"}'` | 403 ✅ |
| ROL-21 | P0 | `api PUT /admin/system-status $MGR -d '{"status":"SUSPENDED","reason":"x"}'` | 403 ✅ |
| ROL-22 | P1 | `api POST /users/owner/reset-password $MGR -d '{"newPassword":"abcdefgh1"}'` | 403 ✅ |
| ROL-23 | P1 | `api GET /users $MGR` ، `api PUT /settings/assignment-mode $MGR -d '{"mode":"ALGORITHM"}'` | هر دو 200 ✅ (مدیر مجاز است) |
| ROL-24 | P1 | `api GET /dashboard/user $MGR` | 403 ✅ (فقط اجرایی) |
| ROL-25 | P0 | `api POST /operations/A/start $MGR -d '{}'` (بعد از بارگذاری A1 و تأیید تخصیص) | 403 ✅ |
| ROL-26 | P0 | `api POST /users $OWNER -d '{"id":"boss","fullName":"B","role":"OWNER","password":"abcdefgh1"}'` | 400 `The OWNER account cannot be created here` ✅ |
| ROL-27 | P0 | `api PATCH /users/owner $OWNER -d '{"active":false}'` | 403 `The OWNER account cannot be demoted or disabled` ✅ |
| ROL-28 | P0 | `api PATCH /users/user1 $OWNER -d '{"role":"OWNER"}'` | 403 `Ownership cannot be granted through the API` ✅ |
| ROL-29 | P1 | ساخت کاربر با رمز کوتاه | 400 `Password must be at least 8 characters` ✅ |
| ROL-30 | P1 | ساخت کاربر با id تکراری (`user1`) | 409 `User id 'user1' already exists` ✅ |
| ROL-31 | P1 | `api PATCH /settings $OWNER -d '{"maxActiveTasksPerUser":0}'` | 400 `maxActiveTasksPerUser must be between 1 and 50` ✅ |

---

# ۵. تست‌های مدیریت پروژه (Project Management)

> ## توضیح
> از نسخهٔ ۰.۲ **چند پروژه** پشتیبانی می‌شود (جدول `project`؛ هر عملیات متعلق به یک پروژه). پروژه با **ورود فایل Excel** ساخته می‌شود (نه با فرم دستی)، سپس در صفحهٔ «پروژه‌ها و اولویت‌ها» رتبه‌بندی، نام‌گذاری، سررسید و بایگانی می‌شود. تست‌های کامل چندپروژه‌ای در **بخش ۲۱** (MPR) است. تنظیم «نام پروژه» در کنسول مالک اکنون **نام کارخانه/سیستم** است و فقط نام پروژهٔ پیش‌فرضِ اولین ورود را تعیین می‌کند (`projectId` پیش‌فرض `P-001`). تست‌های زیر دربارهٔ همین تنظیمات سیستم‌اند.

| ID | P | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|
| PRJ-01 | P1 | Owner: کنسول مالک | زیرعنوان «AISO Project (P-001)» (تنظیمات سیستم)؛ کارت‌ها: وضعیت سیستم «فعال»، «نسخهٔ داده / پیکربندی» (`v0 / c2` پس از seed) |
| PRJ-02 | P1 | Owner: کارت «تنظیمات» ← نام پروژه را به `Factory Line 7` ← «ذخیره» | پیام «ذخیره شد»؛ زیرعنوان صفحه بعد از رفرش داده‌ها «Factory Line 7 (P-001)»؛ شمارندهٔ پیکربندی (`c`) یک واحد بالا می‌رود؛ Audit: `SETTINGS_CHANGED` با مقدار قبلی/جدید |
| PRJ-03 | P0 | Manager: `api PATCH /settings $MGR …` | 403 ✅ (ویرایش فقط Owner) |
| PRJ-04 | P1 | نام پروژه را خالی بفرستید: `api PATCH /settings $OWNER -d '{"projectName":""}'` 🔎 | تغییری اعمال نمی‌شود (فیلد خالی نادیده گرفته می‌شود)؛ نام قبلی می‌ماند |
| PRJ-05 | P1 | `maxActiveTasksPerUser` = 0 و 51 | 400 با پیام بازهٔ ۱..۵۰ ✅ |
| PRJ-06 | P0 | ماندگاری: بعد از PRJ-02 صفحه را F5 کنید؛ Backend را ری‌استارت کنید (با دیتابیس **فایلی**) | نام جدید می‌ماند. (با `jdbc:h2:mem` پس از ری‌استارت همه‌چیز به حالت اولیه برمی‌گردد — انتظار طبیعی) |
| PRJ-07 | P1 | بازنشانی دادهٔ عملیاتی (Owner) | نام پروژه، کاربران، تنظیمات، Audit **باقی** می‌مانند؛ `نسخهٔ داده` به `0` برمی‌گردد ✅ |
| PRJ-08 | P2 | ورود Excel قالب Master با شیت `Settings` (`Project_ID`, `Project_Name`, `Messenger_Platform`, `Data_Version`) 🔎 | مقادیر روی تنظیمات اعمال می‌شوند؛ `Owner_ID/Manager_ID` ناموجود ⇒ خطا با شیت/ردیف/ستون. (فایل نمونه‌ای برای این حالت تولید نشده؛ از قالب دانلودی استفاده کنید) |
| PRJ-09 | P1 | تغییر روش تخصیص (Owner یا Manager) و سپس refresh | روش جدید ماندگار است (`assignmentMode` در دیتابیس)؛ Audit: `ASSIGNMENT_MODE_CHANGED` |
| PRJ-10 | P2 | Owner: «تعلیق سیستم» با علت، سپس بازفعال‌سازی | بخش ۱۳ (SEC-12..14) |

---

# ۶. تست‌های ورود Excel (Excel Import)

پیاده‌سازی: `POST /api/import?apply=false|true` (فقط Owner/Manager). دو قالب را خودکار تشخیص می‌دهد:
1. **Master**: شیت‌های `Resources`, `OPC` (الزامی)، `BOM`, `Predecessors`, `Users`, `Settings` (اختیاری).
2. **Simple**: شیت‌های `opc` و `source` با سرستون فارسی (مثل `simple excel.xlsx`).

قواعد واقعی: اعتبارسنجی **دو لایه** است — اگر در لایهٔ سلولی/ساختاری (ستون ناموجود، عدد نامعتبر، enum نامعتبر) خطا باشد، لایهٔ ارجاع‌ها (منبع/پیش‌نیاز ناموجود، تکراری، چرخه) در همان پاس **اجرا نمی‌شود**؛ یعنی بعد از رفع خطاهای لایهٔ اول ممکن است خطاهای جدید ظاهر شود (⚠ مورد IMP-09 را ببینید). ورود **همه‌یا‌هیچ** است: هیچ «ورود جزئی» وجود ندارد. وضعیت‌ها: `REJECTED` / `VALID` (فقط اعتبارسنجی) / `APPLIED`. HTTP همیشه 200 است، مگر فایل کاملاً خالی (400).

> قبل از هر تست: `reset`. در UI: صفحهٔ «ورود اطلاعات Excel» ← انتخاب فایل ← «فقط اعتبارسنجی» یا «اعتبارسنجی و ورود».

| ID | P | فایل | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|---|
| IMP-01 | P0 | `test-data/I01-valid.xlsx` | «فقط اعتبارسنجی» | وضعیت VALID؛ نوار سبز؛ **هیچ عملیاتی در دیتابیس نیست** (صفحهٔ عملیات خالی) ✅ |
| IMP-02 | P0 | `I01-valid.xlsx` | «اعتبارسنجی و ورود» | APPLIED؛ شمارش: منابع +3، عملیات +3، پیش‌نیاز +2؛ هشدارهای «Sheet 'BOM'/'Users'/'Settings' is not in the file…» ✅. در «عملیات»: OP-1 «آماده»، OP-2 و OP-3 «آماده نیست»؛ OP-2 «در انتظار OP-1» |
| IMP-03 | P0 | `../simple excel.xlsx` یا `backend/src/main/resources/samples/simple-sample.xlsx` | ورود | فرمت `SIMPLE`؛ ۱۶ عملیات؛ ۵ عملیات «آماده» و ۱۱ «آماده نیست» ✅؛ شناسه‌ها `OP-001..OP-016`؛ منابع `RES-001..006` |
| IMP-04 | P1 | Owner: کنسول مالک | «بارگذاری داده نمونه» | همان نتیجهٔ IMP-03 + پیام «داده نمونه بارگذاری شد» ✅ |
| IMP-05 | P0 | `I02-missing-column.xlsx` (OPC بدون `Resource_ID`) | ورود | REJECTED؛ خطا: شیت `OPC`، ردیف 1، ستون `Resource_ID`: `Required column 'Resource_ID' is missing` ✅؛ دیتابیس بدون تغییر |
| IMP-06 | P0 | `I03-missing-sheet.xlsx` (فاقد `OPC`) | ورود | REJECTED: `Unrecognised workbook. Expected the master template (sheets Resources, OPC, ...) or the simple layout (sheets opc and source).` ✅ |
| IMP-07 | P0 | `I04-invalid-values.xlsx` | ورود | REJECTED با **۶ خطا** ✅: `Resources 2 Capacity: Capacity must be a whole number >= 1` ؛ `Resources 3 Capacity: 'two' is not a number` ؛ `OPC 2 Direct_Time: 'abc' is not a number` ؛ `OPC 3 Direct_Time: Time cannot be negative` ؛ `OPC 4 Operation_Status: Unknown status 'RUNNING'` ؛ `OPC 5 Operation_Status: Initial status must be empty, NOT_READY, READY, COMPLETED or CANCELLED (got IN_PROGRESS)` |
| IMP-08 | P0 | `I05-bad-references.xlsx` | ورود | REJECTED ✅: `OPC 4 Resource_ID: Resource 'R-NOPE' does not exist` ؛ `Predecessors 2: Predecessor 'GHOST' does not exist` ؛ `Predecessors 3: An operation cannot be its own predecessor` ؛ `Predecessors 5: Duplicate predecessor A for B` |
| IMP-09 | P1 | `I05b-bad-dependency-type.xlsx` | ورود | REJECTED: `Predecessors 2 Dependency_Type: Dependency_Type must be FINISH_TO_START or START_TO_START (got SIDEWAYS)` ✅. ⚠ **توجه:** اگر همین خطا در فایل IMP-08 بود، خطاهای ارجاعِ IMP-08 در همان پاس گزارش **نمی‌شدند** (اعتبارسنجی دو لایه) |
| IMP-10 | P0 | `I06-cycle.xlsx` (A←C، B←A، C←B) | ورود | REJECTED: `Dependency cycle: A -> C -> B -> A. A real loop in the process must be resolved by the manager, it is not removed automatically.` روی ردیف ۲ شیت `Predecessors` ✅ |
| IMP-11 | P0 | `I07-duplicates.xlsx` | ورود | REJECTED: `Resources 3 Resource_ID: Duplicate id 'R1' (first used in row 2)` و `OPC 3 Operation_ID: Duplicate id 'A' (first used in row 2)` ✅ |
| IMP-12 | P0 | `I11-empty.xlsx` (۰ بایت) | ورود | **HTTP 400** `The file is empty` (این تنها حالت غیر-200) ✅ |
| IMP-13 | P0 | `I12-malformed.xlsx` (سرآیند ZIP ولی محتوای خراب) | ورود | REJECTED: `The file is not a readable Excel workbook: No valid entries or contents found, this is not a valid OOXML (Office Open XML) file` ✅ |
| IMP-14 | P1 | `I13-not-excel.txt` | ورود (فیلتر انتخاب فایل UI فقط `.xlsx/.xls` را نشان می‌دهد؛ «All files» را انتخاب کنید یا API) | REJECTED: `…unsupported file type: UNKNOWN` ✅ |
| IMP-15 | P1 | `I08-headers-only.xlsx` | ورود | APPLIED با همهٔ شمارش‌ها صفر (فایل بدون ردیف داده خطا نیست) ✅ |
| IMP-16 | P0 | `I09-partial-bad-row.xlsx` (۴ ردیف درست + ۱ ردیف با منبع ناموجود) | ورود | REJECTED با یک خطا (`OPC 6 Resource_ID`) و **هیچ‌کدام از ۴ ردیف درست وارد نمی‌شود** (عملیات = 0) ✅ ← «ورود جزئی» وجود ندارد |
| IMP-17 | P1 | `I01-valid.xlsx` دو بار پشت‌سرهم | ورود دوم | شمارش دوم: عملیات +0 / به‌روزرسانی 3؛ منابع به‌روزرسانی 3؛ پیش‌نیازها +2 (حذف و ساخت مجدد). عملیات تکراری ساخته نمی‌شود ✅ (upsert بر پایهٔ شناسه) |
| IMP-18 | P0 | `I10-users-owner.xlsx` (شیت Users با نقش OWNER و MANAGER) — با کاربر **OWNER** | ورود | REJECTED: `Users 2 Role: The OWNER account cannot be created or changed by an import` ✅ |
| IMP-19 | P1 | همان فایل با کاربر **MANAGER** | ورود | علاوه بر خطای بالا: `Only the OWNER can import a MANAGER` ✅ |
| IMP-20 | P0 | `I01-valid.xlsx` با `user1` | `upload $U1 …` | **403** ✅ |
| IMP-21 | P1 | قالب دانلودی (`GET /import/template`) | ورود مستقیم (VALID سپس APPLIED) | معتبر است؛ ۳ کاربر جدید `USER_1..3` با **رمز موقت** ساخته و فقط همان لحظه در نتیجه نمایش داده می‌شود (کادر «حساب‌های جدید»). ⚠ این‌ها با کاربران seed (`user1..3`) متفاوتند |
| IMP-22 | P1 | ساختار تغییر در عملیات آغازشده: ابتدا I01 را وارد و OP-1 را تا «تکمیل‌شده» ببرید؛ سپس فایلی با منبع متفاوت برای OP-1 وارد کنید | REJECTED: `Operation OP-1 is COMPLETED; its resource cannot be changed` (در تست خودکار `AisoFlowIntegrationTest` نیز پوشش دارد) |
| IMP-23 | P2 | تاریخچهٔ ورود | بعد از چند ورود، پایین صفحهٔ ورود Excel | هر تلاش (REJECTED/VALID/APPLIED) با نام فایل، تعداد خطا و «جزئیات» ثبت است ✅ (`GET /import/history`) |
| IMP-24 | P2 | شیت‌های ناقص | شیت `Predecessors` در فایل نباشد | پیش‌نیازهای موجود دست‌نخورده می‌مانند (هشدار «left unchanged»)؛ وجود شیت (حتی خالی) یعنی «جایگزینی پیش‌نیازهای عملیات فایل» |

**دربارهٔ تراکنش/Rollback:** پیاده‌سازی در یک تراکنش (`TransactionTemplate`) اعمال می‌شود و اعتبارسنجی کامل **قبل از** هر نوشتن انجام می‌شود (IMP-16). ایجاد خطای وسط تراکنش به‌صورت دستی ممکن نیست؛ رفتار Rollback با ۳۸ تست خودکار غیرمستقیم پوشش داده شده است، **نه** با تست مستقیم شکست وسط تراکنش. 🔎

---

# ۷. تست‌های عملیات/وظایف (Operations)

> ## ⚠ تفاوت‌ها
> 🚫 **ایجاد و ویرایش دستی عملیات (UI یا API) وجود ندارد.** عملیات، منابع، قطعات و پیش‌نیازها فقط از راه ورود Excel ساخته/به‌روزرسانی می‌شوند. «ویرایش» = ورود دوبارهٔ فایل اصلاح‌شده (IMP-17، IMP-22). مدیر می‌تواند فقط: تخصیص دستی، لغو تخصیص، رفع انسداد، تأیید اتمام، لغو عملیات و ثبت توضیح.
> وضعیت‌های واقعی: `NOT_READY, READY, ASSIGNED, IN_PROGRESS, BLOCKED, COMPLETED, CANCELLED` (🚫 وضعیت `PENDING` وجود ندارد).

داده: `A1-three-equal.xlsx` (عملیات A,B,C روی منبع R با ظرفیت ۵). ابتدا `reset` و ورود؛ سپس «تخصیص وظایف ← پیشنهاد تخصیص ← تأیید همه» ⇒ A→user1، B→user2، C→user3.

| ID | P | کاربر | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|---|
| OPR-01 | P0 | Manager | صفحهٔ «عملیات» | ۳ ردیف؛ فیلتر وضعیت/منبع/کاربر و جستجو (روی شناسه و نام) کار می‌کند؛ شمارندهٔ «۳ از ۳ عملیات» |
| OPR-02 | P1 | Manager | روی ردیف A کلیک کنید | پنجرهٔ جزئیات: وضعیت، منبع، مدت، «زنجیره پس از آن»، «بازه مبنا» و «بازه پیش‌بینی‌شده»، پیش‌نیازها/وابسته‌ها، سوابق |
| OPR-03 | P0 | user1 | `/my` ← کارت A ← «شروع کار» | A: `ASSIGNED → IN_PROGRESS` ✅؛ «شروع» در سوابق؛ `startedAt` پر می‌شود |
| OPR-04 | P0 | user1 | شروع دوباره | 409 `Operation A is IN_PROGRESS, expected ASSIGNED` ✅ |
| OPR-05 | P0 | user1 | ثبت اتمام روی A قبل از «شروع کار» (API: `api POST /operations/A/complete $U1 -d '{}'`) | 409 `Operation A is ASSIGNED, expected IN_PROGRESS` ✅ (روی عملیات دیگران: 403) |
| OPR-06 | P1 | user1 | پیشرفت 150 / −1 | 400 `percent: must be less than or equal to 100` / `…greater than or equal to 0` ✅ |
| OPR-07 | P1 | user1 | پیشرفت 40٪ با توضیح | 200؛ نوار پیشرفت 40٪؛ در سوابق «پیشرفت 40٪» |
| OPR-08 | P0 | user1 | پیشرفت قبل از شروع | 409 (مثل OPR-05) ✅ |
| OPR-09 | P0 | user1 | «گزارش مشکل» با علت `material missing` | A: `BLOCKED`؛ مدیر اعلان «A (Task A) is blocked: material missing» می‌گیرد؛ نمایش در «عملیات مسدود» داشبورد مدیر |
| OPR-10 | P1 | user1 | گزارش مشکل با علت خالی | 400 `reason: must not be blank` ✅ |
| OPR-11 | P0 | user1 | اتمام وقتی BLOCKED | 409 `Operation A is BLOCKED, expected IN_PROGRESS` ✅ |
| OPR-12 | P0 | Manager | روی A ← «رفع انسداد» | برمی‌گردد به **وضعیتِ قبل از انسداد** (`IN_PROGRESS`) ✅ |
| OPR-13 | P1 | Manager | رفع انسداد دوباره | 409 `Operation A is IN_PROGRESS, expected BLOCKED` ✅ |
| OPR-14 | P0 | user1 | «ثبت اتمام» | A: `COMPLETED`، پیشرفت 100٪؛ تأییدیه مدیر هنوز **نیست** (`completionApproved=false`) ✅ |
| OPR-15 | P1 | user1 | اتمام دوباره | 409 `Operation A is COMPLETED, expected IN_PROGRESS` ✅ |
| OPR-16 | P0 | Manager | «تأیید اتمام» | 200؛ نمایش «اتمام توسط مدیر تأیید شده است» ✅ |
| OPR-17 | P1 | Manager | تأیید دوباره | 409 `Completion is already approved` ✅ |
| OPR-18 | P1 | Manager | لغو عملیات تکمیل‌شده | 409 `Operation is already COMPLETED` ✅ |
| OPR-19 | P0 | Manager | لغو B با علت `customer cancelled` | B: `CANCELLED`؛ user2 اعلان لغو می‌گیرد؛ علت در جزئیات ✅ |
| OPR-20 | P1 | Manager | لغو بدون علت | 400 `reason: must not be blank` ✅ |
| OPR-21 | P1 | user2 | شروع B (لغوشده) | 409 `Operation B is CANCELLED, expected ASSIGNED` ✅ |
| OPR-22 | P0 | Manager | «لغو تخصیص» C | C: `ASSIGNED → READY`، `assignedUserId` خالی؛ user3 اعلان می‌گیرد ✅ |
| OPR-23 | P1 | Manager | لغو تخصیص مجدد (C اکنون READY) | 409 `Operation C is READY, expected ASSIGNED` ✅ |
| OPR-24 | P0 | Manager | تخصیص دستی C به `user2` (جزئیات ← «تخصیص به») | C: `ASSIGNED` ✅؛ Audit `ASSIGN` |
| OPR-25 | P1 | Manager | تخصیص دستی به کاربر `manager` | 400 `User manager is not an active executive user` ✅ |
| OPR-26 | P1 | Manager | تخصیص دستی به `ghost` | 400 `Unknown user ghost` ✅ |
| OPR-27 | P1 | Manager | تخصیص دستی عملیات تکمیل‌شده A | 409 `Operation A is COMPLETED and cannot be assigned` ✅ |
| OPR-28 | P1 | Manager | عملیات ناموجود `ZZZ` (GET و assign) | 404 `Operation ZZZ not found` ✅ |
| OPR-29 | P0 | هر دو | وابستگی: `S01-sequential.xlsx` (A→B→C): تخصیص دستی B (NOT_READY) | 409 `Operation B is NOT_READY and cannot be assigned` ✅ |
| OPR-30 | P0 | user1 | S01: A را تخصیص/شروع/تکمیل کنید | B به‌صورت خودکار `READY` می‌شود، C هنوز `NOT_READY` و در جزئیات «در انتظار: B (آماده)» ✅ |
| OPR-31 | P1 | Owner | تنظیم «عملیات بعدی منتظر تأیید مدیر بماند» روشن؛ S01؛ A را تا تکمیل ببرید | B هنوز `NOT_READY`؛ بعد از «تأیید اتمام» مدیر ⇒ `READY` ✅ |
| OPR-32 | P1 | — | وابستگی اختیاری/SS | بخش ۸ (SCH-09, SCH-10) |
| OPR-33 | P2 | Manager/user1 | توضیح (comment) خالی | 400 (`reason: must not be blank` در DTO) |
| OPR-34 | P1 | هر دو | توضیح user روی عملیات دیگران | 403؛ مدیر روی هر عملیاتی می‌تواند توضیح بگذارد |

---

# ۸. تست‌های زمان‌بندی (Scheduling)

## ۸.۱ پیاده‌سازی واقعی در یک نگاه

(`SchedulePlanner` + `PlanService`) — **ساعت پیوسته** از «اکنون» (🚫 تقویم کاری/شیفت ندارد).

* **مدت** هر عملیات = `Preparation + Transport + Setup + Direct`.
* وزن بحرانی (**tail**) = مدت خودِ عملیات + طولانی‌ترین زنجیرهٔ کارِ منتظرِ آن. هرچه بیشتر ⇒ بحرانی‌تر.
* ورودی طرح: همهٔ عملیات غیرپایانی (`COMPLETED/CANCELLED` حذف). `IN_PROGRESS` ⇒ ساعتِ باقی‌مانده = مدت × (1 − پیشرفت٪) و از لحظهٔ 0 یک اسلات را می‌گیرد.
* بقیه یکی‌یکی چیده می‌شوند: از میان عملیاتی که **همهٔ پیش‌نیازهایشان چیده شده**، آنکه tail بزرگ‌تر دارد (برابری: شناسهٔ کوچک‌تر) در **زودترین زمانِ ممکن** قرار می‌گیرد که (۱) پیش‌نیازها تمام شده (FS: پایان پیش‌نیاز؛ SS: شروع پیش‌نیاز) و (۲) یکی از `capacity` خطِ منبع آزاد باشد؛ **حفره‌های خالی** پر می‌شوند.
* پیش‌نیاز **اختیاری** (`Is_Mandatory=FALSE`) در زمان‌بندی نادیده گرفته می‌شود.
* خروجی در UI: صفحهٔ «برنامه‌ریزی» (نوار هر عملیات؛ tooltip = بازهٔ شروع/پایان و مدت)، پنجرهٔ جزئیات عملیات («بازه پیش‌بینی‌شده»)، KPI «پایان پیش‌بینی‌شده» و «مسیر بحرانی باقی‌مانده» (= makespan بر حسب ساعت). API: فیلدهای `projectedStart`, `projectedEnd`, `tailHours` در `GET /api/operations`.

## ۸.۲ چگونه مقدار مورد انتظار را بخوانید

زمان‌ها نسبت به «اکنون» هستند و هر درخواست «اکنون» جدیدی دارد. بنابراین **اختلاف** را ببینید: در جدول زیر «شروع/پایان» **ساعت نسبت به شروع اولین عملیاتِ همان صفحه/پاسخ** است. در UI از tooltip نوارهای «برنامه‌ریزی» (همه در یک بارگذاری) استفاده کنید؛ یا با API:

```bash
reset; upload $OWNER test-data/S01-sequential.xlsx
api GET /operations $MGR      # projectedStart/projectedEnd را از همین پاسخ تفریق کنید
```
مقدار «بحرانی‌ها» را می‌توانید از داشبورد مدیر ← «عملیات بحرانی» (۶ عملیات ناتمام با بیشترین tail، به ترتیب نزولی) بخوانید.

> همهٔ جدول‌های زیر با اجرای واقعی تأیید شده‌اند ✅.

### SCH-01 [P0] — زنجیرهٔ ترتیبی روی یک منبع
**ورودی** (`S01-sequential.xlsx`): منبع M1 (ظرفیت ۱). A=4h، B=3h (بعد از A)، C=2h (بعد از B).
**زمان‌بندی مورد انتظار:**

| عملیات | وضعیت | شروع | پایان | tail |
|---|---|---|---|---|
| A | READY | 0 | 4 | 9 |
| B | NOT_READY | 4 | 7 | 5 |
| C | NOT_READY | 7 | 9 | 2 |

**مسیر بحرانی:** A → B → C (۹ ساعت) = makespan «مسیر بحرانی باقی‌مانده: ۹ ساعت».
**تخصیص منبع:** همه روی M1 (تنها منبع).
**چرا درست است:** هر عملیات باید منتظر پایان قبلی بماند: 4، 4+3=7، 7+2=9. tail هر عملیات = مدت خودش + tail بعدی: C=2، B=3+2=5، A=4+5=9.

### SCH-02a [P0] — دو کار موازی، ظرفیت ۲
**ورودی** (`S02a-parallel-capacity2.xlsx`): M1 ظرفیت ۲؛ A=5h، B=5h مستقل.
**مورد انتظار:** A: 0–5، B: 0–5؛ makespan = 5. هر دو READY. **مسیر بحرانی:** هر دو (tail=5).
**چرا:** دو خط موازی آزادند ⇒ هر دو از 0 شروع می‌شوند.

### SCH-02b [P0] — همان دو کار، ظرفیت ۱ (منبع پرکار)
**ورودی** (`S02b-parallel-capacity1.xlsx`): M1 ظرفیت ۱.
**مورد انتظار:** A: 0–5، B: 5–10؛ makespan = 10.
**چرا:** فقط یک خط؛ tail برابر است ⇒ شکستن تساوی با شناسه (A قبل از B).

### SCH-03 [P0] — اتصال (Join) و شناسایی مسیر بحرانی
**ورودی** (`S03-join.xlsx`): سه منبع مستقل (ظرفیت ۱). A=3h(M1)، B=6h(M2)، C=2h(M3) با C بعد از A **و** B.

| عملیات | وضعیت | شروع | پایان | tail |
|---|---|---|---|---|
| A | READY | 0 | 3 | 5 |
| B | READY | 0 | 6 | 8 |
| C | NOT_READY | 6 | 8 | 2 |

**مسیر بحرانی:** B → C (۸ ساعت). A «شناوری ۳ ساعت» دارد (می‌تواند تا ۳ ساعت دیر شروع شود).
**چرا:** C تا پایان **هر دو** پیش‌نیاز صبر می‌کند: max(3,6)=6. در «عملیات بحرانی» ترتیب: B(۸)، A(۵)، C(۲).

### SCH-04 [P0] — مسیر بحرانی روی منبع کمیاب
**ورودی** (`S04-critical-first.xlsx`): R و S هر دو ظرفیت ۱. Z=5h(R)، X=5h(R)، Y=20h(S) بعد از X.

| عملیات | شروع | پایان | tail |
|---|---|---|---|
| X | 0 | 5 | 25 |
| Z | 5 | 10 | 5 |
| Y | 5 | 25 | 20 |

**makespan = 25.** مسیر بحرانی: X → Y.
**چرا:** X زنجیرهٔ ۲۵ساعته پشت خود دارد، پس زودتر روی R می‌نشیند. اگر Z را اول می‌گذاشتیم: Z 0–5، X 5–10، Y 10–30 ⇒ makespan = ۳۰ (بدتر). شهود: «کارِ بحرانی را اول بچین».

### SCH-05 [P0] — پر شدن حفرهٔ خالی (Idle gap)
**ورودی** (`S05-gap-fill.xlsx`): X=10h روی S؛ A=5h روی R بعد از X؛ B=4h روی R مستقل.

| عملیات | شروع | پایان |
|---|---|---|
| X | 0 | 10 |
| B | 0 | 4 |
| A | 10 | 15 |

**makespan = 15.** **چرا:** منبع R تا ساعت 10 خالی است (A منتظر X است)؛ B (۴ ساعت) در حفرهٔ 0..10 جا می‌شود و A را عقب نمی‌اندازد. (بدون پر کردن حفره، B در 15..19 می‌رفت.)

### SCH-06 [P1] — حفرهٔ کوچک‌تر از کار
**ورودی** (`S06-gap-too-small.xlsx`): X=3h(S)، A=5h(R) بعد از X، B=4h(R).

| عملیات | شروع | پایان |
|---|---|---|
| X | 0 | 3 |
| A | 3 | 8 |
| B | 8 | 12 |

**چرا:** حفرهٔ R فقط 0..3 است (۳ ساعت) و B به ۴ ساعت نیاز دارد ⇒ جا نمی‌شود و بعد از A می‌رود.

### SCH-07 [P1] — زنجیرهٔ طولانی (۶ گام)
**ورودی** (`S07-long-chain.xlsx`): O1..O6 هر کدام 2h، O(k) بعد از O(k−1)، همه روی R(۱).
**مورد انتظار:** O1: 0–2، O2: 2–4، O3: 4–6، O4: 6–8، O5: 8–10، O6: 10–12. tail: 12،10،8،6،4،2. makespan=12. فقط O1 `READY`، بقیه `NOT_READY`.

### SCH-08 [P0] — منبع بیش‌ازحد بار (۴ کار، ظرفیت ۲)
**ورودی** (`S08-overload.xlsx`): R ظرفیت ۲؛ T1..T4 هر کدام 3h مستقل.
**مورد انتظار:** T1 و T2: 0–3؛ T3 و T4: 3–6؛ makespan=6. هر چهار READY.
**چرا:** دو خط ⇒ دو کار همزمان؛ باقی نوبتی. ترتیب بر اساس شناسه.

### SCH-09 [P1] — پیش‌نیاز شروع‌به‌شروع (START_TO_START)
**ورودی** (`S09-start-to-start.xlsx`): A=10h(R1)، B=4h(R2) با وابستگی SS به A.
**مورد انتظار:** A: 0–10؛ B: **0–4** (می‌تواند همزمان با شروع A شروع شود). وضعیتِ B تا زمانی که A **شروع نشده** `NOT_READY` است و پس از `start` شدن A (`IN_PROGRESS`) خودکار `READY` می‌شود.
> ⚠ **رفتار ثبت‌شده:** tail عملیات A برابر **۱۴** نشان داده می‌شود (۱۰+۴) در حالی که B با A موازی است؛ یعنی یال‌های SS در محاسبهٔ tail مثل زنجیرهٔ ترتیبی شمرده می‌شوند. این فقط روی «ترتیب اولویت و فهرست عملیات بحرانی» اثر دارد، نه بازه‌های زمانی.

### SCH-10 [P1] — پیش‌نیاز اختیاری
**ورودی** (`S10-optional-dependency.xlsx`): همان S09 ولی `FINISH_TO_START` با `Is_Mandatory=FALSE`.
**مورد انتظار:** هر دو `READY`؛ A: 0–10، B: 0–4 (وابستگی اختیاری مانع نیست).
> ⚠ باز هم tail(A)=۱۴ (یال اختیاری در tail شمرده می‌شود). در صفحهٔ جزئیات B پیش‌نیاز با برچسب «اختیاری» نشان داده می‌شود.

### SCH-11 [P1] — کار در حال انجام ظرفیت را اشغال می‌کند
**ورودی** (`S11-running-task.xlsx`): R ظرفیت ۱؛ A=8h، B=2h.
**مراحل:** ورود فایل ← جزئیات A ← «تخصیص به user1» ← (لاگین user1) شروع A ← پیشرفت 50٪.
**مورد انتظار** (✅): A `IN_PROGRESS`: 0–4 (باقی‌مانده = 8×0.5)، B `READY`: **4–6**.
**چرا:** A برای ۴ ساعت دیگر اسلات R را نگه داشته؛ B بعد از آن.

### SCH-12 [P1] — نمونهٔ واقعی (۱۶ عملیات)
**مراحل:** `reset` ← «بارگذاری داده نمونه» ← داشبورد مدیر و «برنامه‌ریزی».
**مورد انتظار:**
* «مسیر بحرانی باقی‌مانده» باید **≥ ۱۱۲ ساعت** باشد (طولانی‌ترین زنجیره؛ محدودیت ظرفیت منابع ممکن است آن را بیشتر کند). عدد دقیق را از UI بخوانید و ثبت کنید 🔎.
* «عملیات بحرانی»: OP-004 (۱۱۲)، OP-005 (۷۶)، OP-006 (۷۲)، OP-001 (۶۶)، OP-007 (۵۶)، OP-009 (۵۶) ✅.
* مجموع ساعات ۲۵۵، پیشرفت ۰٪.

### SCH-13 [P1] — چندپروژه‌ای
✅ **از نسخهٔ ۰.۲ پشتیبانی می‌شود.** سناریوهای کامل با اعداد دستی‌محاسبه‌شده در **بخش ۲۱** (MPR-03، MPR-04، MPR-05) آمده است.

### SCH-14 [P1] — پایداری «اکنون»
**مراحل:** صفحهٔ «برنامه‌ریزی» را باز کنید، ۵۰ ثانیه بعد refresh کنید.
**مورد انتظار:** همهٔ بازه‌ها به‌اندازهٔ زمان گذشته جلو می‌روند (طرح هر بار از «اکنون» محاسبه می‌شود؛ ذخیره نمی‌شود). اختلاف داخلی بازه‌ها ثابت می‌ماند. «مهلت» (due) عملیات تخصیص‌یافته **ثابت** است (مبنا در لحظهٔ تخصیص).

---

# ۹. تست‌های الگوریتم تخصیص (Assignment Algorithm)

## ۹.۱ قواعد واقعی (`AlgorithmStrategy` + `AssignmentState`)

**قیود سخت** (برای هر دو روش Algorithm و LLM):
1. کاربر باید اجرایی (`USER_*`) و فعال باشد.
2. اگر در Excel برای عملیات `Responsible_User_ID` آمده، **فقط** همان کاربر.
3. منبع فعال باشد و اسلات آزاد داشته باشد: `ASSIGNED + IN_PROGRESS < capacity`.
4. تعداد وظایف فعال کاربر (`ASSIGNED+IN_PROGRESS`) < `maxActiveTasksPerUser` (پیش‌فرض ۳).

**الگوریتم:** (۱) فقط عملیاتِ `READY`؛ مرتب‌سازی: tail نزولی ← مدت نزولی ← شناسه؛ (۲) اگر منبع اسلات آزاد ندارد ⇒ رد با دلیل؛ (۳) از میان کاربران مجاز، کمترین «بار مؤثر» = ساعت‌های باز کاربر − ۸ (اگر کاربر مسئول منبع باشد)؛ شکستن تساوی: تعداد وظایف کمتر ← شناسهٔ کاربر (`user1` < `user2` < `user3`)؛ (۴) بار و اسلات‌ها به‌روزرسانی می‌شوند.

> ⚠ پیشنهادها فقط **پیشنهاد**اند؛ تا تأیید مدیر هیچ تخصیصی انجام نمی‌شود.
> در UI: «تخصیص وظایف» ← روش «الگوریتم» ← «پیشنهاد تخصیص». API: `api POST /assignments/suggest $MGR -d '{"mode":"ALGORITHM"}'`.
> مقدارهای زیر همه با اجرای واقعی ✅ تأیید شده‌اند.

### ASG-01 [P0] — کمترین بار + تساوی (کاربر مورد انتظار صریح)
**ورودی** (`A1-three-equal.xlsx`): R ظرفیت ۵؛ A,B,C هر کدام 10h مستقل؛ هر سه کاربر بدون بار.
**خروجی:** **A→user1، B→user2، C→user3.**
**چرا:** ترتیب A,B,C (tail و مدت برابر ⇒ شناسه). برای A همه بار ۰ و ۰ وظیفه ⇒ شکستن تساوی با شناسه ⇒ user1. برای B: user1 اکنون ۱۰ ساعت دارد، user2 و user3 صفر ⇒ user2 (شناسه). برای C ⇒ user3. دلیل نمایشی: «lowest load (0.0 h, 0 active)».

### ASG-02 [P0] — اجرای مجدد = نتیجهٔ یکسان (قطعیت)
همان داده، دو بار «پیشنهاد تخصیص». **مورد انتظار:** هر دو بار دقیقاً A→user1، B→user2، C→user3. (دومی پیشنهادهای اولی را `SUPERSEDED` می‌کند.)

### ASG-03 [P0] — محدودیت ظرفیت منبع
**ورودی** (`A4-capacity.xlsx`): R ظرفیت ۱؛ C1=6h، C2=5h.
**خروجی:** **C1→user1**؛ C2 «تخصیص داده نشد» با دلیل `Resource Machine R has no free slot (1 of 1 busy)`.
**چرا:** فقط یک اسلات؛ tail(C1)=6 > tail(C2)=5.

### ASG-04 [P0] — درنظر گرفتن مسیر بحرانی
**ورودی** (`A5-critical-path.xlsx`): R(۱): C1=6h، C2=5h؛ S(۱): D=20h بعد از C2.
**خروجی:** **C2→user1** (دلیل: «Critical-path rank 1 (chain 25.0 h)»)؛ C1 رد می‌شود (`no free slot`).
**چرا:** با اینکه C2 کوتاه‌تر است، ۲۰ ساعت کار پشت آن منتظر است (tail=25 > 6)، پس اسلات به C2 می‌رسد.

### ASG-05 [P0] — سقف وظایف کاربر (limit=1)
**مراحل:** Owner ← «تنظیمات» ← «سقف وظایف فعال هر کاربر» = 1 ← ذخیره؛ `A3-task-limit.xlsx` (R ظرفیت ۱۰؛ T1=8،T2=7،T3=6،T4=5)؛ پیشنهاد.
**خروجی:** **T1→user1، T2→user2، T3→user3**؛ T4 تخصیص داده نمی‌شود: `No executive user is below the active-task limit (1)`.
**چرا:** هر کاربر یک وظیفهٔ فعال می‌گیرد و سقف پر می‌شود. (پس از تست سقف را به ۳ برگردانید.)

### ASG-06 [P1] — چند کاربر مجاز؛ کم‌بارترین برنده است
همان فایل با سقف پیش‌فرض ۳.
**خروجی:** T1→user1، T2→user2، T3→user3، و **T4→user3** (دلیل: «lowest load (6.0 h, 1 active)»).
**چرا:** وقتی T4 می‌رسد بارها: user1=8، user2=7، user3=6 ⇒ کمترین = user3.

### ASG-07 [P0] — مجری ثابت در Excel (Responsible_User_ID)
**ورودی** (`A6-fixed-executor.xlsx`): F (3h) با مجری ثابت `user3`؛ G (3h) بدون مجری.
**خروجی:** **F→user3** (دلیل «fixed executor in OPC»)، **G→user1** (user3 ۳ ساعت بار دارد، user1 صفر).

### ASG-08 [P0] — مجری ثابتِ غیرفعال
**مراحل:** Owner ← کاربران ← `user3` غیرفعال؛ همان فایل A6.
**خروجی:** F **تخصیص داده نمی‌شود**: `Fixed executor user3 is unavailable: User user3 is unknown, inactive or not an executive user`؛ **G→user1**.

### ASG-09 [P0] — کاربر غیرفعال هرگز انتخاب نمی‌شود
**مراحل:** `user1` غیرفعال؛ `A1-three-equal.xlsx`؛ پیشنهاد.
**خروجی:** **A→user2، B→user3، C→user2.**
**چرا:** A→user2 (id کوچک‌تر بین دو کاربر خالی). B: user2=10h، user3=0 ⇒ user3. C: هر دو ۱۰ ساعت و ۱ وظیفه ⇒ شکستن تساوی با id ⇒ user2.

### ASG-10 [P1] — هیچ کاربر معتبری نیست
**مراحل:** هر سه کاربر اجرایی غیرفعال (هر کدام بدون وظیفهٔ باز)؛ `A1`؛ پیشنهاد.
**خروجی:** ۰ پیشنهاد؛ هر سه A,B,C در «تخصیص داده نشد».
> ⚠ **پیام گمراه‌کننده:** دلیل نمایش‌داده‌شده `No executive user is below the active-task limit (3)` است در حالی که علت واقعی «غیرفعال بودن همهٔ کاربران» است (کد برای دلیل‌دهی بین «سقف پر» و «غیرفعال» فرق نمی‌گذارد). این یک نقص جزئی در کیفیت پیام است.

### ASG-11 [P1] — امتیاز مسئول منبع غالب نمی‌شود
**ورودی** (`A7a-responsible-user-no-win.xlsx`): منبع Q(۱): P=10h با مجری ثابت user2؛ منبع R(۵، مسئول=`user2`): N=4h.
**خروجی:** P→user2 (ثابت)، **N→user1.**
**چرا:** بار مؤثر user2 = 10−8 = **2** > بار user1 = 0 و user3 = 0 ⇒ میان user1/user3 تساوی ⇒ id ⇒ user1.

### ASG-12 [P1] — امتیاز مسئول منبع برنده می‌شود
**ورودی** (`A7b-responsible-user-wins.xlsx`): مانند بالا اما P=5h.
**خروجی:** P→user2؛ **N→user2** (دلیل «responsible user of Machine R, load 5.0 h»).
**چرا:** بار مؤثر user2 = 5−8 = **−3** < 0 ⇒ از user1/user3 بهتر.

### ASG-13 [P0] — فقط عملیات READY بررسی می‌شود
`S01-sequential.xlsx`؛ پیشنهاد. **خروجی:** فقط A (→user1)؛ B و C اصلاً در پیشنهاد/«تخصیص داده نشد» نیستند (NOT_READY).

### ASG-14 [P1] — تأثیر بار موجود
`A1` ← پیشنهاد و تأیید (user1,user2,user3 هر کدام ۱۰ ساعت) ← یک عملیات READY جدید D=2h (با ورود فایل دوم یا تغییر) ← پیشنهاد 🔎: کاربر با کمترین بار؛ چون همه ۱۰ و ۱ وظیفه ⇒ **user1** (id).

---

# ۱۰. تست‌های تأیید مدیر (Manager Approval)

جریان واقعی: `suggest` ⇒ پیشنهادهای `PENDING` (پیشنهادهای PENDING قبلی `SUPERSEDED` می‌شوند) ⇒ مدیر `approve` یا `reject` ⇒ هنگام تأیید دوباره بررسی می‌شود که عملیات هنوز `READY` است و قیود سخت برقرارند.
داده: `A1-three-equal.xlsx` مگر خلاف آن گفته شود.

| ID | P | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|
| APR-01 | P0 | Manager: «پیشنهاد تخصیص» | جدول «پیشنهادها (۳)» با ستون‌های عملیات، منبع، مدت، کاربر پیشنهادی، دلیل، وضعیت «در انتظار»؛ همه تیک‌خورده ✅. Owner نیز همین صفحه را می‌بیند |
| APR-02 | P0 | «تأیید موارد انتخاب‌شده (3)» | پیام «۳ مورد تأیید شد»؛ A→`ASSIGNED` برای user1 و … ✅؛ اعلان «New task: A (Task A)» برای هر کاربر؛ Audit: `ASSIGN`؛ `مهلت` (due) هر عملیات = پایان پیش‌بینی‌شده در لحظهٔ تأیید |
| APR-03 | P1 | فقط تیک A را نگه دارید؛ تأیید | فقط A تخصیص می‌یابد؛ B و C `PENDING` می‌مانند و عملیاتشان `READY` |
| APR-04 | P0 | «رد موارد انتخاب‌شده» | وضعیت پیشنهاد «رد شد» (یادداشت: `Rejected by manager` یا متن شما)؛ عملیات همچنان `READY` و `assignedUserId` خالی ✅ |
| APR-05 | P0 | تأیید دوبارهٔ همان شناسه‌ها (`approve` با ids قبلی) | **200 با لیست خالی** و **هیچ تغییری** ✅. ⚠ بی‌صدا است؛ خطا برنمی‌گرداند |
| APR-06 | P1 | رد پیشنهادِ قبلاً تأییدشده | 200 لیست خالی؛ تخصیص باقی می‌ماند ✅ |
| APR-07 | P1 | `approve` با شناسهٔ ناموجود `[99999]` | 200 لیست خالی ✅ |
| APR-08 | P1 | `approve` با لیست خالی | **400** `proposalIds: must not be empty` ✅ |
| APR-09 | P0 | **پیشنهاد کهنه (stale):** پیشنهاد run1؛ دوباره پیشنهاد (run2)؛ تأیید ids مربوط به run1 | run1 `SUPERSEDED`؛ تأیید ⇒ 200 لیست خالی؛ عملیات تغییر نمی‌کند ✅ |
| APR-10 | P0 | **پیشنهاد نامعتبر هنگام تأیید:** پیشنهاد؛ سپس A را «لغو» کنید؛ سپس تأیید همه | A: پیشنهاد `REJECTED` با یادداشت `Not applied: Operation is no longer READY`؛ B و C `APPROVED` ✅ |
| APR-11 | P1 | تخصیص دستی B بعد از پیشنهاد | پیشنهاد B `SUPERSEDED` (یادداشت «Manually assigned»)، بقیه `PENDING` ✅ |
| APR-12 | P1 | بررسی مجدد ظرفیت هنگام تأیید 🔎: `A4-capacity.xlsx` ← پیشنهاد (C1→user1) ← تخصیص دستی C2 به user2 (دستی ظرفیت را نادیده می‌گیرد) ← تأیید | پیشنهاد C1 `REJECTED` با `Not applied: Resource Machine R is at capacity (1/1)` |
| APR-13 | P0 | user1: `api POST /assignments/approve $U1 -d '{"proposalIds":[1]}'` | 403 ✅ |
| APR-14 | P1 | Owner: همان تأیید | 200 (OWNER هم مجاز است) |
| APR-15 | P1 | سابقهٔ اجراها | بخش «سابقهٔ اجراها» حداکثر ۸ اجرای اخیر با روش، زمان و تعداد؛ `GET /assignments/runs` |
| APR-16 | P1 | تأیید تخصیص با `systemStatus=SUSPENDED` | مدیر: 423 `The system is suspended by the owner.` ✅؛ Owner مجاز |

---

# ۱۱. تست‌های LLM (مدل زبانی)

## ۱۱.۱ پیاده‌سازی واقعی

(`LlmStrategy`) فقط ids، نام‌ها، ساعت‌ها و بارها (بدون اطلاعات تماس) به مدل زبانی ارسال می‌شود. خروجی «ساختاریافته» (JSON schema از `LlmOutput`) است. **هر پیشنهادِ مدل با همان `AssignmentState` (قیود سخت) دوباره بررسی می‌شود**؛ پیشنهاد متخلف حذف و در `warnings` می‌آید؛ تکراری/شناسهٔ ساختگی نادیده گرفته می‌شود؛ عملیاتی که مدل به آن نپرداخته «Not addressed by the model» می‌گیرد. در هر خطا (نبود کلید، خطای API، امتناع (refusal)، خروجی غیرقابل‌استفاده) به **الگوریتم** برمی‌گردد و در Run `fallback=true` + هشدار می‌گذارد.

**اصل:** *LLM = توصیه؛ Backend = اعتبارسنجی نهایی و قواعد کسب‌وکار.* هیچ مسیری وجود ندارد که خروجی LLM بدون عبور از `AssignmentState` ذخیره شود، و در تأیید نیز دوباره بررسی می‌شود (APR-10، APR-12).

> ## ⚠ محدودیت این بازبینی
> هیچ کلید معتبر Anthropic در دسترس نبود؛ بنابراین **مسیر موفق واقعی** (LLM-04) هرگز اجرا نشده. مسیرهای «بدون کلید» و «کلید نامعتبر» روی Backend واقعی اجرا و تأیید شدند ✅. رفتارهای «پاسخ ناقص/کاربر نامعتبر/تخطی از ظرفیت…» با **تست واحد** (`LlmStrategyValidationTest`) تأیید شده‌اند؛ به‌صورت دستی نمی‌توان از مدل واقعی چنین خروجی‌هایی را اجبار کرد.

| ID | P | شرایط / مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|
| LLM-01 | P0 | **بدون کلید** (`ANTHROPIC_API_KEY` خالی). «تخصیص وظایف»؛ روش «هوش مصنوعی (LLM)»؛ «پیشنهاد تخصیص» | Run: درخواست‌شده=LLM، تولیدشده با=الگوریتم، برچسب «جایگزین»، نوار هشدار؛ هشدار: `LLM mode failed (ANTHROPIC_API_KEY is not configured on the server). The algorithm produced these proposals instead.` ✅؛ پیشنهادها همان نتیجهٔ الگوریتم (ASG-01) |
| LLM-02 | P0 | بدون کلید: صفحهٔ «تخصیص» و کنسول مالک | نوار زرد «کلید API … تنظیم نشده است»؛ `GET /settings` ⇒ `llmAvailable:false` ✅ |
| LLM-03 | P0 | **کلید نامعتبر:** Backend را با `ANTHROPIC_API_KEY=sk-ant-invalid-key-for-qa` و `AISO_LLM_MODEL=any-model` اجرا کنید؛ LLM → پیشنهاد | ≈۲–۳ ثانیه؛ fallback؛ هشدار `LLM mode failed (LLM API call failed: 401: … "API key is invalid." …)` ✅؛ `llmAvailable:true`؛ پیشنهادها از الگوریتم (`source=ALGORITHM`) |
| LLM-04 | P0 | **کلید معتبر** (فقط با کلید واقعی؛ ⚠ هزینه دارد): داده `A5-critical-path.xlsx` یا نمونهٔ ۱۶ عملیاتی؛ روش LLM؛ پیشنهاد | 🔎 `effectiveMode=LLM`، `fallback=false`، مدلِ تنظیم‌شده در `AISO_LLM_MODEL`، «توکن ورودی→خروجی» پر، دلیل‌های انگلیسی؛ **همهٔ** پیشنهادها قیود سخت را رعایت می‌کنند (ظرفیت منبع، سقف کاربر، مجری ثابت)؛ عملیات اضافی در «تخصیص داده نشد» |
| LLM-05 | P0 | **پاسخ ناقص/خراب:** `mvn -Dtest=LlmStrategyValidationTest test` (در پوشهٔ `backend`) | تست `nullListsFromTheModelAreTolerated` سبز: لیست‌های null ⇒ بدون پیشنهاد، همه عملیات «Not addressed by the model» |
| LLM-06 | P0 | **کاربر نامعتبر / عملیات ناموجود:** همان کلاس تست | `invented_idsAndDuplicatesAreIgnored` سبز: `ZZZ` و کاربر `ghost` حذف + ۳ هشدار؛ فقط انتساب معتبر می‌ماند |
| LLM-07 | P0 | **تخطی از ظرفیت:** همان کلاس | `capacityViolationIsDropped` سبز: هشدار `Dropped B -> u2: Resource R is at capacity (1/1)` |
| LLM-08 | P0 | **تخطی از مجری ثابت / تخصیص غیرممکن:** همان کلاس | `fixedExecutorViolationIsDropped` سبز: هشدار شامل «fixed» |
| LLM-09 | P0 | **تخطی از وابستگی:** عملیات `NOT_READY` اصلاً به مدل داده نمی‌شود و اگر مدل شناسهٔ آن را بدهد `Operation X is not READY` و حذف می‌شود | با تست واحد (شناسهٔ ساختگی/غیرREADY) ✅؛ دستی: در S01 فقط A در snapshot است |
| LLM-10 | P1 | **Timeout / قطع شبکه:** (الف) کلید معتبر + قطع اینترنت، یا (ب) `AISO_LLM_TIMEOUTSECONDS=1` 🔎 | fallback با هشدار `LLM API call failed: …`؛ درخواست حداکثر به‌اندازهٔ timeout طول می‌کشد، نه بی‌نهایت |
| LLM-11 | P1 | **غیرفعال‌کردن fallback** 🔎: `AISO_LLM_FALLBACKTOALGORITHM=false`، بدون کلید، LLM → پیشنهاد | HTTP **502** `LLM assignment failed: ANTHROPIC_API_KEY is not configured on the server`؛ UI پیام خطا (toast) |
| LLM-12 | P1 | `validate` پاس: خروجی مدل با `Pick` معتبر | `validAnswerPassesThrough` سبز؛ توکن‌ها در Suggestion حفظ می‌شوند |
| LLM-13 | P0 | **LLM هرگز قواعد را دور نمی‌زند (ترکیبی):** با LLM-04 ، سپس بین پیشنهاد و تأیید، ظرفیت منبع را با تخصیص دستی پر کنید | تأیید ⇒ پیشنهاد `REJECTED` (APR-12) — بررسی نهایی همیشه سمت Backend است |
| LLM-14 | P1 | تغییر روش: Owner/Manager روش را روی LLM بگذارد؛ بدون بدنه «پیشنهاد» | از روش تنظیم‌شده استفاده می‌شود (`requestedMode=LLM`) ✅؛ دکمهٔ «یک بار با … اجرا کن» همان‌جا روش دیگر را فقط برای همان اجرا می‌زند |
| LLM-16 | P1 | کلید تنظیم است ولی `AISO_LLM_MODEL` خالی است 🔎 | `llmAvailable:false`؛ اجرای LLM به الگوریتم برمی‌گردد با هشدار `LLM mode failed (AISO_LLM_MODEL is not set on the server)…` |
| LLM-15 | P2 | کلید در UI/پاسخ‌ها | کلید در هیچ پاسخ API یا صفحه‌ای نیست (فقط `llmAvailable` بولی و نام مدل) ✅ |

> ⚠ **تفاوت با مستندات:** README می‌گوید «در صورت refusal هم fallback». این در کد هست (`StopReason.REFUSAL ⇒ LlmUnavailableException`) ولی با مدل واقعی تست نشده. 🔎

---

# ۱۲. تست‌های گردش کار کاربر اجرایی (User Task Workflow)

> ⚠ **تفاوت با درخواست:** مسیر `PENDING → IN_PROGRESS → COMPLETED` در کد وجود ندارد. مسیر واقعی کاربر اجرایی:
> `ASSIGNED → IN_PROGRESS → COMPLETED`، و شاخهٔ `ASSIGNED/IN_PROGRESS → BLOCKED → (رفع انسداد توسط مدیر) → وضعیت قبلی`. «تأیید اتمام» رویداد جدا و فقط برای مدیر است.

| ID | P | مراحل (داده A1، تخصیص‌شده) | نتیجهٔ مورد انتظار |
|---|---|---|---|
| USR-01 | P0 | user1 ← `/my` ← کارت A | کارت: شناسه، نام، وضعیت «تخصیص‌یافته»، منبع، مدت، مهلت؛ شمارنده «منتظر شروع: ۱» ✅ |
| USR-02 | P0 | شروع کار | `IN_PROGRESS`؛ دکمه‌های «ثبت پیشرفت»/«ثبت اتمام» ظاهر می‌شوند ✅ |
| USR-03 | P0 | شروع عملیاتی که تخصیصش به خودش نیست (user2 روی A) | 403 `This operation is not assigned to you` ✅ |
| USR-04 | P0 | شروع عملیات `READY` (هنوز بدون تخصیص) | 403 همان پیام (چون تخصیص‌یافتهٔ او نیست) ✅ |
| USR-05 | P0 | اتمام بدون شروع (روی A در وضعیت ASSIGNED) | 409 `Operation A is ASSIGNED, expected IN_PROGRESS` ✅ |
| USR-06 | P0 | شروع → پیشرفت ۴۰٪ → «ثبت اتمام» | `COMPLETED`، ۱۰۰٪، مدیر اعلان `user1 completed A (Task A)` می‌گیرد؛ وابسته‌ها بازارزیابی می‌شوند |
| USR-07 | P0 | **وابستگی انجام‌نشده** (S01): کاربر نمی‌تواند B را بگیرد/شروع کند | B تا A تمام نشود `NOT_READY` است و تخصیص‌پذیر نیست (OPR-29)؛ در `/my` اصلاً نمی‌آید |
| USR-08 | P0 | user2 `GET /operations/A` (A از user1) | 403 `This operation is not assigned to you` ✅ |
| USR-09 | P0 | user2 تلاش برای `progress`/`complete`/`block`/`comment` روی A | همه 403 |
| USR-10 | P1 | گزارش مشکل → مدیر رفع انسداد | `IN_PROGRESS` → `BLOCKED` → `IN_PROGRESS`؛ اعلان «unblocked» برای کاربر |
| USR-11 | P1 | داشبورد کاربر | «عملکرد من»: به‌موقع، واقعی÷برنامه، با تأخیر، مشکلات گزارش‌شده؛ «اخیراً تکمیل‌شده» با ✓ پس از تأیید مدیر |
| USR-12 | P2 | اعلان‌ها: زنگ بالای صفحه | تعداد خوانده‌نشده؛ «علامت‌گذاری همه به‌عنوان خوانده‌شده» |
| USR-13 | P1 | «با تأخیر تمام شد»: بعد از گذشت مهلت (due) تکمیل کنید 🔎 | `late=true` و برچسب «⚠ با تأخیر تمام شد» در فهرست اخیراً تکمیل‌شده |

---

# ۱۳. تست‌های امنیتی (Security)

> هدف: بررسی کنترل‌های دسترسی خودِ برنامه، نه دور زدن آن.

| ID | P | تست | نتیجهٔ مورد انتظار |
|---|---|---|---|
| SEC-01 | P0 | ارتقای نقش از طریق بدنهٔ درخواست: کاربر USER_1 به `PATCH /users/user1` با `{"role":"OWNER"}` | 403 (مجوز Owner لازم است) — حتی Owner هم نمی‌تواند مالکیت بدهد (`Ownership cannot be granted through the API`) ✅ |
| SEC-02 | P0 | endpoint محافظت‌شده بدون احراز (`GET /operations`, `POST /assignments/suggest`) | 401 ✅ |
| SEC-03 | P0 | JWT ساختگی / امضا دست‌خورده | 401 ✅ |
| SEC-04 | P0 | دست‌کاری شناسه‌ها (IDOR): user2 → `GET/POST /operations/A/…` (A از user1) | 403 ✅ |
| SEC-05 | P0 | تأیید تخصیص بدون مجوز مدیر | user1 ⇒ 403 ✅ |
| SEC-06 | P0 | ویرایش تنظیمات پروژه بدون مجوز Owner | Manager ⇒ 403 ✅ |
| SEC-07 | P0 | ورود Excel توسط کاربر اجرایی | 403 ✅ |
| SEC-08 | P1 | JWT از نقش خود استفاده نمی‌کند: ادعای نقش در توکن (claims) نادیده است؛ نقش از دیتابیس می‌آید | تغییر نقش کاربر توسط Owner ⇒ همان لحظه روی توکن قدیمی اثر می‌گذارد (AUTH-19 و ROL-07) |
| SEC-09 | P1 | کوکی نشست | در DevTools ← Application ← Cookies: `aiso_token` = `HttpOnly`، `SameSite=Strict`؛ در Console: `document.cookie` توکن را نشان **نمی‌دهد** |
| SEC-10 | P1 | کلیدهای حساس | پاسخ‌های `GET /settings` و `/dashboard/owner` شامل کلید API یا رمز نیستند؛ رمزها BCrypt (در پاسخ کاربران `passwordHash` نیست) ✅ |
| SEC-11 | P1 | پیام ورود یکسان برای کاربر ناموجود/رمز غلط/غیرفعال | همیشه `Invalid user id or password` ✅ |
| SEC-12 | P0 | تعلیق سیستم (Owner، با علت) | خواندن‌ها (GET) برای همه کار می‌کند؛ هر **نوشتن** از Manager/User ⇒ **423** `The system is suspended by the owner.` ✅؛ Owner آزاد است؛ لاگین مجاز است ✅ |
| SEC-13 | P1 | تعلیق بدون علت | 400 `A reason is required` ✅ |
| SEC-14 | P1 | بازفعال‌سازی | نوشتن دوباره برای Manager 200 ✅ |
| SEC-15 | P0 | بازنشانی فقط Owner و با تأیید | بدون `"confirm":"RESET"` ⇒ 400 `Type RESET in the confirm field to proceed` ✅؛ بدون علت ⇒ 400 `A reason is required for a reset` ✅؛ Manager ⇒ 403 ✅؛ پس از بازنشانی، Audit پاک نمی‌شود و رویداد `SYSTEM_RESET` (با علت) در آن ثبت شده است |
| SEC-16 | P1 | Audit تغییرناپذیر از طریق برنامه | هیچ endpoint ای برای ویرایش/حذف Audit وجود ندارد (فقط `GET /audit`) |
| SEC-17 | P1 | تزریق فرمول در خروجی Excel | متنی که با `=` یا `+`/`-`/`@` شروع شود (مثلاً نام عملیات `=1+1`) در `AISO-report.xlsx` با `'` ابتدای متن می‌آید (🔎 نیازمند فایل ورودی با چنین نامی) |
| SEC-18 | P1 | آپلود با نوع محتوای جعلی | فایل غیر Excel با پسوند `.xlsx` ⇒ REJECTED بدون Crash (IMP-13/14) ✅ |
| SEC-19 | P2 | محدودیت اندازه | فایل > ۱۰ مگابایت ⇒ خطای آپلود (`max-file-size: 10MB`) 🔎 |
| SEC-20 | P2 | ⚠ نبود rate-limit ورود | ۲۰ تلاش پیاپی با رمز غلط همه 401 می‌دهند و مسدود نمی‌شود — محدودیت شناخته‌شده (بخش ۱۹) |

---

# ۱۴. تست‌های UI/UX

زبان پیش‌فرض: فارسی (RTL)؛ دکمهٔ «EN/فا» در نوار بالا و صفحهٔ ورود. تم: روشن/تیره با دکمهٔ ◐ (و تنظیم سیستم).

| ID | P | تست | نتیجهٔ مورد انتظار |
|---|---|---|---|
| UI-01 | P0 | `<html dir="rtl" lang="fa">` پس از بارگذاری | منو و جداول راست‌به‌چپ؛ شناسه‌ها/ساعت‌ها (LTR) داخل متن فارسی به‌هم نمی‌ریزند ✅ |
| UI-02 | P1 | کلیک «EN» | همهٔ برچسب‌ها انگلیسی و `dir="ltr"`؛ انتخاب در کوکی `aiso_lang` می‌ماند (F5) |
| UI-03 | P1 | تاریخ‌ها | در فارسی به تقویم جلالی با ارقام فارسی (مثلاً «۱۲ مهر ۱۴۰۵، ۹:۱۱»)؛ در انگلیسی میلادی ✅ |
| UI-04 | P0 | صفحهٔ ورود | فیلدها required؛ خطا به‌صورت نوار قرمز؛ دکمه حین ارسال spinner |
| UI-05 | P0 | داشبورد مدیر | KPI ها (پیشرفت، پایان پیش‌بینی‌شده، آماده، تخصیص‌یافته/در حال انجام، مسدود، تأخیر)، نوار توزیع وضعیت با لِجند، عملیات بحرانی، منابع (مربع‌های اسلات)، حجم کار کاربران ✅ |
| UI-06 | P1 | جدول عملیات | سطر با کیبورد (Tab+Enter) قابل باز شدن؛ وضعیت با **آیکون + متن** (نه فقط رنگ) |
| UI-07 | P1 | اعتبارسنجی فرم‌ها | ریست رمز با رمز کوتاه ⇒ دکمه غیرفعال؛ لغو با علت خالی ⇒ دکمه غیرفعال؛ خطاهای Backend در toast قرمز (7 ثانیه) |
| UI-08 | P1 | حالت loading | هنگام بارگذاری متن «در حال بارگذاری…» |
| UI-09 | P1 | حالت خطا | Backend را متوقف کنید و صفحه را refresh کنید ⇒ نوار/پیام خطا نمایش داده می‌شود (پاسخ 502 با `The server is not reachable`) و برنامه کرش نمی‌کند |
| UI-10 | P1 | حالت خالی | بدون دادهٔ عملیاتی: «هنوز عملیاتی ثبت نشده است» با لینک «ورود اطلاعات Excel»؛ پلن: «عملیات ناتمامی برای برنامه‌ریزی وجود ندارد»؛ اعلان‌ها: «اعلانی وجود ندارد» |
| UI-11 | P1 | ناوبری | آیتم فعال منو هایلایت و `aria-current`؛ نقش‌ها فقط آیتم‌های مجاز را می‌بینند (ROL-01..03) |
| UI-12 | P1 | تازه‌سازی خودکار | داشبورد مدیر هر ~۱۵ ثانیه، عملیات ~۲۰، برنامه ~۳۰ ثانیه؛ تغییر در تب دیگر بدون F5 دیده می‌شود |
| UI-13 | P1 | واکنش‌گرایی (موبایل) | پهنای ۳۷۵px: منوی همبرگری ☰ جایگزین سایدبار؛ جدول‌ها اسکرول افقی داخلی دارند؛ **صفحه** اسکرول افقی ندارد. ⚠ در بازبینی خودکار با مرورگر داخلی قابل اندازه‌گیری نبود — حتماً دستی در مرورگر واقعی بررسی کنید و نتیجه را ثبت کنید |
| UI-14 | P2 | تم تیره | کنتراست متن/پس‌زمینه کافی؛ رنگ وضعیت‌ها با آیکون همراه است |
| UI-15 | P2 | Gantt | هر منبع یک ردیف؛ منبع با ظرفیت ۲ حداکثر دو «لِین» موازی؛ کلیک روی نوار پنجرهٔ جزئیات را باز می‌کند |
| UI-16 | P2 | دسترسی‌پذیری | Esc پنجرهٔ مودال را می‌بندد؛ فیلدها label دارند؛ toast با `aria-live` |
| UI-17 | P1 | دانلودها | «دریافت قالب» و «دریافت گزارش Excel» فایل `.xlsx` معتبر می‌دهند (شیت‌ها: Operations, User_Performance, Import_History, Audit) ✅ |

---

# ۱۵. سناریوی جامع End-to-End (Master Scenario)

> هدف: یک مسیر کامل از ورود تا اتمام، روی داده‌های `I01-valid.xlsx` + یک فایل کمکی. **پیش‌شرط:** `reset`؛ سقف وظایف=۳؛ روش تخصیص=الگوریتم؛ هر ۵ کاربر seed فعال.

⚠ «ایجاد پروژه» و «محاسبهٔ زمان‌بندی» مراحل جداگانهٔ کاربر نیستند: پروژه از قبل هست (Owner فقط نام را عوض می‌کند) و زمان‌بندی خودکار و آنی است (صفحهٔ «برنامه‌ریزی»).

| گام | Actor | اقدام | نتیجهٔ مورد انتظار |
|---|---|---|---|
| E2E-01 | Owner | لاگین `owner` | هدایت به `/owner`؛ وضعیت سیستم «فعال» |
| E2E-02 | Owner | «تنظیمات» ← نام پروژه `Factory Line 7` ← ذخیره | پیام ذخیره؛ نام در زیرعنوان؛ نسخهٔ پیکربندی ↑ (جایگزین «ایجاد پروژه») |
| E2E-03 | Owner | «ورود اطلاعات Excel» ← `test-data/I01-valid.xlsx` ← «فقط اعتبارسنجی» | VALID؛ هیچ عملیاتی هنوز نیست |
| E2E-04 | Owner | «اعتبارسنجی و ورود» | APPLIED: منابع +3، عملیات +3، پیش‌نیاز +2 |
| E2E-05 | Manager (لاگین `manager`) | «داشبورد مدیر» | ۳ عملیات؛ پیشرفت ۰٪؛ آماده ۱، آماده‌نیست ۲؛ «عملیات بحرانی»: OP-1 (۱۸)، OP-2 (۱۲)، OP-3 (۴) |
| E2E-06 | Manager | «عملیات» ← OP-3 | «در انتظار: OP-2»؛ تخصیص‌اش ممکن نیست (409) |
| E2E-07 | Manager | «برنامه‌ریزی» | OP-1: 0–6، OP-2: 6–14، OP-3: 14–18 (ساعت نسبی)؛ «مسیر بحرانی باقی‌مانده: ۱۸ ساعت» |
| E2E-08 | Manager | «تخصیص وظایف» ← «پیشنهاد تخصیص» | فقط OP-1 پیشنهاد می‌شود (تنها READY)، به `user1`؛ OP-2/3 در هیچ لیستی نیستند |
| E2E-09 | Manager | تأیید پیشنهاد | OP-1 `ASSIGNED` برای user1؛ اعلان برای user1 |
| E2E-10 | user1 | لاگین ← `/my` | یک کارت: OP-1؛ «منتظر شروع: ۱»؛ اعلان «New task: OP-1 (Cut plates)» |
| E2E-11 | user1 | «شروع کار» | `IN_PROGRESS` |
| E2E-12 | user1 | پیشرفت ۵۰٪ + توضیح | نوار ۵۰٪؛ مدیر در «فعالیت‌های اخیر» می‌بیند |
| E2E-13 | user1 | «ثبت اتمام» | `COMPLETED`؛ OP-2 خودکار `READY`؛ OP-3 هنوز آماده نیست (۲) |
| E2E-14 | Manager | داشبورد | پیشرفت = 6÷18 = ۳۳.۳٪؛ اعلان «OP-2 became READY»؛ «آماده» ۱ |
| E2E-15 | Manager | «پیشنهاد تخصیص» | OP-2 (منبع R-WELD با مسئول `user1`) ⇒ پیشنهاد؛ بار user1 = 0 ⇒ طبق امتیاز مسئول منبع (−۸) **user1** برنده است |
| E2E-16 | Manager | تأیید | OP-2 `ASSIGNED` برای user1 |
| E2E-17 | Manager | «تأیید اتمام» روی OP-1 | `completionApproved=true` (✓ در فهرست کاربر) |
| E2E-18 | user1 | OP-2: شروع ← «گزارش مشکل» (`power cut`) | `BLOCKED`؛ مدیر اعلان می‌گیرد؛ در «عملیات مسدود» |
| E2E-19 | Manager | «رفع انسداد» | OP-2 به `IN_PROGRESS` برمی‌گردد |
| E2E-20 | user1 | تکمیل OP-2 | `COMPLETED`؛ OP-3 `READY` |
| E2E-21 | Manager | تخصیص دستی OP-3 به `user2` | `ASSIGNED` برای user2 |
| E2E-22 | user2 | شروع و اتمام OP-3 | `COMPLETED` |
| E2E-23 | Manager | داشبورد | پیشرفت **۱۰۰٪**؛ همهٔ وضعیت‌ها `COMPLETED=3`؛ «پایان پیش‌بینی‌شده» خالی (—) و «مسیر بحرانی باقی‌مانده: ۰» |
| E2E-24 | Manager | «گزارش‌ها» | عملکرد: user1 تکمیل ۲ (۱۴ ساعت)، user2 تکمیل ۱ (۴ ساعت)، مشکل‌گزارش‌شدهٔ user1 = ۱؛ دانلود Excel |
| E2E-25 | Owner | «گزارش ممیزی» | رویدادها به ترتیب: `OPERATION_CREATED`, `STATUS_CHANGE`, `ASSIGN`, `PROGRESS`, `COMPLETION_APPROVED`, … با Actor و زمان |
| E2E-26 | Owner | F5 / ری‌استارت Backend (دیتابیس فایلی) | همهٔ داده‌ها و وضعیت‌ها پابرجا |

> نکتهٔ E2E-15: امتیاز «مسئول منبع» ۸ ساعت است؛ اگر user1 بار باز داشته باشد (≥ ۸ ساعت بیش از دیگران) کاربر دیگری انتخاب می‌شود. 🔎 این گام را با مقادیر دقیق در اجرای اول مشاهده و ثبت کنید.

---

# ۱۶. تست‌های رگرسیون (Regression)

پس از هر تغییر مهم این‌ها را اجرا کنید. ستون «خودکار» یعنی پوشش دارد با `cd backend && mvn test` (۶۸ تست).

## P0 — بحرانی (قبل از هر Merge)

| گروه | تست‌ها | خودکار؟ |
|---|---|---|
| احراز هویت | AUTH-01..04، 08، 10، 12..14، 19 | بخشی (`AisoFlowIntegrationTest`) |
| مجوزها | ROL-03، 04، 05، 11..22، 25..28 ، SEC-01..07، 12، 15 | بخشی |
| ورود Excel | IMP-02، 03، 05..08، 10..13، 16، 18، 20 | بخشی (چرخه/مرجع نامعتبر/قالب) |
| وابستگی‌ها | OPR-29، 30، 31 ، USR-07 | بله |
| زمان‌بندی | SCH-01، 02a/b، 03، 04، 05، 08 | بله (`SchedulePlannerTest`) |
| تخصیص | ASG-01..05، 07..09، 13 | بله (`AlgorithmStrategyTest`) |
| تأیید مدیر | APR-01، 02، 04، 05، 09، 10، 13 | بخشی |
| LLM | LLM-01، 03، 05..09، 13 | بله (`LlmStrategyValidationTest`) / LLM-03 دستی |
| گردش کار | OPR-03..05، 09..12، 14، 16، 19، 22، 24 ، USR-02..06، 08 | بخشی |
| پایداری | PRJ-06، E2E-26 | دستی |
| E2E | کل بخش ۱۵ | دستی |

## P1 — مهم (هر Release)
بقیهٔ تست‌های IMP/OPR/APR، SCH-06، 07، 09..12، ASG-06، 10..12، 14، LLM-02، 10..15 ، USR-10..13، ROL-06..10، 23..24، 29..31 ، SEC-08..11، 13..14، 16..18 ، UI-02..13، 17، PRJ-01..05، 07، 09.

## P2 — معمولی (دوره‌ای)
AUTH-20، SCH-14، IMP-23، 24، PRJ-08، 10 ، SEC-19، 20 ، UI-14..16.

> دستور تست‌های خودکار: `cd backend ; mvn test` (انتظار: Tests run: 68, Failures: 0)؛ Frontend: `cd frontend ; npm run lint ; npx tsc --noEmit ; npm run build`.

---

# ۱۷. قالب گزارش باگ (Bug Report Template)

```markdown
**Bug ID:** BUG-YYYYMMDD-NN
**Severity:** Blocker / Critical / Major / Minor / Trivial
**Test case:** (مثلاً SCH-04 یا ASG-09)
**Environment:** OS / مرورگر + نسخه / Backend (jar یا mvn) / Frontend (dev یا start) / DB (H2 file | mem | PostgreSQL) / ANTHROPIC_API_KEY (دارد/ندارد) / Commit
**Preconditions:** (داده/فایل ورودی از test-data، نقش کاربر، تنظیمات: سقف وظایف، حالت تخصیص، تعلیق)
**Steps to reproduce:**
1.
2.
3.
**Expected result:**
**Actual result:**
**Screenshots / logs:** (Console مرورگر، Network، لاگ Backend)
**API response (در صورت وجود):** (متد + مسیر + کد HTTP + بدنهٔ JSON)
**Status:** New / Confirmed / In progress / Fixed / Verified / Won't fix
**Notes:**
```

---

# ۱۸. چک‌لیست نهایی

```
[ ] Authentication            (AUTH-01..20)
[ ] Authorization / Roles     (ROL-01..31)
[ ] Project (single project)  (PRJ-01..10)  ← فقط تنظیمات پروژه؛ ساخت پروژه وجود ندارد
[ ] Excel import              (IMP-01..24)
[ ] Operations                (OPR-01..34)
[ ] Dependencies              (OPR-29..31، SCH-09/10)
[ ] Scheduling                (SCH-01..14)
[ ] Critical Path             (SCH-03/04/07، ASG-04)
[ ] Resource capacity         (SCH-02/05/08، ASG-03)
[ ] Assignment                (ASG-01..14)
[ ] Manager approval          (APR-01..16)
[ ] LLM                       (LLM-01..15)  ← مسیر موفق با کلید واقعی هنوز اجرا نشده
[ ] Task execution (User)     (USR-01..13)
[ ] Security                  (SEC-01..20)
[ ] UI / RTL / Responsive     (UI-01..17)
[ ] End-to-End                (E2E-01..26)
[ ] Regression suite (mvn test = 38 pass)
```

---

# ۱۹. شکاف‌ها و تفاوت‌های کشف‌شده

## الف) مستند شده ولی پیاده‌سازی نشده (🚫)

1. **ساخت پروژه با فرم دستی**: ندارد؛ پروژه فقط از راه ورود Excel ساخته می‌شود (نام/شناسه/سررسید هنگام ورود، بعداً قابل ویرایش در «پروژه‌ها و اولویت‌ها»). همچنین هر فایل Excel فقط یک پروژه است.
2. **ساخت/ویرایش/حذف دستی عملیات، منبع، قطعه (BOM)، پیش‌نیاز** در UI/API: فقط از راه Excel. (قطعات BOM بدون هیچ نمایشی در UI ذخیره می‌شوند.)
3. **وضعیت `PENDING`** (بنابراین مسیر `PENDING→IN_PROGRESS→COMPLETED` مطرح‌شده در درخواست تست نمی‌شود؛ مسیر واقعی در بخش ۱۲).
4. **اتصال Telegram/پیام‌رسان خارجی**: هر پلتفرم غیر از `IN_APP` ⇒ ارسال `FAILED` (شفاف) ✅ (دیده شد: `No connector installed for messenger platform TELEGRAM`، شمارندهٔ «پیام‌های ناموفق» ۲، `GET /notifications/failed`).
5. **Backup/Restore**، **انتقال مالکیت (Owner transfer)**، **بازیابی رمز مالک**، **rate-limit ورود**، **ابطال سمت‌سرور توکن (logout واقعی)**: وجود ندارند.
6. **تقویم کاری/شیفت** در زمان‌بندی؛ ضرب‌شدن `Quantity` در مدت.
7. **مسیر موفق LLM با کلید و مدل واقعی** هنوز اجرا/تأیید نشده.

## ب) وجود دارد ولی کم‌مستند

1. تنظیم «عملیات بعدی منتظر تأیید مدیر بماند» (`requireCompletionApproval`).
2. مجری ثابت (`Responsible_User_ID`) در OPC به‌عنوان **قید سخت** و امتیاز ۸ساعته برای «مسئول منبع» به‌عنوان **ترجیح نرم**.
3. پیش‌نیاز `START_TO_START` و `Is_Mandatory=FALSE` (ستون اضافه نسبت به سند اصلی).
4. fallback خودکار LLM→Algorithm و گزینهٔ غیرفعال‌سازی آن.
5. تعلیق سیستم (HTTP 423) و «همه‌یا‌هیچ» بودن ورود Excel.
6. اعتبارسنجی **دو لایه** ورود Excel.

## ج) ناسازگاری‌ها / نقص‌های کوچک پیدا‌شده

| # | یافته | شدت پیشنهادی | تست مرتبط |
|---|---|---|---|
| 1 | tail (وزن بحرانی) یال‌های **اختیاری** و **SS** را مثل زنجیرهٔ ترتیبی می‌شمارد (A=۱۴ به‌جای ۱۰ در S09/S10) | Minor | SCH-09/10 |
| 2 | پیام «No executive user is below the active-task limit» وقتی علت واقعی «همهٔ کاربران غیرفعال» است گمراه‌کننده است | Minor | ASG-10 |
| 3 | `approve`/`reject` برای شناسه‌های ناموجود/غیر PENDING/قبلاً تأییدشده خطا نمی‌دهد (200 با لیست خالی) و UI این را به‌صورت «۰ مورد تأیید شد» نشان می‌دهد | Minor | APR-05..07 |
| 4 | خطاهای Excel در دو لایه گزارش می‌شوند (باید چند بار فایل را اصلاح و دوباره بارگذاری کرد) | Minor | IMP-08/09 |
| 5 | logout توکن را باطل نمی‌کند (JWT stateless) | Info/Minor | AUTH-11 |
| 6 | رمز seed پیش‌فرض `ChangeMe!123` برای هر ۵ حساب | Major در محیط واقعی | ENV |
| 7 | «ورود Excel» ساختار عملیات آغازشده را رد می‌کند (`its resource cannot be changed`): رفتار درست ولی برای کاربر ممکن است غیرمنتظره باشد؛ راه‌حل: لغو یا Reset | Info | IMP-22 |
| 8 | ورود مجدد، پیش‌نیازها را «ایجاد» می‌شمارد (حذف+ساخت) نه «به‌روزرسانی» | Trivial | IMP-17 |
| 9 | پس از فعال/غیرفعال‌کردن کاربران برای تست، حالت آن‌ها پس از `reset` برنمی‌گردد (reset کاربران را لمس نمی‌کند) | Info | §۱.۶ |

---

# ۲۰. فایل‌های تست (`test-data/`)

تولیدشده با `python test-data/generate_test_data.py`. همگی قالب Master (شیت‌های `Resources`, `OPC`, `Predecessors`)؛ کاربران در فایل‌ها نیست (از کاربران seed استفاده می‌شود).

| فایل | استفاده |
|---|---|
| `S01-sequential` … `S11-running-task` | سناریوهای زمان‌بندی SCH-01..11 |
| `A1-three-equal`, `A3-task-limit`, `A4-capacity`, `A5-critical-path`, `A6-fixed-executor`, `A7a/A7b-responsible-user-*` | تخصیص ASG-01..14، تأیید APR، کار کاربر OPR/USR |
| `M1-alpha`, `M2-beta`, `M3-gamma` | چندپروژه‌ای MPR-01..19 (دو ماشین مشترک WELD و PAINT) |
| `I01-valid` | داده مثال ۲.۲ و E2E |
| `I02-missing-column`, `I03-missing-sheet`, `I04-invalid-values`, `I05-bad-references`, `I05b-bad-dependency-type`, `I06-cycle`, `I07-duplicates`, `I08-headers-only`, `I09-partial-bad-row`, `I10-users-owner` | ورود Excel (IMP-05..19) |
| `I11-empty.xlsx` (۰ بایت), `I12-malformed.xlsx`, `I13-not-excel.txt` | فایل خالی/خراب/غیرExcel (IMP-12..14) |
| `../simple excel.xlsx` | نمونهٔ واقعی (IMP-03، SCH-12) |

**منبع حقیقت این سند:** کلاس‌های `SchedulePlanner`, `AlgorithmStrategy`, `AssignmentState`, `LlmStrategy`, `AssignmentService`, `OperationService`, `DependencyService`, `ImportService`/`MasterParser`/`SimpleParser`, `SecurityConfig`, `SuspensionInterceptor` و کنترلرهای `web/*`. مقدارهای علامت‌دار ✅ با اجرای واقعی روی Backend در دسترس تأیید شده‌اند؛ مقدارهای 🔎 باید هنگام اجرای اول مشاهده و در صورت اختلاف همین سند اصلاح شود.

---

# ۲۱. چند پروژهٔ همزمان، فارسی و تقویم شمسی

> این بخش ویژگی‌های نسخهٔ ۰.۲ را پوشش می‌دهد. داده‌ها: `M1-alpha.xlsx` (پروژهٔ «آلفا»: `A-W1` جوش ۱۰ساعته ← `A-P1` رنگ ۶ساعته ← `A-W2` جوش ۴ساعته)، `M2-beta.xlsx` («بتا»: `B-W3` جوش ۵ساعته و `B-P3` رنگ ۸ساعته، مستقل) و `M3-gamma.xlsx`. هر دو ماشین **WELD** و **PAINT** ظرفیت ۱ دارند و بین پروژه‌ها مشترکند. قبل از شروع: `reset`.
> زمان‌ها «ساعت نسبت به لحظهٔ صفر برنامه» هستند (مثل بخش ۸). همهٔ مقدارهای ✅ با اجرای واقعی تأیید شده‌اند.

**پیش‌محاسبهٔ دستی (برای اینکه خودتان تأیید کنید):**
* آلفا تنها: WELD: `A-W1` 0–10، (خالی 10–16)، `A-W2` 16–20 → busy=14، انتهای خط=20 → تلف **۶**. PAINT: `A-P1` 10–16 → busy=6، انتها=16 → تلف **۱۰**. جمع تلف = **۱۶** ساعت؛ بهره‌وری = 20÷36 = **۵۵٫۶٪**؛ پایان = ۲۰.
* افزودن بتا با اولویت پایین‌تر: `B-P3` (۸ساعت) در خالیِ PAINT از 0 تا 10 می‌نشیند (0–8)؛ `B-W3` (۵ساعت) در خالیِ WELD (10–16، شش ساعت) می‌نشیند (10–15). آلفا همچنان 0–20. تلف: WELD: انتها 20، busy 19 → ۱؛ PAINT: انتها 16، busy 14 → ۲؛ جمع = **۳** ساعت؛ بهره‌وری = 33÷36 = **۹۱٫۷٪**؛ پایان آلفا ۲۰، بتا ۱۵.
* بتا اول: `B-P3` 0–8، `B-W3` 0–5؛ `A-W1` 5–15؛ `A-P1` 15–21 (پس از پایان `A-W1`)؛ `A-W2` 21–25. پایان: بتا ۸، آلفا ۲۵.

## ۲۱.۱ چند پروژه و اولویت (MPR)

| ID | P | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|
| MPR-01 | P0 | `reset`؛ ورود `M1-alpha.xlsx` بدون هیچ انتخاب پروژه (UI: «ورود اطلاعات Excel» ← پروژهٔ جدید بدون نام، یا API بدون پارامتر) | APPLIED؛ پروژهٔ `P-001` («AISO Project» از تنظیمات سیستم) با اولویت ۱ ساخته می‌شود؛ **هیچ پرسش اولویتی** نیست ✅ |
| MPR-02 | P0 | وضعیت آلفا تنها: «پروژه‌ها و اولویت‌ها» / `GET /scheduling/overview` | پایان ۲۰ ساعت؛ زمان تلف‌شده **۱۶**؛ بهره‌وری **۵۵٫۶٪**؛ ترتیب: `A-W1` 0–10، `A-P1` 10–16، `A-W2` 16–20 ✅ |
| MPR-03 | P0 | ورود `M2-beta.xlsx` با «ساخت پروژهٔ جدید»: نام `Beta`، شناسه `BETA`؛ **فقط اعتبارسنجی** | وضعیت **PRIORITY_REQUIRED**؛ `activeProjects=[P-001 با اولویت ۱]`؛ تأثیر پیش‌فرض (بتا آخر): آلفا 20→20، بتا جدید→15، زمان تلف‌شده 16→**۳** ✅؛ **هیچ چیزی ذخیره نشده** (عملیات هنوز ۳ تاست) ✅ |
| MPR-04 | P0 | در UI: ویرایشگر اولویت ظاهر می‌شود؛ بتا با نشان «جدید» **آخر** فهرست است؛ «ورود با این اولویت» (آلفا ۱، بتا ۲) | APPLIED؛ `GET /projects`: `P-001`=۱، `BETA`=۲ ✅. برنامه: `A-W1` 0–10، `B-P3` 0–8، `A-P1` 10–16، `B-W3` 10–15، `A-W2` 16–20؛ تلف **۳** ساعت؛ بهره‌وری **۹۱٫۷٪** ✅. در Audit: `PROJECT_CREATED` و `PROJECT_PRIORITIES_CHANGED` |
| MPR-05 | P0 | **اولویت ضمانت‌شده:** پایان آلفا را در MPR-02 و MPR-04 مقایسه کنید | هر دو **۲۰** ساعت: پروژهٔ کم‌اولویت‌تر (بتا) آلفا را عقب نینداخت و فقط جاهای خالی را پر کرد ✅ |
| MPR-06 | P0 | «پروژه‌ها و اولویت‌ها»: بتا را با ▲ بالاتر ببرید ← «اعمال اولویت‌ها» (یا `PUT /projects/priorities` با `["BETA","P-001"]`) | برنامه دوباره محاسبه می‌شود: `B-P3` 0–8، `B-W3` 0–5، `A-W1` 5–15، `A-P1` 15–21، `A-W2` 21–25؛ پایان بتا **۸**، آلفا **۲۵**؛ تلف **۱۳** ساعت ✅ |
| MPR-07 | P1 | «پیش‌نمایش اثر» در حالتی که بتا اول است و ترتیب پیشنهادی «آلفا، بتا» (`POST /scheduling/preview`) | `deltas`: آلفا **−۵** ساعت (زودتر)، بتا **+۷** ساعت (دیرتر) ✅؛ ترتیب ذخیره‌شده تغییر **نمی‌کند** ✅ |
| MPR-08 | P0 | با آلفا اول: «تخصیص وظایف» ← پیشنهاد (الگوریتم) | منبع WELD ظرفیت ۱ دارد: **`A-W1`→user1** و **`B-P3`→user2** (روی PAINT)؛ `B-W3` «تخصیص داده نشد»: `Resource Welding has no free slot (1 of 1 busy)`؛ دلیل شامل `project AISO Project (priority 1)` است ✅ |
| MPR-09 | P0 | ورود دوباره `M2-beta.xlsx` به‌عنوان پروژهٔ جدید `Copy` با ranking معتبر | REJECTED: `Operation 'B-W3' already belongs to project BETA; operation ids must be unique across projects` (در فارسی: «عملیات «B-W3» از قبل متعلق به پروژهٔ BETA است…») ✅ |
| MPR-10 | P0 | با دو پروژهٔ فعال، ورود بدون انتخاب پروژه/ساخت پروژهٔ جدید | REJECTED: `Several projects are active. Choose the target project…` ✅ |
| MPR-11 | P0 | ranking ناقص (`NEW`) یا با شناسهٔ ناموجود (`NEW,GHOST`) | REJECTED با خطای `ranking` ✅ |
| MPR-12 | P1 | ورود `M3-gamma.xlsx` (پروژهٔ جدید `Gamma`) در UI؛ آخرین مورد را با ▲ یک پله بالا ببرید و «ورود با این اولویت» | ویرایشگر سه مورد دارد (جدید آخر)؛ بعد از ورود: اولویت‌ها آلفا ۱، **گاما ۲**، بتا ۳ ✅؛ پیش‌نمایش بعد از هر جابه‌جایی به‌روز می‌شود ✅ |
| MPR-13 | P1 | سررسید: در «پروژه‌ها و اولویت‌ها» برای یک پروژه `۱۴۰۵/۰۷/۲۵` (یا `1405/07/25`) بنویسید ← اعمال | تاریخ پذیرفته می‌شود و **۱۷ اکتبر ۲۰۲۶** (آخر آن روز به وقت تهران) ذخیره می‌شود ✅؛ اگر پایان پیش‌بینی‌شده بعد از آن باشد، ستون «تأخیر» نشان می‌دهد؛ سررسید هرگز ترتیب کارها را عوض نمی‌کند |
| MPR-14 | P1 | پروژه‌ای با عملیات ناتمام را بایگانی کنید (`PATCH /projects/{id}` با `{"status":"ARCHIVED"}`) | **409**؛ دکمهٔ «بایگانی» در UI غیرفعال است ✅. پس از تکمیل/لغو همهٔ عملیات: بایگانی می‌شود و از رتبه‌بندی خارج و رتبه‌ها دوباره ۱..n می‌شود 🔎 |
| MPR-15 | P1 | «روش ساده» در برابر بهینه: `GET /scheduling/overview` یا کارت‌های صفحهٔ پروژه‌ها | `optimized.wasteHours ≤ naive.wasteHours` همیشه ✅؛ `candidatesTried > 1` ✅؛ در دادهٔ M1+M2 هر دو ۳ ساعت‌اند (چیدن ساده همین‌جا هم بهینه است) ✅ |
| MPR-16 | P1 | کاربر اجرایی: `GET /projects`، `POST /scheduling/preview`، `PUT /projects/priorities` | هر سه **403** ✅ |
| MPR-17 | P1 | فیلتر پروژه در «عملیات» و «برنامه‌ریزی»؛ ستون «پروژه» در جدول؛ نام پروژه در tooltip نوارها و کارت «وظایف من» | فقط عملیات همان پروژه دیده می‌شود ✅ |
| MPR-18 | P1 | ورود فایلی که ظرفیت یک منبع مشترک را عوض می‌کند (مثلاً WELD با ظرفیت ۲) | هشدار: `Resource WELD is shared by all projects: its capacity changes from 1 to 2.` (فارسی: «منبع WELD بین همهٔ پروژه‌ها مشترک است…») 🔎؛ برنامهٔ همهٔ پروژه‌ها با ظرفیت جدید دوباره محاسبه می‌شود |
| MPR-19 | P1 | «بازنشانی داده‌های عملیاتی» | **پروژه‌ها نیز حذف می‌شوند**؛ ورود بعدی بدون پارامتر دوباره پروژهٔ پیش‌فرض می‌سازد ✅ |
| MPR-20 | P0 | **ارتقای دیتابیس قدیمی:** برنامه را روی دیتابیسی که با نسخهٔ قبل ساخته شده اجرا کنید | مهاجرت‌های `V2` و `V3` خودکار اجرا می‌شوند ✅ (لاگ: `Migrating schema ... to version "2 - projects"`). عملیات موجود در یک پروژه (شناسه و نام از تنظیمات قدیمی) می‌روند؛ داده‌ای گم نمی‌شود ✅ (تست `MigrationTest` + ارتقای واقعی) |
| MPR-21 | P2 | گزارش Excel | شیت «Projects/پروژه‌ها» و ستون پروژه در شیت عملیات ✅ |
| MPR-22 | P1 | عملیات در حال انجام + پروژهٔ جدید با اولویت بالا (S11 را با پروژهٔ دوم ترکیب کنید) | عملیات «در حال انجام» جابه‌جا **نمی‌شود** (اسلاتش از لحظهٔ ۰ اشغال است)؛ پروژهٔ جدید بعد از آن می‌نشیند 🔎 (تست واحد `runningWorkIsNeverMovedByANewHigherPriorityProject` ✅) |

## ۲۱.۲ فارسی و تقویم شمسی (PER)

| ID | P | مراحل | نتیجهٔ مورد انتظار |
|---|---|---|---|
| PER-01 | P0 | «دریافت قالب» در حالت فارسی (`GET /import/template?lang=fa`) | شیت‌ها: `منابع، قطعات، عملیات، پیش‌نیازها، کاربران، تنظیمات`؛ سرستون‌ها فارسی (مثلاً «شناسه منبع»)؛ شیت‌ها راست‌به‌چپ؛ نام فایل `AISO-قالب-اصلی.xlsx` ✅ |
| PER-02 | P0 | قالب فارسیِ دانلودشده را **بدون هیچ تغییری** وارد کنید (اعتبارسنجی، سپس ورود) | VALID سپس APPLIED؛ عملیات `OP-001` و `OP-002` ساخته می‌شوند ✅ (مقدارهای فارسی `فعال`، `پایان به شروع`، `بله`، `کاربر ۱` پذیرفته می‌شوند) |
| PER-03 | P1 | فایل ترکیبی: نام شیت‌ها فارسی ولی بعضی سرستون‌ها انگلیسی | پذیرفته می‌شود (هر سرستون جداگانه به نام استاندارد نگاشت می‌شود) 🔎 |
| PER-04 | P0 | «دریافت گزارش Excel» در حالت فارسی (`GET /reports/export?lang=fa`) | شیت‌ها: `پروژه‌ها، عملیات، عملکرد کاربران، تاریخچه ورود، ممیزی` (راست‌به‌چپ)؛ تاریخ‌ها شمسی با ارقام فارسی مثل `۱۴۰۵/۰۷/۱۶ ۰۴:۵۵`؛ وضعیت‌ها فارسی («در حال انجام»)؛ اقدام‌های ممیزی فارسی ✅ |
| PER-05 | P1 | همان گزارش با `lang=en` | شیت‌های `Projects, Operations, User_Performance, Import_History, Audit`؛ تاریخ ISO ✅ |
| PER-06 | P0 | پیام‌های خطا با `Accept-Language: fa` (UI خودش می‌فرستد) | `عملیات ZZZ پیدا نشد` (404)؛ ورود نادرست: `شناسه کاربر یا رمز عبور نادرست است`؛ `این عملیات به شما تخصیص داده نشده است` (403) ✅؛ با `en` انگلیسی ✅ |
| PER-07 | P1 | یافته‌های ورود Excel در حالت فارسی | مثل `منبع «NOPE» وجود ندارد`، `عملیات «B-W3» از قبل متعلق به پروژهٔ BETA است؛ شناسهٔ عملیات باید در همهٔ پروژه‌ها یکتا باشد` ✅. نام شیت/ستون‌ها در جدول خطا به همان شکلی است که در فایل هست |
| PER-08 | P1 | کنسول مالک ← «زبان متن‌های سیستم» = فارسی ← تخصیص دستی `OP-001` به user1 | اعلان user1: `وظیفهٔ جدید: OP-001 (…)` ✅؛ دلیل پیشنهادهای بعدی فارسی (`رتبه ۱ (زنجیره …`) ✅؛ مقدار `de` ⇒ 400 `language must be fa or en` ✅. متن‌های **قبلی** تغییر نمی‌کنند |
| PER-09 | P1 | نصب تازه (بدون دیتابیس قبلی) | زبان متن‌های سیستم پیش‌فرض **فارسی** است (`AISO_LANGUAGE=fa`)؛ در دیتابیس ارتقایافته **انگلیسی** می‌ماند تا مالک عوض کند ✅ |
| PER-10 | P1 | کادر تاریخ (سررسید) در حالت فارسی: `۱۴۰۵/۰۷/۲۵` | زیر کادر تاریخ به حروف می‌آید (مثلاً «۲۵ مهر ۱۴۰۵»)؛ `۱۴۰۵/۱۳/۰۱` ⇒ «تاریخ شمسی معتبر نیست» 🔎؛ در حالت انگلیسی انتخابگر تاریخ میلادی |
| PER-11 | P1 | تاریخ‌ها در همهٔ صفحه‌ها (داشبورد، برنامه‌ریزی، ممیزی، اعلان‌ها) | همه شمسی، با ارقام فارسی، به وقت تهران ✅ (۴ اکتبر ۲۰۲۶ = ۱۲ مهر ۱۴۰۵) |
| PER-12 | P1 | درستی تبدیل تاریخ | `mvn test -Dtest=JalaliTest`: نوروز ۱۴۰۴=۲۱ مارس ۲۰۲۵؛ ۱۲ مهر ۱۴۰۵=۴ اکتبر ۲۰۲۶؛ ۳۰ اسفند ۱۴۰۳ (سال کبیسه)؛ رفت‌وبرگشت ۲۰۲۰–۲۰۳۱ ✅ |
| PER-13 | P2 | نام فایل‌های دانلودی فارسی | مرورگر نام را درست نشان می‌دهد (`filename*=UTF-8''…`) 🔎 |
| PER-14 | P2 | جدول ممیزی در UI | اقدام‌ها فارسی («تغییر وضعیت»، «تخصیص»)؛ اقدام ناشناخته به‌صورت کد نشان داده می‌شود 🔎 |
