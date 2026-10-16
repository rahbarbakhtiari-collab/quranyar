# سند ممیزی نهایی و نقشه اصلاح مرحله‌ای قرآن‌یار

**نام پروژه:** قرآن‌یار  
**مبنای ممیزی:** QuranYar Messenger `1.3.1-rc11`
**آخرین نسخه اصلاحی طبق این سند:** QuranYar Messenger `1.3.1-rc53`  
**Schema مبنا:** `2026.10.05.2`  
**آخرین Schema اصلاحی:** `2026.10.11.2`  
**تاریخ ممیزی:** ۱۴۰۵/۰۷/۱۳ — 2026-10-05  
**نوع سند:** مرجع فنی برای ادامه اصلاحات، کنترل Regression و برنامه‌ریزی نسخه‌های بعدی  
**وضعیت:** مصوب برای استفاده به‌عنوان Roadmap فنی پروژه

---

## 1. هدف سند

این سند جمع‌بندی نهایی ممیزی هفت‌مرحله‌ای قرآن‌یار است و باید به‌عنوان مرجع اصلی اصلاحات آینده در سورس پروژه نگهداری شود.

ابعاد ممیزی:

1. معماری و منطق اجرای سیستم
2. ماژولار بودن واقعی
3. Performance
4. قابلیت توسعه و نگهداری
5. UX
6. UI
7. کیفیت، پایداری و امنیت مرتبط با معماری

این سند **کد اصلاحی ارائه نمی‌کند**. هدف آن تعیین اولویت‌ها، ترتیب اصلاح، معیار پذیرش و جلوگیری از اصلاحات پراکنده و موردی است.

---

# 2. جمع‌بندی مدیریتی

قرآن‌یار در RC11 دیگر یک افزونه ساده نیست و هسته‌های مهم زیر را دارد:

- احراز هویت برنامک ایتا با `initData`
- پرونده عضو
- معماری چنددانشگاهی
- پنل دانشگاه
- پیام‌رسانی و Queue
- ارسال هدفمند و Excel
- تولد
- برنامه‌ها و ثبت‌نام
- فرم‌های نسخه‌دار
- Enrollment
- حضور و غیاب
- QR ثابت
- سوابق عضو
- Health Center
- گزارش و خروجی

پایه سیستم در مجموع قابل ادامه است، اما در وضعیت فعلی مهم‌ترین ریسک پروژه **افزودن قابلیت‌های جدید بدون تثبیت مرزهای معماری** است.

سه محور بیشترین اولویت را دارند:

1. **مرز اعتماد چنددانشگاهی و مجوزها**
2. **تمامیت داده و بازیابی خطاهای عملیاتی**
3. **کاهش coupling و هزینه Performance در مسیرهای پرتکرار**

تا پایان اصلاحات اولویت P0 و P1، توسعه Featureهای بزرگ جدید باید محدود شود.

---

# 3. طبقه‌بندی اولویت‌ها

## P0 — Stop-Ship / Critical

مشکلاتی که می‌توانند باعث شکستن مرز دانشگاه‌ها، دسترسی به داده دانشگاه دیگر، یا نقض جدی تمامیت داده شوند.

**قاعده:** قبل از گسترش واقعی چنددانشگاهی باید بسته شوند.

## P1 — High / Production Reliability

مشکلاتی که ممکن است باعث از دست رفتن پیام، state ناسازگار، خطای جدی مدیریتی، مصرف غیرضروری منابع یا اختلال محسوس در عملیات شوند.

**قاعده:** قبل از افزایش مقیاس استفاده یا ورود دانشگاه‌های بیشتر اصلاح شوند.

## P2 — Medium / Maintainability & UX

مشکلاتی که امروز الزاماً باعث خرابی نمی‌شوند اما هزینه توسعه، احتمال Regression و اصطکاک کاربران را افزایش می‌دهند.

## P3 — Low / Polish & Platformization

موارد بهبود معماری، UI و استانداردسازی که پس از پایدارشدن Core انجام می‌شوند.

---

# 4. ثبت اصلی مشکلات — Master Issue Register

## P0 — Critical

### QYS-P0-01 — تغییر دانشگاه بدون ابطال تأیید عضو

**بُعد:** معماری و منطق  
**وضعیت:** مشکل قطعی

**محل اصلی:**
- `src/Members/MembersModule.php`
- `src/Programs/ProgramService.php`
- `src/Attendance/RecurringAttendance.php`

**مشکل:**  
تغییر دانشگاه عضو به‌عنوان تغییر هویتی در نظر گرفته نمی‌شود و `verified` می‌تواند حفظ شود.

**اثر:**  
عضو تأییدشده دانشگاه A می‌تواند دانشگاه خود را به B تغییر دهد و در دانشگاه B همچنان تأییدشده تلقی شود.

**اصلاح هدف:**  
تغییر دانشگاه باید یک فرآیند انتقال مستقل باشد. تا زمان تأیید دانشگاه مقصد، دامنه عملیاتی عضو نباید تغییر کند.

**معیار پذیرش:**
- تغییر دانشگاه هرگز تأیید مقصد را به‌صورت ضمنی ایجاد نکند.
- نماینده دانشگاه مقصد باید انتقال را تأیید کند.
- Program و Attendance فقط University Membership معتبر را بپذیرند.
- تست cross-tenant اجباری باشد.

---

### QYS-P0-02 — یکی‌شدن Visibility و Authority در برنامه‌های چنددانشگاهی

**بُعد:** معماری و منطق  
**وضعیت:** مشکل قطعی

**محل اصلی:**
- `src/Programs/ProgramRepository.php`
- `src/Programs/ProgramsModule.php`

**مشکل:**  
نماینده دانشگاهی که Program برای آن قابل مشاهده است می‌تواند در برخی مسیرها Program مشترک را مدیریت کرده و متقاضیان سایر دانشگاه‌ها را نیز مشاهده کند.

**اثر:**  
احتمال مشاهده، ویرایش یا خروجی اطلاعات متقاضیان دانشگاه دیگر.

**اصلاح هدف:**  
سه مفهوم باید کاملاً مستقل شوند:

- `Visibility`
- `Ownership / Management Authority`
- `Applicant Data Scope`

**معیار پذیرش:**
- نماینده A فقط درخواست‌های A را ببیند.
- Program مشترک فقط توسط مالک یا مدیرکل ویرایش شود مگر Delegation صریح.
- Sensitive Export فقط داده همان Tenant را شامل شود.
- تست A/B/C برای همه CRUDهای Program اجرا شود.

---

# 5. P1 — مشکلات با اولویت بالا

## QYS-P1-01 — Eligibility فقط هنگام Submit کنترل می‌شود

**بُعد:** معماری  
**وضعیت:** قطعی

Eligibility باید هنگام:

- Submit
- Accept
- Enroll
- Attendance

از یک Policy واحد بررسی شود.

**ریسک فعلی:** فردی که بعد از درخواست، دانشگاه/نقش/تأیید او تغییر کرده است همچنان ممکن است Enrollment بگیرد.

---

## QYS-P1-02 — مدل تحویل پیام و Recovery ناکافی

**بُعد:** معماری / پایداری  
**وضعیت:** قطعی

وضعیت‌های فعلی Queue برای تشخیص این موارد کافی نیستند:

- شکست قطعی
- شکست موقت
- نتیجه نامعلوم
- ارسال موفق
- Worker crash بعد از Claim

**هدف:** تعریف stateهای صریح مانند:

- `pending`
- `sending`
- `sent`
- `retryable_failed`
- `definitive_failed`
- `uncertain`

و lease برای `sending`.

---

## QYS-P1-03 — Welcome/Activation در خطا ممکن است برای همیشه متوقف شود

**بُعد:** پایداری  
**وضعیت:** قطعی

وجود اولین log مانع retry می‌شود حتی اگر ارسال قبلی failed یا stuck شده باشد.

**هدف:** recovery کنترل‌شده و idempotent.

---

## QYS-P1-04 — CSV Injection در Export برنامه‌ها

**بُعد:** امنیت/پایداری  
**وضعیت:** قطعی

خروجی Program باید همان محافظ `csv_safe()` مورد استفاده در بخش‌های دیگر را اعمال کند.

**معیار پذیرش:** مقادیر آغازشونده با `=`, `+`, `-`, `@` در Excel به فرمول اجرایی تبدیل نشوند.

---

## QYS-P1-05 — Retention Policy وجود ندارد

**بُعد:** پایداری / حریم خصوصی  
**وضعیت:** قطعی

داده‌های زیر می‌توانند بدون سقف زمانی نگهداری شوند:

- Queue history
- متن پیام
- Excel follow-up
- Birthday archive
- PII snapshotها
- Auditهای قدیمی

**هدف:** Retention Matrix + Cleanup Cron + Anonymization/Purge.

---

## QYS-P1-06 — Program و Attendance State کاملاً همگام نیستند

**بُعد:** معماری / پایداری  
**وضعیت:** قطعی

پایان یا لغو Program الزاماً child attendance/enrollmentها را به state سازگار منتقل نمی‌کند.

**هدف:** Transition Orchestrator برای stateهای terminal.

---

## QYS-P1-07 — Queue Throughput بسیار محدود

**بُعد:** Performance  
**وضعیت:** قطعی

Worker فعلی تقریباً ۵ پیام در دقیقه پردازش می‌کند.

تقریب تقریبی:

- ۵۰۰ پیام ≈ ۱۰۰ دقیقه
- ۵۰۰۰ پیام ≈ ۱۶ ساعت و ۴۰ دقیقه

**هدف:** پردازش تطبیقی بر اساس time budget و rate limit واقعی provider.

---

## QYS-P1-08 — Schema Introspection در Runtime

**بُعد:** Performance  
**وضعیت:** قطعی

توابع `schema_ready()` در مسیر عادی تعداد زیادی:

- `SHOW TABLES`
- `SHOW COLUMNS`

اجرا می‌کنند.

**هدف:** Cache request-level و استفاده از schema version؛ introspection فقط در Migration/Health Diagnostics.

---

## QYS-P1-09 — N+1 سنگین در Programs

**بُعد:** Performance  
**وضعیت:** قطعی

مشاهده Program، فرم‌ها، counts و export باعث query explosion می‌شود.

**هدف:** Queryهای batch/grouped.

---

## QYS-P1-10 — Health Snapshot سنگین در هر دقیقه

**بُعد:** Performance  
**وضعیت:** قطعی

هر Worker tick نباید تمام readinessها و aggregateهای کامل را محاسبه کند.

**هدف:**
- heartbeat سبک: هر دقیقه
- health snapshot کامل: دوره‌ای یا on-demand

---

## QYS-P1-11 — Eager Bootstrap و نبود Fault Isolation

**بُعد:** ماژولار بودن  
**وضعیت:** قطعی

تمام ماژول‌ها Load و Init می‌شوند؛ Fatal در یک Feature می‌تواند کل افزونه را مختل کند.

**هدف:** Module Manager با lifecycle مستقل و fail-safe.

---

## QYS-P1-12 — God Class مرکزی `QYS_Messenger`

**بُعد:** ماژولار بودن  
**وضعیت:** قطعی

Traitهای متعدد و dependencyهای گسترده باعث blast radius بالا شده‌اند.

**هدف:** استخراج سرویس‌های پایه:

- Config
- TokenStore
- TableNames
- TextNormalizer
- CalendarService
- Bootstrap Facade

---

## QYS-P1-13 — Circular Dependencies

**بُعد:** ماژولار بودن  
**وضعیت:** قطعی

نمونه‌های مهم:

- Programs ↔ Attendance
- Permission ↔ Targeted
- Security ↔ Multitenant
- FeatureRegistry ↔ Multitenant

**هدف:** dependency direction یک‌طرفه و interface/event boundary.

---

# 6. P2 — مشکلات متوسط

## معماری و منطق

### QYS-P2-01 — ظرفیت و Waitlist Policy یکپارچه نیست

پذیرش می‌تواند بیش از ظرفیت انجام شود و check اصلی دیرتر در Enrollment رخ دهد.

### QYS-P2-02 — Programهای بدون Registration از Read Model عمومی حذف می‌شوند

برنامه حضورمحور `registration_mode=none` باید قابل نمایش باشد ولی `can_apply=false`.

### QYS-P2-03 — دو منبع حقیقت برای باز/بسته بودن ثبت‌نام

`status` و `registration_*_at_utc` باید توسط یک `effective_registration_state()` واحد مصرف شوند.

---

## ماژولار بودن

### QYS-P2-04 — کلاس‌های بسیار بزرگ

نمونه‌های اصلی:

- `QYS_Attendance`
- `QYS_Multitenant`
- `Excel`
- `Birthday`

هرکدام چند مسئولیت دارند.

### QYS-P2-05 — FeatureRegistry فقط Navigation Registry است

Registry باید lifecycle کامل Feature را مدیریت کند.

### QYS-P2-06 — Persistence Boundary ناقص است

برخی Serviceها مستقیم SQL اجرا می‌کنند و Repository را دور می‌زنند.

---

## Performance

### QYS-P2-07 — Export Program دارای N+1 روی Answers است

همه Answers باید batch fetch شوند.

### QYS-P2-08 — Campaign counts با correlated query

باید grouped aggregation استفاده شود.

### QYS-P2-09 — Queue aggregate روی کل History

نیاز به index و retention/materialized counters دارد.

### QYS-P2-10 — Birthday با `LIKE '%MM/DD'`

**وضعیت فعلی:** ✅ بسته در RC34 / Phase D-D9.

مسیر leading-wildcard با کلید materialized `birth_md` و exact-match composite index جایگزین شد؛ رفتار دامنه تولد تغییر نکرد.

### QYS-P2-11 — Member Search با leading wildcard

برای شناسه‌ها exact/prefix search مناسب‌تر است.

### QYS-P2-12 — Asset Loading غیرشرطی در `/qys/`

Assets باید بر اساس view/feature بارگذاری شوند.

---

## توسعه و نگهداری

### QYS-P2-13 — Hook/Interface رسمی وجود ندارد

Core برای extension pointها آماده نیست.

### QYS-P2-14 — Migration Ledger وجود ندارد

یک `SCHEMA_VERSION` سراسری برای پروژه رو‌به‌رشد کافی نیست.

### QYS-P2-15 — Upgrade به `admin_init` وابسته است

امکان اجرای کد جدید روی schema قدیمی وجود دارد.

### QYS-P2-16 — Member App featureها hard-coded هستند

افزودن Feature جدید نیازمند تغییر Shell و JS مرکزی است.

### QYS-P2-17 — Message Provider قابل تعویض نیست

نیاز به Transport abstraction.

### QYS-P2-18 — Field Types در چند لایه duplicate هستند

نیاز به Field Registry.

### QYS-P2-19 — Permission Registry مستقل وجود ندارد

Permissionها به Multitenant گره خورده‌اند.

### QYS-P2-20 — REST Contract Governance ناقص است

باید schema و deprecation policy برای `/qys/v1` مشخص شود.

---

## UX

### QYS-P2-21 — تشکیل پرونده به رضایت دریافت پیام وابسته است

عضویت و Messaging Consent باید مستقل شوند.

### QYS-P2-22 — Gate برنامه‌ها بدون توضیح Redirect می‌کند

باید علت و CTA روشن داشته باشد.

### QYS-P2-23 — Actionهای برگشت‌ناپذیر confirmation ندارند

شامل:

- پایان برنامه
- لغو
- بایگانی
- رد متقاضی

### QYS-P2-24 — Program مشخصات اصلی قابل ویرایش نیست

Title, Description, Capacity, Audience و Attendance Link باید بر اساس state قابل ویرایش باشند.

### QYS-P2-25 — عضو امکان Withdraw ندارد

State وجود دارد ولی Journey ندارد.

### QYS-P2-26 — Home Programs خطا را با Empty یکی می‌کند

Error باید visible و retryable باشد.

### QYS-P2-27 — Optional Off به‌عنوان Attention نمایش داده می‌شود

خاموش‌بودن اختیاری نباید خطا تلقی شود.

### QYS-P2-28 — Draft فرم کاربر وجود ندارد

پاسخ فرم در refresh/navigation از دست می‌رود.

### QYS-P2-29 — Validation checkbox ناقص است

Required multi-select باید سمت client نیز enforce شود.

### QYS-P2-30 — مدیریت متقاضیان عملیات گروهی ندارد

نیاز به search/filter/bulk action.

---

## UI

### QYS-P2-31 — CSS Collision روی Button/Tab

Button primitive واحد لازم است.

### QYS-P2-32 — Sticky Quicknav زیر Header می‌رود

Offset responsive باید اصلاح شود.

### QYS-P2-33 — جدول متقاضیان Responsive واقعی نیست

روی موبایل باید card layout داشته باشد.

### QYS-P2-34 — Destructive Visual Hierarchy ضعیف است

Danger actions باید از primary/secondary جدا شوند.

### QYS-P2-35 — متن‌های Home Programs کوچک و contrast مرزی

Metadata باید خواناتر شود.

### QYS-P2-36 — Font Weightهای مصرفی با Font Assets هماهنگ نیستند

فقط وزن‌های واقعی استفاده شوند.

### QYS-P2-37 — Design Token مشترک وجود ندارد

Color/Spacing/Radius/Typography باید استاندارد شوند.

### QYS-P2-38 — Iconography Programs/Messaging تکراری است

Iconهای معنایی مستقل لازم است.

### QYS-P2-39 — اصطلاح «پیش‌ثبت‌نام» یکدست نیست

Glossary رسمی UI لازم است.

---

## پایداری

### QYS-P2-40 — Rate Limit اتمیک نیست

برای کد شش‌رقمی حضور اهمیت دارد.

### QYS-P2-41 — Date Validation فقط regex است

تاریخ واقعی باید validate شود.

### QYS-P2-42 — Supply-chain SDK ایتا

نیاز به CSP/monitoring/version governance دارد.

### QYS-P2-43 — DB Transaction Support بررسی نمی‌شود

جداول critical باید engine تراکنشی داشته باشند.

### QYS-P2-44 — PII در DB plaintext است

فعلاً اولویت اصلی retention و least privilege است؛ field encryption باید جدا بررسی شود.

---

# 7. P3 — بهبودهای بعدی

- Autoload کامل و کاهش eager file loading
- Event Contract Versioning در Frontend
- Member Role Registry
- Release CHANGELOG استاندارد
- Design System کامل
- استاندارد spacing/radius
- Accessible labels دقیق‌تر برای CTAها
- Deep-link به Program از Home
- drag/drop ترتیب سؤال‌ها
- Provider Registry چندکاناله
- Platform-level plugin SDK

---

# 8. نقشه اصلاح مرحله‌ای

## Phase A — تثبیت مرزهای اعتماد و تمامیت داده

**اولویت:** P0

### کارها

1. طراحی `University Transfer Workflow`
2. تفکیک Program Visibility / Ownership / Applicant Scope
3. بازبینی تمام REST/Admin endpoints از نظر Tenant
4. تست cross-tenant برای:
   - Members
   - Programs
   - Applications
   - Enrollments
   - Attendance
   - Exports
5. Revalidate eligibility در Accept/Enroll/Attendance
6. اصلاح CSV Injection در Program Export

### معیار خروج

- هیچ Representative نتواند داده دانشگاه دیگر را بخواند یا تغییر دهد.
- انتقال دانشگاه بدون تأیید مقصد ممکن نباشد.
- Export به tenant فعلی محدود باشد.
- تست امنیت چنددانشگاهی کامل PASS شود.

---

## Phase B — Reliability پیام و State

### کارها

1. طراحی Message Delivery State Model
2. Lease برای `sending`
3. recovery برای queue stuck
4. اصلاح Welcome/Activation retry semantics
5. تفکیک `uncertain` از `failed`
6. Program terminal transition orchestration
7. Capacity/Waitlist Policy مرکزی
8. Retention Policy

### معیار خروج

- هیچ `sending` رهاشده‌ای بدون تشخیص/بازیابی نماند.
- پیام موقتاً failed قابل تصمیم‌گیری باشد.
- پیام با وضعیت نامعلوم دوباره کورکورانه ارسال نشود.
- لغو/پایان Program stateهای وابسته را سازگار کند.
- cleanup دوره‌ای داده حساس فعال باشد.

---

## Phase C — زیرساخت Migration و Module Boundaries

### کارها

1. ایجاد Migration Ledger
2. حذف وابستگی Upgrade به صرف `admin_init`
3. Module lifecycle contract
4. استخراج سرویس‌های پایه از `QYS_Messenger`
5. شکستن cycleهای:
   - Programs ↔ Attendance
   - Permission ↔ Targeted
   - Security ↔ Multitenant
6. Repository ownership کامل
7. FeatureRegistry → Module Registry واقعی

### معیار خروج

- dependency graph Core یک‌طرفه شود.
- Feature بتواند مستقل health/boot/migrate شود.
- خرابی Feature غیرضروری کل افزونه را از کار نیندازد.
- Migrationها قابل تکرار و checkpointدار باشند.

---

## Phase D — Performance Hardening

### ترتیب

1. حذف runtime schema introspection
2. اصلاح N+1های Programs
3. Home Summary endpoint سبک
4. Lazy load Programs/Attendance در برنامک
5. Queue index `(status,id)`
6. Queue throughput adaptive
7. Health snapshot سبک
8. Batch export
9. Birthday index
10. conditional asset loading

### معیار خروج

- query count مسیرهای اصلی اندازه‌گیری و ثبت شود.
- بازشدن Home نیازمند دریافت Form کامل Programها نباشد.
- Worker ظرفیت عملی برای ارسال‌های دانشگاهی داشته باشد.
- Health Center باعث بار غیرضروری دقیقه‌ای نشود.

---

## Phase E — UX Safety & Workflow

### کارها

1. جداسازی Membership از Messaging Consent
2. Gate واضح Programs
3. Confirmation برای destructive actions
4. Program Edit workflow
5. Member Withdraw
6. Error/Empty/Loading استاندارد
7. Draft فرم
8. validation field-level
9. bulk applicant management
10. اصلاح progress پرونده

### معیار خروج

هر مسیر اصلی باید دارای:

- Entry State
- Loading State
- Success State
- Empty State
- Error State
- Recovery Action

باشد.

---

## Phase F — UI System

### کارها

1. Design Tokens
2. Button Variants
3. Typography Scale
4. Font Weight normalization
5. Responsive applicant cards
6. Sticky offset fix
7. destructive hierarchy
8. icon system
9. glossary و microcopy
10. contrast audit

### معیار خروج

- UI برنامک و پنل دانشگاه از tokenهای مشترک استفاده کنند.
- هیچ conflict ناشی از selector عمومی button وجود نداشته باشد.
- موبایل برای جداول اصلی نیازمند scroll افقی اجباری نباشد.
- WCAG visual checks پایه PASS شوند.

---

## Phase G — Platform Extensibility

این مرحله فقط بعد از تثبیت Core انجام شود.

### کارها

- `MessageTransportInterface`
- Field Type Registry
- Permission Registry
- Member Feature Registry
- Program Extension API
- Event Bus قرارداددار
- REST v1 governance
- Hookهای رسمی و محدود
- مستندات SDK داخلی

### هدف

حرکت از:

> Core قابلیت‌ها را می‌شناسد

به:

> قابلیت‌ها خودشان را در Core ثبت می‌کنند

---

# 9. ترتیب پیشنهادی نسخه‌ها

شماره نسخه نهایی هنگام اجرا قابل تصمیم است، اما ترتیب Release باید تقریباً چنین باشد:

### Release 1 — Security & Tenant Boundaries
Phase A

### Release 2 — Reliability & State Recovery
Phase B

### Release 3 — Migration & Modular Core
Phase C

### Release 4 — Performance
Phase D

### Release 5 — UX
Phase E

### Release 6 — UI
Phase F

### Release 7 — Extensibility Platform
Phase G

Featureهای محصول جدید بهتر است بعد از Release 2 فقط در صورت ضرورت اضافه شوند و بعد از Release 3 آزادانه‌تر توسعه پیدا کنند.

---

# 10. Regression Matrix اجباری

هر اصلاح باید حداقل این تست‌ها را پاس کند.

## Members

- تشکیل پرونده
- پرونده ناقص
- تأیید
- غیرفعال‌سازی پیام
- فعال‌سازی مجدد
- تغییر مشخصات
- تغییر دانشگاه
- دسترسی مدیر دانشگاه

## Multitenant

- مدیرکل
- نماینده ساوه
- نماینده دانشگاه دوم
- عدم مشاهده cross-tenant
- Export isolation
- Program مشترک

## Programs

- Draft
- Publish Form
- Form Versioning
- Registration
- Waitlist
- Accept
- Reject
- Enroll
- Withdraw
- Capacity
- Cancellation
- Completion

## Attendance

- QR قدیمی
- QR ثابت
- ورود
- خروج
- ورود تکراری
- خروج بدون ورود
- stale session
- پایان دستی occurrence
- Program cancelled
- Program completed

## Messaging

- immediate
- scheduled
- Excel
- Queue
- failure
- uncertain
- retryable
- permanent failure
- welcome
- activation

## Member App

- بدون پرونده
- پرونده ناقص
- تأییدنشده
- کامل
- پیام خاموش
- تولد خاموش
- برنامه فعال
- برنامه بدون Registration
- Network failure

---

# 11. قواعد اجباری برای هر اصلاح آینده

1. **هیچ تغییر زنده بدون تست Regression مرتبط منتشر نشود.**
2. **Schema change باید migration مستقل و idempotent داشته باشد.**
3. **هیچ Permission جدیدی فقط در UI enforce نشود؛ server-side اجباری است.**
4. **هر query روی داده چنددانشگاهی باید Tenant Scope صریح داشته باشد.**
5. **PII فقط در صورت نیاز نمایش/خروجی شود.**
6. **هیچ action برگشت‌ناپذیر بدون confirmation مناسب نباشد.**
7. **Feature جدید نباید state موجود را بی‌دلیل تغییر دهد.**
8. **هر worker باید heartbeat و recovery policy داشته باشد.**
9. **هر REST endpoint باید auth، validation، permission و error contract روشن داشته باشد.**
10. **هر feature public باید Loading / Empty / Error / Retry state داشته باشد.**
11. **قبل از Optimization، query count و زمان واقعی اندازه‌گیری شود.**
12. **هیچ refactor بزرگ بدون حفظ backward compatibility انجام نشود مگر با migration صریح.**

---

# 12. Definition of Done برای هر مرحله

یک مرحله فقط زمانی «بسته‌شده» است که:

- اصلاحات آن کامل شده باشند.
- تست‌های واحد/قراردادی مرتبط PASS باشند.
- regression matrix مربوطه PASS باشد.
- روی بسته ZIP نهایی دوباره تست انجام شده باشد.
- Schema و نسخه دقیق ثبت شده باشد.
- Release note نوشته شده باشد.
- rollback path مشخص باشد.
- Health Center بعد از نصب سالم باشد.
- هیچ داده قبلی بدون migration صریح حذف نشده باشد.
- هیچ PII جدید بدون ضرورت به log اضافه نشده باشد.

---

# 13. مواردی که فعلاً نباید انجام شوند

تا پایان P0/P1 توصیه می‌شود از این کارها خودداری شود:

- افزودن Feature بزرگ جدید
- بازنویسی کامل افزونه
- تغییر هم‌زمان معماری + UI + Schema در یک Release
- حذف جدول یا داده Legacy بدون Migration
- تبدیل چند ماژول بزرگ در یک مرحله
- افزایش دانشگاه‌ها بدون بستن Criticalهای Tenant
- افزایش حجم ارسال انبوه بدون اصلاح Queue
- ایجاد REST v2 بدون تثبیت contract v1

---

# 14. نقاط قوتی که باید حفظ شوند

در Refactorهای آینده این بخش‌ها نباید قربانی بازطراحی شوند:

- اعتبارسنجی `initData`
- HMAC و `hash_equals`
- Fail-closed بودن TenantContext
- Token encryption با AES-256-GCM
- Message Gateway مرکزی
- Form Versioning
- تفکیک Application / Enrollment / Attendance
- Transaction در ایجاد occurrence
- QR بدون PII
- Health Center و Worker heartbeat
- محدودیت حجم Excel
- XML hardening
- CSV protection موجود در ماژول‌های امن
- audit قبل از نمایش برخی PII
- Backward compatibility QRهای قبلی

---

# 15. راهبرد پیشنهادی ادامه کار با ChatGPT

از این سند به‌عنوان Source of Truth استفاده شود.

برای شروع اصلاحات، هر بار فقط یک مرحله اجرا شود:

> «مرحله بعدی — Phase A»

در هر مرحله:

1. ابتدا سورس آخرین نسخه بررسی شود.
2. فقط مشکلات همان Phase اصلاح شوند.
3. Migrationهای لازم ساخته شوند.
4. تست‌های ممکن اجرا شوند.
5. اثر بر Featureهای موجود بررسی شود.
6. ZIP قابل نصب تولید شود.
7. Release note و test report تولید شود.
8. سند Roadmap با وضعیت `Done / Deferred / Remaining` به‌روزرسانی شود.

---

# 16. وضعیت اولیه Roadmap

| Phase | وضعیت |
|---|---|
| Phase A — Tenant & Data Boundaries | 🟨 Implemented + Production Health Verified — Live Cross-Tenant Acceptance Deferred |
| Phase B — Reliability & State | ✅ Code Complete + Production Health Verified؛ Live Edge Acceptance Deferred |
| Phase C — Modular Core & Migration | ✅ Complete — C1 تا C7 Production Verified |
| Phase D — Performance | ✅ Complete — D1 تا D10 Production Verified |
| Phase E — UX | 🟨 E1–E9 Production Health Verified؛ تصمیم E1 درباره اختیاری‌بودن Messaging در RC49 به تصمیم محصول جدید «permission-first / required» supersede شد؛ E10/RC45 در زنجیره حفظ شده؛ Manual UX Acceptance تجمیع‌شده برای پایان پروژه |
| Phase F — UI | ⏸ Frozen by Product Decision — Not Started |
| Phase G — Extensibility | ⬜ Not Started |

---

# 17. تصمیم نهایی معماری

قرآن‌یار در RC11 **قابل ادامه و قابل اصلاح است** و نیاز به بازنویسی از صفر ندارد.

راهبرد صحیح:

> **Refactor مرحله‌ای، حفاظت از داده و سازگاری، اصلاح Criticalها، سپس کاهش coupling و در نهایت توسعه‌پذیرکردن Platform.**

بزرگ‌ترین اشتباه در این نقطه، شروع یک Rewrite کلی یا ادامه افزودن Featureهای بزرگ بدون بستن مرزهای چنددانشگاهی و Reliability است.

این سند مبنای فنی اصلاحات بعدی پروژه است.


---

# 18. گزارش اجرای Phase A — 1.3.1-rc12

**وضعیت:** 🟨 Implemented — Pending Live Acceptance

اصلاحات اجراشده:

- `QYS-P0-01`: تغییر دانشگاه به Workflow انتقال تبدیل شد؛ دانشگاه فعلی تا تأیید مقصد تغییر نمی‌کند.
- تأیید انتقال در صورت وجود رکورد حضور باز متوقف می‌شود تا رکورد ورود/خروج بین دو دانشگاه معلق نماند.
- `QYS-P0-02`: Visibility، Ownership و Applicant Scope در Program از هم جدا شدند.
- Application و Enrollment دارای snapshot دانشگاه هستند؛ داده تاریخی با انتقال عضو بین دانشگاه‌ها جابه‌جا نمی‌شود.
- Eligibility در Submit / Accept / Enroll / Attendance از Policy مشترک دوباره بررسی می‌شود.
- Program Export در پنل دانشگاه فقط داده همان Tenant را صادر می‌کند.
- CSV Formula Injection در خروجی Program خنثی شد.
- Attendance، Members، Targeted Messaging و Excel از نظر Scope مرور شدند؛ مسیرهای موجود آن‌ها همچنان بر permission/tenant scope متکی‌اند.
- Migration داده‌های قدیمی Program به‌صورت fail-closed طراحی شد؛ موارد مشترک حل‌نشده با `legacy_unknown` از دسترسی دانشگاهی خارج می‌مانند.

**Schema پس از Phase A:** `2026.10.05.3`

**معیار خروج Phase A:** تست‌های محلی و قراردادی PASS شده‌اند. بسته‌شدن نهایی Phase A منوط به نصب RC12، اجرای migration روی دیتابیس زنده، سلامت Health Center و تأیید سناریوهای Runtime چنددانشگاهی است.

# 19. گزارش اجرای Phase B / B1 — 1.3.1-rc13

**وضعیت:** ✅ B1 Production Verified — Schema `2026.10.05.4` و Health Center روی Production تأیید شد؛ B2 در RC14 پیاده‌سازی شده است.

اصلاحات اجراشده:

- قرارداد مرکزی `QYS_Delivery_State` برای وضعیت‌های Queue و نتیجه یک تلاش ارسال اضافه شد.
- نتیجه ارسال موفق `sent`، محدودیت موقت `retryable_failed`، شکست قطعی `definitive_failed` و نتیجه شبکه/پاسخ نامطمئن `uncertain` ثبت می‌شود.
- `network_failure` دیگر در Queue به `failed` قطعی تبدیل نمی‌شود.
- `queue.status` به `varchar(24)` ارتقا یافت.
- migration رکوردهای قدیمی `failed` اضافه شد؛ timestamp و `last_error` تاریخی در migration دست‌نخورده می‌مانند.
- شمارنده Aggregate قدیمی `failed` برای backward compatibility حفظ و شمارنده‌های تفکیکی جدید به Readiness و Health Center اضافه شدند.
- گزارش کارزار و Excel بین خطای قطعی، خطای موقت و نتیجه نامشخص تمایز عملیاتی ایجاد می‌کنند.
- Worker پیش از آماده‌شدن Schema جدید، fail-safe متوقف می‌شود تا state جدید در ستون قدیمی truncate نشود.

**Schema پس از B1:** `2026.10.05.4`

**خارج از دامنه B1 و باقی‌مانده برای ادامه Phase B:**

- B3: Welcome / Activation reliability و retry semantics
- B4: Program terminal-state orchestration
- B5: Capacity / Waitlist policy
- B6: Retention policy و cleanup

**معیار ادامه:** B1 فقط مدل state را تثبیت می‌کند؛ هیچ `retryable_failed` در این نسخه به‌طور خودکار دوباره ارسال نمی‌شود و هیچ `uncertain` بدون reconciliation به صف برنمی‌گردد.



# 20. گزارش اجرای Phase B / B2 — 1.3.1-rc14

**وضعیت:** ✅ B2 Production Verified — Schema `2026.10.05.5` و Health Center روی Production تأیید شد

اصلاحات اجراشده:

- Queue Claim دارای Lease صریح ۱۸۰ ثانیه شد.
- ستون‌های `claimed_at_utc`، `lease_expires_at_utc` و `outbound_started_at_utc` به Queue افزوده شدند.
- آخرین مرز امن پیش از HTTP با `mark_outbound_started()` ثبت می‌شود.
- Claim منقضی که هنوز از مرز شبکه عبور نکرده است فقط به `pending` بازمی‌گردد.
- Claim منقضی که outbound آن شروع شده یا رکورد legacy بدون Lease باشد به `uncertain` منتقل می‌شود و خودکار resend نمی‌شود.
- Worker قدیمی پس از Recovery بدون Claim معتبر اجازه عبور به MessageGateway ندارد؛ این مانع Duplicate Send در Resume دیرهنگام Process می‌شود.
- Migration رکوردهای قدیمی `sending` fail-safe است و آنها را برای retry خودکار ایمن فرض نمی‌کند.
- Health Center وضعیت Lease فعال/منقضی/بدون Lease و Recovery اخیر را بدون PII نمایش می‌دهد.
- `attempts` در Claimهای بعدی افزایش می‌یابد؛ B2 هنوز retry خودکار برای `retryable_failed` انجام نمی‌دهد.

**Schema پس از B2:** `2026.10.05.5`

**خارج از دامنه B2 و باقی‌مانده:**

- B3: Welcome / Activation reliability
- B4: Program terminal-state orchestration
- B5: Capacity / Waitlist policy
- B6: Retention policy

**معیار ادامه:** پس از نصب RC14، Schema و Health Center باید سالم باشند و شمارنده‌های Lease در وضعیت عادی صفر/بدون انقضای رهاشده باقی بمانند.


# 21. گزارش اجرای Phase B / B3 — 1.3.1-rc15

**وضعیت:** ✅ B3 Production Health Verified — Schema `2026.10.05.6` و Health Center روی Production تأیید شد

اصلاحات اجراشده:

- `welcome_log` و `activation_log` به مدل canonical تحویل پیام B1 متصل شدند و ستون `status` به `varchar(24)` ارتقا یافت.
- برای هر تلاش onboarding ستون‌های `claimed_at_utc`، `lease_expires_at_utc`، `outbound_started_at_utc` و `next_attempt_at_utc` افزوده شد.
- Crash پیش از outbound marker پس از انقضای Lease به `pending` بازیابی می‌شود؛ Crash پس از outbound یا رکورد legacy بدون Lease به `uncertain` می‌رود.
- `uncertain` و `definitive_failed` هرگز خودکار resend نمی‌شوند.
- `retryable_failed` فقط حداکثر سه تلاش در پنجره ۲۴ساعته با backoff ۶۰ و ۳۰۰ ثانیه دارد.
- نبود توکن برنامک قبل از شبکه attempt را مصرف نمی‌کند و پیام در `pending` باقی می‌ماند.
- قبل از retry، consent، verified بودن Activation و scope دانشگاه عضو مجدداً بررسی می‌شوند؛ تغییر scope باعث `cancelled` شدن ارسال معلق می‌شود.
- migration قدیمی‌ها: `accepted → sent`، `sending → uncertain` و `failed` بر اساس دلیل ذخیره‌شده طبقه‌بندی می‌شود.
- Worker یک recovery bounded برای Welcome/Activation دارد و Health Center شمارنده‌های aggregate و stale sending را بدون PII نمایش می‌دهد.
- پیام مدیریتی فعال‌سازی اکنون بین retryable، uncertain، definitive و pending تفاوت روشن دارد.

**Schema پس از B3:** `2026.10.05.6`

**خارج از دامنه B3 و باقی‌مانده:**

- B4: Program terminal-state orchestration
- B5: Capacity / Waitlist policy
- B6: Retention policy

**معیار ادامه:** پس از نصب RC15، Schema و Health Center باید سالم باشند. در حالت عادی stale sending مربوط به Welcome/Activation باید صفر باشد؛ تست failure واقعی و uncertain عمدی برای تست جامع Production Deferred می‌ماند.

# 22. گزارش اجرای Phase B / B4 — 1.3.1-rc16

**وضعیت:** ✅ B4 Production Health Verified — Schema `2026.10.05.7` و Health Center روی Production تأیید شد

اصلاحات اجراشده:

- transitionهای `completed`، `cancelled` و `archived` اکنون Orchestration واحد و تراکنشی برای child stateها دارند.
- Applicationهای بلاتکلیف در پایان عادی به `closed` و در لغو برنامه به `cancelled` منتقل می‌شوند؛ تصمیم‌های تاریخی accepted/rejected حفظ می‌شوند.
- Enrollmentهای برنامه پایان‌یافته: `participating → completed` و `enrolled → no_show`.
- Enrollmentهای برنامه لغوشده: active → `cancelled`؛ بنابراین لغو از سمت برگزارکننده با `withdrawn` عضو اشتباه نمی‌شود.
- برای Program آرشیوی قدیمی با منشأ نامعلوم، Enrollment فعال به `closed` خنثی منتقل می‌شود.
- Attendance Program متصل بسته، `current_session_id` صفر و occurrenceهای dynamic باز بسته می‌شوند؛ رکوردهای خروج باز حفظ می‌شوند تا خروج واقعی همچنان قابل ثبت باشد.
- Attendance متصل به Program پایانی قابل re-open نیست و ایجاد QR جدید فقط برای Programهای `confirmed/running` مجاز است.
- تغییر دستی Application/Enrollment پس از terminal شدن Program در Service مسدود می‌شود و UI عملیات پذیرش را روی Program پایانی نمایش نمی‌دهد.
- migration idempotent `reconcile_terminal_programs()` برای اصلاح داده‌های terminal موجود اضافه شد.

**Schema پس از B4:** `2026.10.05.7`

**نکته Migration:** RC16 ستون/جدول تازه‌ای اضافه نمی‌کند؛ افزایش Schema Version برای اجرای reconciliation داده‌های موجود است.

**خارج از دامنه B4 و باقی‌مانده:**

- B5: Capacity / Waitlist Policy
- B6: Retention Policy

**معیار ادامه:** پس از نصب RC16، Schema باید `2026.10.05.7` و Health Center سالم باشد. تست زنده transition یک Program آزمایشی و خروج دیرهنگام از occurrence بسته‌شده می‌تواند در مجموعه Live Acceptance بعدی انجام شود.

# 23. گزارش اجرای Phase B / B5 — 1.3.1-rc17

**وضعیت:** 🟨 B5 Installed + Production Health/UI Verified — Functional Edge Acceptance Deferred

اصلاحات اجراشده:

- یک `Admission Policy` مرکزی برای محاسبه ظرفیت رزروشده، ظرفیت باقی‌مانده و Waitlist اضافه شد.
- Application با وضعیت `accepted` از لحظه پذیرش یک صندلی رزرو می‌کند؛ Enrollment فعال همان صندلی را ادامه می‌دهد و دوباره شمرده نمی‌شود.
- پذیرش (`accepted`) با قفل تراکنشی `FOR UPDATE` روی Program و Application انجام می‌شود تا پذیرش هم‌زمان دو دانشگاه نتواند ظرفیت را بیش از حد مصرف کند.
- Waitlist غیرفعال از سمت Service enforce می‌شود و صرفاً محدودیت UI نیست.
- وقتی ظرفیت پر است، پذیرش جدید رد می‌شود؛ اگر Waitlist فعال باشد خطا مسیر `waitlist` را پیشنهاد می‌دهد.
- ارتقای `waitlisted → accepted` فقط در صورت ظرفیت آزاد انجام می‌شود.
- Enrollment یک Application پذیرفته‌شده ظرفیت دوم مصرف نمی‌کند؛ پذیرش قبلی همان Reservation است.
- خروج/وضعیت پایانی Enrollment ظرفیت را آزاد می‌کند.
- در ظرفیت پر و Waitlist غیرفعال، Submit جدید نیز بسته می‌شود؛ در Waitlist فعال Submit اولیه همچنان مجاز است.
- Legacy overbooking به‌صورت محافظه‌کارانه حفظ می‌شود و خودکار تنزل داده نمی‌شود؛ فقط پذیرش جدید تا آزادشدن ظرفیت متوقف است.
- UI مدیریت Program اکنون Aggregate غیرشخصی ظرفیت کل، مصرف‌شده، باقی‌مانده و Waitlist را نمایش می‌دهد.

**Schema پس از B5:** `2026.10.05.7` — بدون تغییر فیزیکی Schema و بدون Migration جدید.

**باقی‌مانده Phase B:**

- B6: Retention Policy و cleanup دوره‌ای داده حساس.

**معیار ادامه:** پس از نصب RC17، یک Program آزمایشی با ظرفیت محدود باید سناریوهای پذیرش تا سقف، جلوگیری از overbooking، Waitlist فعال/غیرفعال، آزادشدن ظرفیت و ارتقای Waitlist را پاس کند.



# 24. گزارش اجرای Phase B / B6 — 1.3.1-rc18

**وضعیت:** ✅ B6 Production Verified — اولین Cleanup روی Production با نتیجه `ok` تأیید شد

اصلاحات اجراشده:

- سرویس مرکزی `QYS_Retention_Service` و Cron مستقل روزانه `qys_retention_cleanup` اضافه شد.
- Retention Matrix پیش‌فرض: payload صف ۹۰ روز؛ ناشناس‌سازی Excel follow-up و Birthday archive در ۱۸۰ روز؛ Birthday log در ۵۵۰ روز؛ حذف Campaign history و Audit در ۷۳۰ روز.
- Queue فقط برای وضعیت‌های نهایی `sent / definitive_failed / cancelled` کمینه می‌شود؛ `pending / sending / retryable_failed / uncertain / legacy failed` از حذف خودکار محافظت می‌شوند.
- Campaign قدیمی فقط به‌صورت کامل و تراکنشی حذف می‌شود، آن هم وقتی هیچ Queue غیرنهایی یا جدید نداشته باشد؛ متادیتای Targeted، Import report، Excel follow-up و Queue همان Campaign نیز با آن پاک می‌شوند.
- Excel follow-up پس از ۱۸۰ روز مستقیم‌ترین شناسه‌ها (`member_id`, name, national_id, mobile) را ناشناس می‌کند و دلیل/ردیف برای آمار باقی می‌ماند.
- Birthday archive فقط در وضعیت‌های نهایی accepted/failed پس از ۱۸۰ روز PII/message را ناشناس و پس از ۷۳۰ روز رکورد را حذف می‌کند؛ uncertain/sending محافظت می‌شوند.
- Birthday logهای نهایی پس از ۵۵۰ روز و Auditهای birthday-access/tenant/program پس از ۷۳۰ روز به‌صورت batch محدود پاک می‌شوند.
- هر اجرا bounded است و Lock مستقل دارد؛ telemetry فقط شمارنده، زمان و کد نتیجه را نگه می‌دارد و raw error/PII ذخیره نمی‌کند.
- Health Center زمان‌بندی Retention، آخرین اجرا، نتیجه، تعداد تغییرات و Matrix نگهداری را نمایش می‌دهد و stale/failed/unscheduled را هشدار می‌دهد.
- Incident log موجود از قبل به ۵۰ رکورد aggregate بدون PII محدود بود و در B6 نیاز به پاک‌سازی جداگانه ندارد.

**Schema پس از B6:** `2026.10.05.7` — بدون تغییر فیزیکی Schema و بدون Migration دیتابیس.

**معیار ادامه:** پس از نصب RC18، Health Center باید Retention را زمان‌بندی‌شده نشان دهد. اولین اجرای روزانه باید نتیجه `ok` ثبت کند؛ تست با داده‌های مصنوعی قدیمی برای anonymize/purge و حفاظت از pending/uncertain در Live Acceptance بعدی انجام می‌شود.

**پس از B6:** Phase B از نظر کد و Production Health کامل است. Automated/Artifact Acceptance برای A+B نیز PASS شده و Live Edge Acceptance به‌عنوان بدهی پذیرش در Roadmap باقی می‌ماند.


# 25. گزارش اجرای Phase C / C1 — 1.3.1-rc19

**وضعیت:** ✅ C1 Production Verified — Migration Ledger 8/8 و Health Center روی Production تأیید شد

اصلاحات اجراشده:

- `QYS_Migration_Ledger` به‌عنوان Ledger پایدار و بدون PII اضافه شد؛ ذخیره‌سازی در option غیر-autoload انجام می‌شود تا ایجاد خود Ledger به Migration جدول جدید وابسته نباشد.
- RC12 تا RC18 با شناسه‌های ترتیبی و نسخه/Schema واقعی خود به‌عنوان baseline ثبت می‌شوند و callbackهای تاریخی آنها دوباره اجرا نمی‌شود.
- Migration جدید دارای stateهای `pending / running / completed / failed`، attempts، timestamps، checkpoint و error code امن است.
- Migration تکمیل‌شده در اجرای بعدی skip می‌شود؛ Migration failed قابل retry است و checkpoint حفظ می‌شود.
- `register()` فقط شناسه‌های جدیدتر از C1 را می‌پذیرد تا Migration retroactive به تاریخچه تزریق نشود.
- Lock مستقل Ledger از اجرای هم‌زمان جلوگیری می‌کند؛ lock قدیمی پس از ۵ دقیقه قابل بازیابی است.
- Installer روی RC18 موجود baseline را import می‌کند و هیچ‌یک از `ensure_schema`، backfill یا reconciliationهای تاریخی RC12–RC18 را replay نمی‌کند.
- Fresh install همچنان bootstrap اثبات‌شده فعلی را یک‌بار اجرا می‌کند و سپس همان وضعیت را baseline می‌کند.
- Upgradeهای آینده، وقتی Ledger وجود دارد، فقط migrationهای pending را اجرا می‌کنند و سپس `qys_schema_version` به نسخه هدف افزونه ارتقا می‌یابد.
- `SCHEMA_VERSION` در C1 همچنان `2026.10.05.7` است؛ C1 هیچ جدول/ستون دامنه‌ای اضافه یا حذف نمی‌کند.
- Health Center وضعیت initialized/ready/pending/running/failed و آخرین migrationها را نمایش می‌دهد و failed/stale migration را هشدار بحرانی می‌کند.
- Compatibility window باعث می‌شود در فاصله نصب RC19 تا اولین admin request، Workerهای public صرفاً به‌دلیل نبود baseline جدید متوقف نشوند؛ پس از ایجاد Ledger، حذف/خرابی آن fail-closed می‌شود.

**خارج از دامنه C1:**

- C2: حذف وابستگی Upgrade به صرف `admin_init`.
- C3: Module lifecycle contract.
- C4+: استخراج سرویس‌های پایه و شکستن cycleهای معماری.

**معیار ادامه:** پس از نصب RC19، Health Center باید `Migration ledger` را Ready با ۸/۸ migration completed نشان دهد؛ ۷ مورد baseline و `20261005_008_rc19_migration_ledger` با یک attempt. سپس C2 آغاز می‌شود.

# 26. گزارش اجرای Phase C / C2 — 1.3.1-rc20

**وضعیت:** ✅ C2 Production Verified — Health Center: Schema 2026.10.05.7 / Ledger 9/9 / Upgrade gate ready

اصلاحات اجراشده:

- `QYS_Upgrade_Coordinator` اضافه شد؛ Migrationهای post-baseline دیگر برای اجرا به بازدید مدیر از `wp-admin` وابسته نیستند و روی `init` با اولویت بسیار زود اجرا می‌شوند.
- Fresh install همچنان برای تعیین مالک و bootstrap اولیه به مدیر دارای `manage_options` نیاز دارد؛ C2 فقط مسیر Upgrade بعد از baseline را خودکار می‌کند.
- نصب pre-ledger سازگار (RC18 با Schema `2026.10.05.7`) می‌تواند بدون admin visit به‌صورت امن baseline شود؛ Fresh/Pending install این مسیر را نمی‌گیرد.
- runner خودکار در هر request حداکثر یک Migration را آغاز می‌کند و budget شروع مرحله بعدی دارد؛ Ledger lock C1 اجرای هم‌زمان را متوقف می‌کند.
- C2 به‌عنوان Migration مستقل `20261006_009_rc20_upgrade_coordinator` ثبت شد؛ C1 (`...008...`) در core definitions حفظ می‌شود.
- RESTهای `qys/v1`، برنامک عضو، پنل دانشگاه، wp-admin/admin-post/AJAXهای قرآن‌یار و workerهای Queue/Welcome/Birthday/Targeted تا آماده‌شدن Upgrade به‌صورت fail-closed متوقف می‌شوند.
- Health Center در حالت Upgrade ناقص دیگر domain tableهای در حال migration را probe نمی‌کند؛ فقط telemetry امن Ledger/Upgrade/Cron/Retention را نمایش می‌دهد.
- Health Center وضعیت Upgrade gate، آخرین تلاش runner، نتیجه، تعداد Migration اجراشده و remaining را بدون PII گزارش می‌کند.
- qys-health برای diagnostics باز می‌ماند؛ سایر صفحات مدیریتی قرآن‌یار هنگام gate بسته با 503 متوقف می‌شوند.
- Schema دامنه در C2 تغییر نکرده و همچنان `2026.10.05.7` است.

**ایمنی و سازگاری:**

- Migration failed یا ناقص، QuranYar surfaces را fail-closed نگه می‌دارد اما سایر WordPress REST/site را از دسترس خارج نمی‌کند.
- lock contention به‌عنوان failure ثبت نمی‌شود؛ request بعدی می‌تواند ادامه دهد.
- checkpoint/retry C1 حفظ شده و Migration failed در تلاش بعدی checkpoint قبلی را دارد.
- rollback فایل به RC19 قبل/بعد از C2 نیازمند rollback دیتابیس نیست؛ entry تکمیل‌شده C2 در Ledger برای RC19 صرفاً یک ردیف تکمیل‌شده اضافی است.

**معیار ادامه:** پس از نصب RC20، Health Center باید Migration Ledger را `9/9 completed`، Upgrade gate را «آماده» و `pending/running/failed=0` نشان دهد. سپس Phase C / C3 (Module lifecycle contract) آغاز می‌شود.

# 27. گزارش اجرای Phase C / C3 — 1.3.1-rc21

**وضعیت:** ✅ C3 Production Verified — Health Center: Ledger 10/10 / Module Lifecycle 17/17 / all Health ok

اصلاحات اجراشده:

- `QYS_Module_Contract` و Adapter سازگار `QYS_Callback_Module` اضافه شدند تا همه قابلیت‌های runtime قرارداد یکسان برای dependency، criticality، Migration، Boot و Health داشته باشند.
- `QYS_Module_Manager` نقطه واحد Boot شده و ۱۷ ماژول runtime را dependency-aware اجرا می‌کند؛ direct initهای پراکنده از entrypoint حذف شده‌اند.
- ماژول غیرحیاتی اگر در Boot شکست بخورد، `failed` می‌شود و سایر ماژول‌های مستقل همچنان Boot می‌شوند؛ ماژول وابسته به آن `blocked` می‌شود. شکست ماژول حیاتی همچنان fail-closed است.
- خطای خام Exception وارد telemetry نمی‌شود و فقط error code مبتنی بر نوع خطا نگهداری/نمایش داده می‌شود.
- Migration متعلق به lifecycle با ID `20261006_010_rc21_module_lifecycle` پیش از `init` در Ledger ثبت می‌شود تا C2 automatic runner بتواند آن را بدون admin visit اجرا کند.
- `MigrationLedger.current_id` اکنون آخرین تعریف واقعی را پویا گزارش می‌کند و برای Module-owned migrationهای آینده hard-code نمی‌ماند.
- `FeatureRegistry` در C3 حفظ شده و فقط یک bridge ایمنی گرفته است: Feature ماژول failed/blocked از Navigation حذف و دسترسی مستقیم آن unavailable می‌شود. تبدیل کامل Registry به C7 موکول است.
- Health Center شاخص‌ها و جدول `Module Lifecycle` را اضافه کرده و `module_critical_failed` / `module_degraded` را در Incident model گزارش می‌کند.
- Schema دامنه همچنان `2026.10.05.7` است؛ Migration 010 هیچ جدول/ستون یا داده دامنه‌ای را تغییر نمی‌دهد.

تست‌های C3:

- PHP 39/39، JavaScript 10/10 و CSS 10/10 PASS.
- Source contracts: 17/17 PASS.
- Runtime lifecycle: 14 assertions PASS.
- Critical dependency fail-closed: PASS.
- Full plugin boot smoke: 17/17 modules PASS.
- C1/C2/C3 automatic migration regression: 13 assertions PASS؛ Ledger از حالت RC20-equivalent `9 completed + 1 pending` به `10/10 complete` می‌رسد.
- Hook parity با RC20: 53 action، 4 filter، 2 shortcode و 1 activation hook دقیقاً حفظ شده‌اند.

**خارج از دامنه:** استخراج سرویس‌های پایه QYS_Messenger (C4)، شکستن cycleها (C5)، Repository ownership (C6) و تبدیل نهایی FeatureRegistry به Module Registry (C7).

**Production Verification:** در 2026-10-06 Health Center روی Production، `Migration Ledger=10/10`، `Module lifecycle=17/17 booted`، همه Healthها `ok`، Upgrade gate آماده و صف بدون وضعیت غیرعادی را نشان داد. C3 بسته شد و C4 آغاز شد.

# 28. گزارش اجرای Phase C / C4 — 1.3.1-rc22

**وضعیت:** ✅ C4 Production Verified — Health Center: Ledger 11/11 / Module Lifecycle 17/17 / Core Health ok

اصلاحات اجراشده:

- `QYS_Config` به‌عنوان منبع canonical نسخه، Schema، endpoint، دانشگاه پیش‌فرض و runtime plugin path اضافه شد؛ ثابت‌های قدیمی `QYS_Messenger` صرفاً compatibility alias باقی ماندند.
- `QYS_Token_Store` منطق source/validation/encryption/decryption/persistence توکن‌ها را از Trait پیام‌رسانی خارج کرد؛ APIهای قدیمی delegate می‌کنند.
- `QYS_Table_Names`، `QYS_Text_Normalizer` و `QYS_Calendar_Service` helperهای مشترک جدول، متن/شناسه و تقویم را از God Class جدا کردند.
- `QYS_Bootstrap_Facade` activation و runtime bootstrap را به یک entry boundary تبدیل کرد؛ فایل اصلی دیگر Installer/ModuleManager را مستقیم wire نمی‌کند.
- call siteهای shared-service در Repository/Domain به سرویس‌های جدید منتقل شدند تا coupling به `QYS_Messenger` کم شود، بدون تغییر business workflow.
- Module `core` اکنون Health سرویس‌های پایه را از `QYS_Bootstrap_Facade::health()` می‌گیرد.
- Migration زیرساختی `20261006_011_rc22_core_services` به Ledger اضافه شد؛ پس از RC21 انتظار `11/11 completed` می‌رود.
- Schema دامنه همچنان `2026.10.05.7` است و هیچ جدول/ستون یا داده دامنه rewrite نمی‌شود.

تست‌های C4:

- PHP `45/45`، JavaScript `10/10`، CSS `10/10` PASS.
- Source/static contracts: `54/54 PASS`.
- Legacy helper behavior parity RC21→RC22: PASS.
- Module lifecycle runtime: `13 assertions PASS` و `17/17 modules booted`.
- Migration regression RC21→RC22: `17 assertions PASS`؛ یک bounded migration اجرا و Ledger به `11/11` می‌رسد.
- Full plugin include/init boot smoke: `15 assertions PASS`.
- Runtime hook parity RC21→RC22: actions `53=53`، filters `4=4`، shortcodes `2=2`، activation hooks `1=1`.

**خارج از دامنه C4:** C5 cycle-breaking، C6 Repository ownership، C7 Module Registry نهایی و Phase D Performance.

**Production Verification:** در 2026-10-06 Health Center روی Production، `Migration Ledger=11/11`، `Module lifecycle=17/17 booted`، تمام Healthها `ok`، Upgrade gate آماده، Worker/Cron/Retention سالم و صف بدون وضعیت غیرعادی را نشان داد. C4 بسته شد و C5 آغاز شد.

# 29. گزارش اجرای Phase C / C5 — 1.3.1-rc23

**وضعیت:** ✅ C5 Production Verified — Health Center: Ledger 12/12 / Module Lifecycle 17/17 / Attendance dependency شامل Programs

اصلاحات اجراشده:

- cycle `Programs ↔ Attendance` به dependency یک‌طرفه تبدیل شد. Programs دیگر هیچ reference مستقیمی به `QYS_Attendance` یا `QYS_Attendance_Programs` ندارد و فقط `QYS_Program_Attendance_Port / Boundary` را مصرف می‌کند. implementation در `QYS_Attendance_Program_Adapter` متعلق به Attendance قرار دارد.
- عملیات cross-feature شامل terminalization، ایجاد/خواندن Attendance Program، summary حضور عضو و backfill legacy از همین boundary عبور می‌کند.
- در Module Lifecycle، Attendance اکنون صریحاً به Programs وابسته است (`attendance -> programs`). شکست Programs، Attendance را blocked می‌کند؛ شکست Attendance، Programs را از Boot خارج نمی‌کند.
- cycle `Permission ↔ Targeted` شکسته شد. `QYS_Permission_Service` دیگر `QYS_Targeted` را نمی‌شناسد؛ metadata و visibility کارزار از boundary ذخیره‌سازی `QYS_Campaign_Repository` خوانده می‌شود.
- `QYS_Targeted` table/schema contract خود را به repository واگذار می‌کند؛ جهت dependency اکنون `Targeted -> Permission -> Repository` است.
- cycle `Security ↔ Multitenant` شکسته شد. owner/page optionها و entry slugهای canonical به `QYS_Config` منتقل شدند و `QYS_Security_Service` بدون reference به `QYS_Multitenant`، مدیرکل و app surfaceهای محافظت‌شده را تشخیص می‌دهد.
- ثابت‌های قبلی Multitenant برای backward compatibility به Config alias شده‌اند.
- Migration زیرساختی `20261006_012_rc23_dependency_cycle_break` اضافه شد؛ Schema دامنه همچنان `2026.10.05.7` است و هیچ جدول/ستون یا داده دامنه‌ای تغییر نمی‌کند.

تست‌های C5:

- PHP `47/47`، JavaScript `10/10` و CSS `10/10` PASS.
- Source/static contracts: `28/28 PASS`.
- Program/Attendance boundary runtime: `11 assertions PASS`.
- Attendance adapter runtime/backfill: `10 assertions PASS`.
- Permission/Targeted authorization runtime: `7 assertions PASS`.
- Security/global-owner runtime: `6 assertions PASS`.
- Module lifecycle runtime: `15 assertions PASS`؛ `17/17 modules booted`.
- Migration regression RC22→RC23: `19 assertions PASS`؛ Ledger از `11/12` به `12/12 completed` می‌رسد.
- Full plugin boot smoke: `15 assertions PASS`؛ `17/17 modules booted`.
- Hook parity RC22→RC23: 53 action، 4 filter، 2 shortcode و 1 activation hook دقیقاً حفظ شده‌اند.
- C4 shared-helper behavior parity: PASS.

**خارج از دامنه C5:** Repository ownership کامل (C6)، FeatureRegistry → Module Registry نهایی و cycle آن با Multitenant (C7)، Phase D Performance و Feature محصولی جدید.

**Production Verification:** در 2026-10-06 Health Center روی Production، `Migration Ledger=12/12`، `Module lifecycle=17/17 booted`، ردیف Attendance با dependency شامل `programs`، تمام Healthها `ok`، Upgrade gate آماده، Worker/Cron/Retention سالم و Queue بدون وضعیت غیرعادی را نشان داد. C5 بسته شد و C6 آغاز شد.

# 30. گزارش اجرای Phase C / C6 — 1.3.1-rc24

**وضعیت:** ✅ C6 Production Verified — Health Center: Ledger 13/13 / Module Lifecycle 17/17 / Programs migration=1

هدف C6 بستن QYS-P2-06 (Persistence Boundary ناقص) در دامنه‌هایی است که Repository canonical از قبل دارند. در این مرحله business/service layer دیگر SQL runtime جداول repository-backed را مستقیماً اجرا نمی‌کند؛ schema DDL/diagnostics همچنان می‌تواند در Feature/Installer مالک schema باقی بماند.

اصلاحات اجراشده:

- `QYS_Program_Service` و `QYS_Programs_Module` از `$wpdb` و SQL مستقیم پاک شدند. Program/Application/Enrollment/Form/Audit/Attendance-link persistence اکنون از `QYS_Program_Repository` عبور می‌کند.
- `QYS_Program_Repository` transaction boundary، Admission counts، Program creation/status locking، Application/Enrollment locking و transition persistence، Form CRUD/publish، Member Record read model، tenant backfill و terminal reconciliation reads را مالک شده است.
- `QYS_Members_Module` دیگر birthday log را مستقیم update نمی‌کند؛ member-scoped cancellation به `QYS_Member_Repository::cancel_pending_birthday_for_member()` منتقل شده است.
- `QYS_Targeted` runtime SQL مربوط به metadata insert، launch/cancel timestamp و due schedule selection را به `QYS_Campaign_Repository` واگذار کرده است. DDL جدول Targeted در `ensure_schema()` به‌عنوان schema ownership feature باقی مانده است.
- `QYS_Multitenant` runtime transaction و tenant audit را به `QYS_University_Repository` واگذار کرده است. SQL مستقیم باقی‌مانده در Multitenant فقط schema creation/introspection است.
- safety read مربوط به حضور باز عضو از Multitenant خارج و به API مالک Attendance (`QYS_Attendance::open_record_count_for_member`) منتقل شده است.
- C5 dependency directionها حفظ شده‌اند؛ هیچ reference معکوس Programs→Attendance، Permission→Targeted یا Security→Multitenant دوباره ایجاد نشده است.
- Migration زیرساختی `20261006_013_rc24_repository_ownership` متعلق به module `programs` اضافه شده و boundaryهای Repository C6 را پیش از complete شدن بررسی می‌کند.
- Schema دامنه همچنان `2026.10.05.7` است؛ Migration 013 هیچ table/column/domain row را تغییر نمی‌دهد.

تست‌های C6:

- PHP `47/47`، JavaScript `10/10`، CSS `10/10` PASS.
- C6 source/static contracts: `35/35 PASS`.
- Repository ownership runtime: `15 assertions PASS`.
- Module lifecycle runtime: `17 assertions PASS`؛ `17/17 modules booted` و Programs migration count=`1`.
- Migration regression RC23→RC24: `18 assertions PASS`؛ Ledger از `12/13` به `13/13 completed` می‌رسد.
- C5 regressions: Program/Attendance `11`، Attendance adapter `10`، Permission/Targeted `7`، Security/owner `6` assertions همگی PASS.
- Full plugin boot smoke: `15 assertions PASS`؛ `17/17 modules booted`.
- Hook parity RC23→RC24: actions `53=53`، filters `4=4`، shortcodes `2=2`، activation hooks `1=1`.
- Shared-helper behavior parity RC23→RC24: PASS.

**مرز C6:** God Classهای feature-local مانند Attendance/Birthday/Excel که Repository مستقل موجود را bypass نمی‌کنند در این مرحله به Repository تازه شکسته نمی‌شوند؛ این موضوع با QYS-P2-04 (کلاس‌های بزرگ) جداست. C6 روی «مالکیت persistence در مرزهای Repository موجود» تمرکز دارد تا refactor به تغییر هم‌زمان گسترده تبدیل نشود.

**خارج از دامنه C6:** C7 تبدیل نهایی `FeatureRegistry → Module Registry` و جمع‌کردن cycle باقی‌مانده FeatureRegistry ↔ Multitenant، Phase D Performance، UX/UI و Feature محصولی جدید.

**Production Verification:** در 2026-10-06 Health Center روی Production، `Migration Ledger=13/13`، `Module lifecycle=17/17 booted`، Programs migration count=`1`، Attendance dependency شامل `programs`، همه Healthها `ok`، Upgrade gate آماده، Worker/Cron/Retention سالم و Queue بدون وضعیت غیرعادی را نشان داد. C6 بسته شد و C7 آغاز شد.



# 31. گزارش اجرای Phase C / C7 — 1.3.1-rc25

**وضعیت:** ✅ C7 Production Verified — Phase C Complete

هدف C7 تبدیل FeatureRegistry مستقل به Module Registry canonical و بستن آخرین cycle Registry ↔ Multitenant در Phase C است.

اصلاحات اجراشده:

- `QYS_Module_Registry` منبع canonical module objectها و feature metadata شد؛ `QYS_Module_Manager` دیگر registry موازی برای module objectها ندارد.
- `QYS_Module_Contract` متد `features()` دارد و ۱۰ Feature فعلی همراه lifecycle module مالک ثبت می‌شوند.
- مالکیت: Universities=home/more، Members=members، Messaging=messages، Programs=programs، Attendance=attendance، TargetedMessaging=targeted، SpreadsheetMessaging=excel، Birthday=birthday، Welcome=welcome.
- ماژول `feature-navigation` حذف و با ماژول حیاتی `module-registry` جایگزین شد؛ total modules همچنان 17 است.
- `QYS_Portal_Feature_Service` permission/availability/dispatch را مالک است؛ `QYS_Module_Registry` pure metadata بوده و به Multitenant، PermissionService یا ModuleManager dependency معکوس ندارد.
- runtime داخلی هیچ referenceای به `QYS_Feature_Registry` ندارد؛ کلاس تاریخی فقط compatibility facade برای extensionهای قبلی است.
- permission vocabulary لازم برای validation از `QYS_Config::PERMISSIONS` خوانده می‌شود؛ `QYS_Multitenant::PERMISSIONS` compatibility alias باقی مانده است. این کار Permission Registry کامل Phase G نیست.
- Health Center readiness جدید `Module registry` با module/feature count و ستون Feature در جدول lifecycle اضافه کرده است.
- Migration زیرساختی `20261006_014_rc25_module_registry` متعلق به `module-registry` اضافه شد؛ هیچ table/column/domain row تغییر نمی‌کند و Schema `2026.10.05.7` باقی می‌ماند.

تست‌های C7:

- PHP `49/49`، JavaScript `10/10`، CSS `10/10` PASS.
- C7 source/static contracts: `40/40 PASS`.
- Module Registry runtime: `32 assertions PASS`؛ `17/17 modules booted` و `10 features`.
- Migration regression RC24→RC25: `18 assertions PASS`؛ Ledger از `13/14` به `14/14 completed` می‌رسد.
- Full plugin boot smoke: `19 assertions PASS`.
- Hook parity RC24→RC25: actions `53=53`، filters `4=4`، shortcodes `2=2`، activation hooks `1=1`.
- C6 source contracts `35/35` و Repository runtime `15 assertions` PASS.
- C5 regressions `11 + 10 + 7 + 6` assertions PASS.

**معیار پایان Phase C:** پس از نصب RC25، Health Center باید `Migration Ledger=14/14`، `Module Lifecycle=17/17 booted`، `Module registry=17 modules / 10 features`، ماژول `module-registry` با Migration count=`1`، `failed/blocked/degraded=0` و Worker/Cron/Retention سالم را نشان دهد.

پس از Production Verification C7، Phase C بسته و **Phase D / D1 — حذف Runtime Schema Introspection** آغاز می‌شود.



# 32. تأیید Production Phase C / C7 و تکمیل Phase C — RC25

**وضعیت:** ✅ Production Verified — Phase C Complete

در Health Center تولیدی پس از نصب `1.3.1-rc25` موارد زیر تأیید شدند:

- Schema = `2026.10.05.7`
- Migration Ledger = `14/14 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- `module-registry` migration count = `1`
- `universities` feature count = `2`
- Programs migration count = `1`
- Attendance dependency شامل `programs`
- Health ماژول‌ها `ok` و هیچ failed/blocked/degraded مشاهده نشد
- Worker/Cron/Retention و Queue سالم

با این تأیید، هر هفت کار Phase C بسته شدند و Performance Hardening وارد اجرا شد.


# 33. گزارش اجرای Phase D / D1 — 1.3.1-rc26

**وضعیت:** 🟨 D1 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D1 بستن `QYS-P1-08 — Schema Introspection در Runtime` است: مسیر عادی نباید برای readiness پرتکرار `SHOW TABLES / SHOW COLUMNS / SHOW INDEX` اجرا کند؛ introspection فقط در Installer/Migration و Health Diagnostics مجاز است.

اصلاحات اجراشده:

- `QYS_Schema_Runtime` اضافه شد و readiness runtime را بر `qys_install_state + qys_schema_version + Migration Ledger` بنا می‌کند؛ نتیجه request-level cache می‌شود.
- `QYS_Table_Names::runtime_exists()` برای مسیر سریع اضافه شد؛ physical introspection در `diagnostic_exists / diagnostic_columns / diagnostic_indexes` متمرکز و request-cache شده است.
- همه `SHOW TABLES/COLUMNS/INDEX` سورس در `QYS_Table_Names` متمرکز شده‌اند و runtime داخلی دیگر `QYS_Table_Names::exists()` تاریخی را صدا نمی‌زند.
- Member/Campaign/University/Program Repositoryها، Multitenant، Attendance، RecurringAttendance، Campus Birthday/Welcome، Welcome/Activation log readiness و helperهای Retention/Spreadsheet/Support به fast runtime readiness منتقل شدند.
- physical diagnostics برای Fresh Install، `dbDelta` verification، migration/backfill و qys-health حفظ شده است.
- Health Center deep diagnostics فقط در render صریح صفحه Health اجرا می‌شود؛ worker heartbeat/snapshot این probeها را اجرا نمی‌کند.
- Health Center دو readiness جدید گزارش می‌کند: `Runtime schema gate` و `Schema diagnostics`.
- برای حفظ dependency direction C5، Diagnostic Attendance از همان Program/Attendance boundary و Adapter عبور می‌کند و reference معکوس Programs→Attendance ایجاد نشده است.
- Migration زیرساختی `20261006_015_rc26_runtime_schema_guard` متعلق به module `core` اضافه شد؛ هیچ table/column/domain row تغییر نمی‌کند و Schema `2026.10.05.7` باقی می‌ماند.

تست‌های D1:

- PHP `50/50`، JavaScript `10/10`، CSS `10/10` PASS.
- D1 source/static contracts: `27/27 PASS`.
- Runtime schema guard: `10 assertions PASS`؛ runtime `SHOW* queries = 0` و explicit diagnostic cache query=`1`.
- Module Registry/Lifecycle runtime: `34 assertions PASS`؛ `17/17 modules`, `10 features`, core migration count=`2`.
- Migration regression RC25→RC26: `17 assertions PASS`؛ Ledger از `14/15` به `15/15 completed` می‌رسد.
- Full plugin boot smoke: `20 assertions PASS`؛ hook inventory `53 actions / 4 filters / 2 shortcodes / 1 activation`.
- C6 source contracts `35/35` و Repository runtime `15 assertions` PASS.
- C5 regressions `12 + 10 + 7 + 6` assertions PASS.
- Hook parity RC25→RC26 کامل PASS.

**معیار Production D1:** پس از نصب RC26، Health Center باید `Migration Ledger=15/15`، `Module Lifecycle=17/17`, `Module Registry=17/10`, core migration count=`2`, `Runtime schema gate: mode=version_ledger / probes=0`, `Schema diagnostics: Physical=12/12`, و failed/blocked/degraded=`0` را نشان دهد.

پس از Production Verification D1، مرحله بعدی **Phase D / D2 — اصلاح N+1های Programs** است.

# 34. تأیید Production Phase D / D1 — RC26

**وضعیت:** ✅ D1 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc26` موارد زیر تأیید شدند:

- Schema = `2026.10.05.7`
- Migration Ledger = `15/15 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Core migration count = `2`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- همه Module Healthها `ok` و failed/blocked/degraded=`0`
- Pending/Sending صفر و Worker/Cron/Retention سالم

با این تأیید، `QYS-P1-08 — Schema Introspection در Runtime` روی Production بسته شد و D2 آغاز شد.


# 35. گزارش اجرای Phase D / D2 — 1.3.1-rc27

**وضعیت:** 🟨 D2 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D2 بستن `QYS-P1-09 — N+1 سنگین در Programs` و `QYS-P2-07 — Export Program دارای N+1 روی Answers` است.

اصلاحات اجراشده:

- `current_form_fields_for_programs()` فرم فعال چند Program را در یک query hydrate می‌کند؛ `rest_list()` دیگر برای هر Program `current_form + form_fields` جداگانه ندارد.
- `counts_for_programs()` Application/Enrollment counts کل Program index را با دو grouped query ثابت می‌سازد؛ countهای per-row حذف شدند.
- `answers_for_applications()` پاسخ‌های صفحه جزئیات و CSV export را به‌صورت batch می‌خواند؛ N query به یک query تبدیل شد.
- `answers_for_application()` برای backward compatibility به batch path یک ردیف delegate می‌کند.
- `admission_counts()` از چهار roundtrip مستقل به یک aggregate query تبدیل شد.
- Health Center readiness جدید `Programs query plan` با `forms: batch | counts: grouped | answers: batch` اضافه شد؛ این کارت خودش query ندارد.
- Migration زیرساختی `20261006_016_rc27_program_batch_queries` متعلق به module `programs` اضافه شد؛ Schema `2026.10.05.7` بدون تغییر باقی می‌ماند.

Query-bound تست‌شده:

- form hydration چند Program: `1 query`
- counts برای `150 Programs`: `2 queries`
- answers برای `1000 Applications`: `1 query`
- admission snapshot: `1 query`

تست‌های D2:

- PHP `50/50`، JavaScript `10/10`، CSS `10/10` PASS.
- D2 source/static contracts: `20/20 PASS`.
- D2 batch runtime: `24 assertions PASS`.
- Migration regression RC26→RC27: `17 assertions PASS`؛ Ledger `15/16 → 16/16`.
- D1 static `27/27` و runtime schema guard `10 assertions` PASS؛ runtime SHOW queries=`0`.
- C7 Registry/Lifecycle runtime `35 assertions` PASS؛ `17/17 modules / 10 features`.
- C6 source `35/35` و Repository runtime `15 assertions` PASS.
- C5 regressions `12 + 10 + 7 + 6` assertions PASS.
- Full boot smoke `21 assertions` PASS؛ Hook parity RC26→RC27 برابر `53/4/2/1` کامل حفظ شد.

**معیار Production D2:** Ledger=`16/16`، Lifecycle=`17/17`، Registry=`17/10`، Programs migration count=`2`، Programs query plan=`forms: batch | counts: grouped | answers: batch`، D1 probes=`0` و Physical diagnostics=`12/12`، failed/blocked/degraded=`0`.

پس از Production Verification D2، مرحله بعدی **Phase D / D3 — Home Summary Endpoint** است.



# 36. تأیید Production Phase D / D2 — RC27

**وضعیت:** ✅ D2 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc27` موارد زیر تأیید شدند:

- Schema = `2026.10.05.7`
- Migration Ledger = `16/16 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `2`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- همه Module Healthها `ok` و failed/blocked/degraded=`0`
- Pending/Sending صفر و Worker/Cron/Retention سالم

با این تأیید، D2 روی Production بسته شد و D3 آغاز شد.

# 37. گزارش اجرای Phase D / D3 — 1.3.1-rc28

**وضعیت:** 🟨 D3 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D3 این است که Home برای نمایش «آخرین برنامه‌ها» مجبور به دریافت payload کامل `programs/list` و فرم‌های ثبت‌نام نباشد.

اصلاحات اجراشده:

- endpoint جدید `POST /qys/v1/home-summary` اضافه شد.
- `QYS_Home_Summary_Service` read model فشرده Home را ارائه می‌کند.
- `QYS_Program_Repository::home_summaries_for_member()` با یک query محدود فقط ستون‌های لازم سه کارت Home را می‌خواند.
- Form Version، Field، Answer، Counts و Attendance detail در Home Summary خوانده نمی‌شوند.
- `qys-programs-public.js` برای Home از `homeSummaryEndpoint` استفاده می‌کند و دیگر `programs/list` را برای Home صدا نمی‌زند.
- endpoint کامل `programs/list` برای نمای کامل Programs بدون تغییر باقی مانده است.
- Health Center کارت `Home summary` با قرارداد `endpoint: lightweight | programs: compact | forms: deferred` دارد.
- Migration زیرساختی `20261006_017_rc28_home_summary` متعلق به module `members` اضافه شد؛ Schema بدون تغییر است.

Query-bound تست‌شده:

- Home Program summary: `1 query`
- حداکثر Home Programها: `3`
- Form/Field/Answer query در Home: `0`

تست‌های D3:

- PHP `51/51`، JavaScript `10/10`، CSS `10/10` PASS.
- D3 source/static contracts: `23/23 PASS`.
- D3 Home Summary runtime: `16 assertions PASS`.
- Migration regression RC27→RC28: `17 assertions PASS`؛ Ledger `16/17 → 17/17`.
- Full boot smoke: `23 assertions PASS`؛ `17/17 modules`، Programs migrations=`2`، Members migrations=`1`.
- D2 contracts `20/20` و batch runtime `24 assertions` PASS.
- D1 contracts `27/27` و runtime schema guard `10 assertions` PASS؛ runtime SHOW queries=`0`.
- C7 Registry/Lifecycle runtime `36 assertions` PASS.
- C6 source `35/35` و Repository runtime `15 assertions` PASS.
- C5 regressions `12 + 10 + 7 + 6` assertions PASS.
- Hook parity RC27→RC28 برابر `53 actions / 4 filters / 2 shortcodes / 1 activation hook` کامل حفظ شد.

**معیار Production D3:** Ledger=`17/17`، Lifecycle=`17/17`، Registry=`17/10`، Programs migration count=`2`، Members migration count=`1`، Programs query plan=`batch/grouped/batch`، Home summary=`lightweight/compact/deferred`، D1 probes=`0`، Physical diagnostics=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D3، مرحله بعدی **Phase D / D4 — Lazy load Programs/Attendance** است.


# 38. گزارش اجرای Phase D / D4 — 1.3.1-rc29

**وضعیت:** ✅ D4 Implemented + Local/Artifact PASS — Pending Production Verification

اصلاحات اجراشده:

- Feature assetهای Programs و Attendance در mini-app از eager enqueue خارج شدند.
- `QYS_Programs_Module::public_asset_manifest()` و `QYS_Attendance::public_asset_manifest()` منبع URL/endpointهای lazy هستند.
- shell پایه با `QYSLazyFeatures` فقط هنگام اولین navigation، CSS/JS Feature را بارگذاری و dedupe می‌کند.
- Home Program cards به `qys-public-ui.js` منتقل شدند و فقط `home-summary` فشرده D3 را مصرف می‌کنند؛ برای Home نیازی به `qys-programs-public.js` نیست.
- Attendance دیگر روی صرف دریافت status، `sessions/history` را نمی‌خواند؛ Full data فقط در view حضور load می‌شود.
- Health Center کارت `Mini-app feature load = programs: lazy | attendance: lazy | data: on_view` دارد.
- Migration زیرساختی `20261006_018_rc29_lazy_member_features` متعلق به module `members` اضافه شد؛ Schema بدون تغییر است.

تست‌های D4:

- PHP `51/51`، JavaScript `10/10`، CSS `10/10` PASS.
- D4 source/static contracts: `27/27 PASS`.
- D4 manifest/runtime contract: `15 assertions PASS`.
- Migration regression RC28→RC29: `18 assertions PASS`؛ Ledger `17/18 → 18/18` و Members migrations=`2`.
- Full boot smoke: `27 assertions PASS`؛ `17/17 modules`.
- Hook parity RC28→RC29 برابر `53 actions / 4 filters / 2 shortcodes / 1 activation hook` حفظ شد.
- D3 Home Summary، D2 Programs batch، D1 schema guard، C6 Repository و C5 cycle regressions همگی PASS.

**معیار Production D4:** Ledger=`18/18`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`2`، Members migrations=`2`، Programs query plan=`batch/grouped/batch`، Home summary=`lightweight/compact/deferred`، Mini-app feature load=`lazy/lazy/on_view`، D1 probes=`0`، Physical diagnostics=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D4، مرحله بعدی **Phase D / D5 — Queue index `(status,id)`** است.

# 39. تأیید Production Phase D / D4 — RC29

**وضعیت:** ✅ D4 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc29` موارد زیر تأیید شدند:

- Schema=`2026.10.05.7`
- Ledger=`18/18`
- Lifecycle=`17/17`
- Registry=`17/10`
- Programs migrations=`2`
- Members migrations=`2`
- Programs query plan=`batch/grouped/batch`
- Home summary=`lightweight/compact/deferred`
- Mini-app feature load=`programs: lazy | attendance: lazy | data: on_view`
- Runtime schema probes=`0`
- Physical diagnostics=`12/12`
- Module Healthها `ok`
- Pending/Sending=`0`
- Worker/Cron/Retention سالم

با این تأیید، D4 روی Production بسته شد و D5 آغاز شد.

# 40. گزارش اجرای Phase D / D5 — 1.3.1-rc30

**وضعیت:** 🟨 D5 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D5 افزودن index فیزیکی مناسب برای query داغ Queue Worker است.

اصلاحات اجراشده:

- queue روی fresh install دارای `KEY status_id (status,id)` است.
- migration جدید `20261006_019_rc30_queue_status_id_index` برای existing install اضافه شد.
- migration قبل از DDL تمام indexها را بررسی می‌کند و هر index با prefix `status,id` را معتبر می‌پذیرد.
- اگر index مناسب وجود نداشته باشد، `ALTER TABLE ... ADD KEY status_id (status,id)` یک‌بار اجرا می‌شود.
- cache schema diagnostics بعد از DDL invalidate می‌شود و index در همان migration دوباره verify می‌شود.
- `QYS_Campaign_Repository` مالک contract جدید Queue Index است.
- Health Center کارت `Queue dispatch index = (status,id): ready | pending scan: indexed` دارد.
- migration متعلق به lifecycle module `messaging` است.
- Schema به `2026.10.06.1` bump شد؛ D5 فقط index فیزیکی اضافه می‌کند و داده دامنه rewrite نمی‌شود.
- batch size و rate-limit logic عمداً تغییر نکرده‌اند و در D6 انجام می‌شوند.

تست‌های D5:

- PHP `51/51`، JavaScript `10/10`، CSS `10/10` PASS.
- D5 source/static contracts: `20/20 PASS`.
- D5 runtime index assertions: `16 PASS`.
- missing/equivalent/wrong-prefix index paths و idempotency PASS.
- Source hook call-site parity RC29→RC30 بدون تغییر: `53/5/2/1`.

**معیار Production D5:** Schema=`2026.10.06.1`، Ledger=`19/19`، Lifecycle=`17/17`، Registry=`17/10`، Messaging migration count=`1`، Queue dispatch index=`(status,id): ready | pending scan: indexed`، D1 probes=`0`، Physical diagnostics=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D5، مرحله بعدی **Phase D / D6 — Queue throughput adaptive** است.

# 41. تأیید Production Phase D / D5 — RC30

**وضعیت:** ✅ D5 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc30` موارد زیر تأیید شدند:

- Schema=`2026.10.06.1`
- Ledger=`19/19`
- Lifecycle=`17/17`
- Registry=`17/10`
- Messaging migrations=`1`
- Queue dispatch index=`(status,id): ready | pending scan: indexed`
- Programs query plan=`batch/grouped/batch`
- Home summary=`lightweight/compact/deferred`
- Mini-app feature load=`lazy/lazy/on_view`
- Runtime schema probes=`0`
- Physical diagnostics=`12/12`
- Module Healthها `ok`
- Pending/Sending=`0`
- Worker/Cron/Retention سالم

با این تأیید، D5 روی Production بسته شد و D6 آغاز شد.

# 42. گزارش اجرای Phase D / D6 — 1.3.1-rc31

**وضعیت:** 🟨 D6 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D6 حذف محدودیت ثابت ۵ پیام در هر اجرای Worker و تبدیل throughput به سیاست bounded/adaptive است، بدون تغییر safety semantics تحویل.

اصلاحات اجراشده:

- `QYS_Queue_Throughput` به‌عنوان policy/service جدید اضافه شد.
- Worker از `pending_batch(5)` به cap تطبیقی مبتنی بر EMA زمان outbound منتقل شد.
- budget هر run=`20s` با reserve=`2s` و hard max=`30` است.
- اولین run بدون history با estimated cost=`1500ms` و cap=`12` شروع می‌شود.
- حداقل فاصله شروع outboundها=`150ms` است تا بدون فرض rate limit اختصاصی provider از burst جلوگیری شود.
- `429/retryable_failed` همان run را متوقف می‌کند؛ state موجود retryable حفظ می‌شود و auto-retry جدیدی اضافه نشده است.
- `uncertain` همان run را متوقف می‌کند تا یک اختلال شبکه به موج outcome نامشخص تبدیل نشود.
- lease، outbound marker، tenant permission، member eligibility و state machine تحویل بدون تغییر باقی مانده‌اند.
- telemetry non-PII در option غیر-autoload `qys_queue_throughput` ذخیره می‌شود.
- Health Center کارت `Queue throughput = mode: adaptive | budget: 20s | max: 30 | rate-limit: stop` دارد.
- migration جدید `20261006_020_rc31_adaptive_queue_throughput` متعلق به module `messaging` اضافه شد؛ Schema بدون تغییر `2026.10.06.1` است.

تست‌های D6:

- PHP `52/52`، JavaScript `10/10`، CSS `10/10` PASS.
- D6 source/static contracts: `32/32 PASS`.
- D6 throughput/runtime: `25 assertions PASS`.
- 429 روی outbound دوم ⇒ دقیقاً ۲ outbound و stop: PASS.
- uncertain روی outbound اول ⇒ دقیقاً ۱ outbound و stop: PASS.
- Migration regression RC30→RC31: `7 assertions PASS`؛ Ledger `19/20 → 20/20`.
- Hook call-site parity RC30→RC31 برابر `53 actions / 5 filters / 2 shortcodes / 1 activation hook` حفظ شد.

**معیار Production D6:** Schema=`2026.10.06.1`، Ledger=`20/20`، Lifecycle=`17/17`، Registry=`17/10`، Messaging migrations=`2`، Queue index=`ready/indexed`، Queue throughput=`adaptive/20s/max30/rate-limit stop`، D1 probes=`0`، Physical diagnostics=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D6، مرحله بعدی **Phase D / D7 — Health snapshot سبک‌تر** است.
# 43. تأیید Production Phase D / D6 — RC31

**وضعیت:** ✅ D6 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc31` موارد زیر تأیید شدند:

- Schema=`2026.10.06.1`
- Ledger=`20/20`
- Lifecycle=`17/17`
- Registry=`17/10`
- Messaging migrations=`2`
- Queue dispatch index=`(status,id): ready | pending scan: indexed`
- Queue throughput=`adaptive / 20s / max30 / rate-limit stop`
- Programs query plan=`batch/grouped/batch`
- Home summary=`lightweight/compact/deferred`
- Mini-app feature load=`lazy/lazy/on_view`
- Runtime schema probes=`0`
- Physical diagnostics=`12/12`
- Module Healthها `ok`
- Pending/Sending=`0`
- Worker/Cron/Retention سالم

با این تأیید، D6 روی Production بسته شد و D7 آغاز شد.

# 44. گزارش اجرای Phase D / D7 — 1.3.1-rc32

**وضعیت:** 🟨 D7 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D7 بستن `QYS-P1-10 — Health Snapshot سنگین در هر دقیقه` است.

اصلاحات اجراشده:

- `heartbeat_finish()` دیگر full `snapshot(true)` را در هر Worker tick اجرا نمی‌کند.
- heartbeat دقیقه‌ای فقط metadata سبک start/finish/result را ثبت می‌کند.
- hook مستقل `qys_health_snapshot` اضافه شد.
- schedule جدید `qys_health_15m` هر 900 ثانیه full snapshot را اجرا می‌کند.
- Health page و Ability همچنان full snapshot تازه را on-demand محاسبه می‌کنند.
- option غیر-autoload `qys_health_snapshot_meta` فقط زمان، مدت و status aggregate آخرین snapshot دوره‌ای را نگه می‌دارد.
- Health Center کارت `Health snapshot = heartbeat: lightweight | full: 15m/on-demand` دارد.
- migration زیرساختی `20261006_021_rc32_lightweight_health_snapshot` متعلق به module `health` اضافه شد؛ Schema بدون تغییر `2026.10.06.1` است.

تست‌های D7:

- PHP `52/52`، JavaScript `10/10`، CSS `10/10` PASS.
- D7 source/static contracts: `27/27 PASS`.
- D7 runtime lightweight snapshot: `10/10 assertions PASS`؛ heartbeat full-metrics calls=`0`.
- D7 migration contract: PASS.
- Hook inventory RC32=`55 actions / 6 filters / 2 shortcodes / 1 activation hook`؛ delta نسبت به RC31 فقط سه hook مورد انتظار D7 است.
- D6 throughput و D5 queue-index regressions PASS.

**معیار Production D7:** Ledger=`21/21`، Lifecycle=`17/17`، Registry=`17/10`، Health migrations=`1`، Health snapshot=`heartbeat: lightweight | full: 15m/on-demand`، Queue throughput و index سالم، D1 probes=`0`، Physical diagnostics=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D7، مرحله بعدی **Phase D / D8 — Batch export** است.

# 45. تأیید Production Phase D / D7 — RC32

**وضعیت:** ✅ D7 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc32` موارد زیر تأیید شدند:

- Schema=`2026.10.06.1`
- Ledger=`21/21`
- Lifecycle=`17/17`
- Registry=`17/10`
- Health migrations=`1`
- Health snapshot=`heartbeat: lightweight | full: 15m/on-demand`
- Queue dispatch index=`ready/indexed`
- Queue throughput=`adaptive / 20s / max30 / rate-limit stop`
- Programs query plan=`batch/grouped/batch`
- Home summary=`lightweight/compact/deferred`
- Mini-app feature load=`lazy/lazy/on_view`
- Runtime schema probes=`0`
- Physical diagnostics=`12/12`
- Module Healthها `ok`
- Pending/Sending=`0`
- Worker/Cron/Retention سالم

با این تأیید، D7 روی Production بسته شد و D8 آغاز شد.

# 46. گزارش اجرای Phase D / D8 — 1.3.1-rc33

**وضعیت:** 🟨 D8 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D8 تبدیل خروجی‌های CSV اصلی به streaming batch با حافظه bounded و cursor/keyset است تا export بزرگ کل گزارش را پیش از دانلود در حافظه نسازد و Program export نیز به سقف خاموش ۱۰۰۰ ردیف محدود نباشد.

اصلاحات اجراشده:

- `QYS_Batch_Export` به‌عنوان boundary مشترک CSV اضافه شد؛ قرارداد `streaming / chunk=250 / keyset / bounded` است.
- `fputcsv` و `php://output` در سورس runtime به این boundary متمرکز شدند.
- Program CSV با `applications_export_chunk()` به keyset تبدیل شد؛ سقف ۱۰۰۰ Application حذف و Answers در هر chunk batch fetch می‌شوند.
- Excel follow-up export دیگر `followup_rows()` کامل را materialize نمی‌کند؛ import exclusions و queue non-sent در دو جریان keyset و chunked صادر می‌شوند.
- Attendance export از OFFSET به `attendance_export_chunk_after()` منتقل شد و Tenant/Role scope هر chunk حفظ می‌شود.
- Birthday archive export از OFFSET به keyset نزولی منتقل شد؛ سقف موجود ۵۰۰۰ رکورد و Audit/PII policy بدون تغییر باقی ماند.
- Health Center کارت `Batch export = mode: streaming | chunk: 250 | cursor: keyset | memory: bounded` دارد.
- migration جدید `20261006_022_rc33_batch_export` متعلق به module `core` است؛ Schema بدون تغییر `2026.10.06.1` باقی می‌ماند.

تست‌های D8:

- PHP `53/53`، JavaScript `10/10`، CSS `10/10` PASS.
- D8 source/static contracts: `17/17 PASS`.
- Batch export helper runtime: `10 assertions PASS`.
- Repository keyset runtime: `16 assertions PASS`.
- D8 migration contract: PASS.
- Hook parity RC32→RC33 برابر `55 actions / 6 filters / 2 shortcodes / 1 activation hook` حفظ شد.
- serialization boundary: `fputcsv` و `php://output` فقط در `QYS_Batch_Export`.

**معیار Production D8:** Schema=`2026.10.06.1`، Ledger=`22/22`، Lifecycle=`17/17`، Registry=`17/10`، Core migrations=`3`، Batch export=`streaming/chunk250/keyset/bounded`، Health snapshot و Queue contracts سالم، D1 probes=`0`، Physical diagnostics=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D8، مرحله بعدی **Phase D / D9 — Birthday index** است.



# 47. تأیید Production Phase D / D8 — RC33

**وضعیت:** ✅ D8 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc33` موارد زیر تأیید شدند:

- Schema=`2026.10.06.1`
- Ledger=`22/22`
- Lifecycle=`17/17`
- Registry=`17/10`
- Core migrations=`3`
- Batch export=`streaming / chunk250 / keyset / bounded`
- Health snapshot=`lightweight / 15m/on-demand`
- Queue dispatch index=`ready/indexed`
- Queue throughput=`adaptive / 20s / max30 / rate-limit stop`
- Programs query plan=`batch/grouped/batch`
- Home summary=`lightweight/compact/deferred`
- Mini-app feature load=`lazy/lazy/on_view`
- Runtime schema probes=`0`
- Physical diagnostics=`12/12`
- Module Healthها `ok`
- Pending/Sending=`0`
- Worker/Cron/Retention سالم

با این تأیید، D8 روی Production بسته شد و D9 آغاز شد.

# 48. گزارش اجرای Phase D / D9 — 1.3.1-rc34

**وضعیت:** 🟨 D9 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D9 بستن `QYS-P2-10 — Birthday با LIKE '%MM/DD'` و حذف Full Scan ناشی از leading wildcard است.

اصلاحات اجراشده:

- ستون مشتق‌شده `members.birth_md char(5)` با فرمت `MM/DD` اضافه شد.
- Fresh install دارای `KEY birthday_lookup (birth_md,birthday_opt_in,verified,consent,id)` است.
- migration جدید `20261006_023_rc34_birthday_lookup_index` ستون را در existing install اضافه، داده موجود را backfill و index معادل را idempotent ایجاد می‌کند.
- `QYS_Member_Repository::insert/update` هنگام نوشتن `birth_jalali`، کلید `birth_md` را همگام می‌کند.
- `birthday_count_enabled_campuses()`، `birthday_members_after()` و `birthday_complete_count_for_university()` از leading wildcard به `birth_md=%s` exact match منتقل شدند.
- در سورس runtime Birthday دیگر `birth_jalali LIKE` وجود ندارد.
- Health Center کارت `Birthday lookup = column: birth_md | query: exact | index: ready` دارد.
- migration متعلق به module `birthday` است و migration count این ماژول پس از RC34 برابر `1` خواهد بود.
- Schema به `2026.10.06.2` bump شد؛ birth_jalali، consent، verified و tenant state rewrite نمی‌شوند.

تست‌های D9:

- PHP `53/53`، JavaScript `10/10`، CSS `10/10` PASS.
- D9 source/static contracts: `24/24 PASS`.
- D9 repository/migration runtime: `21 assertions PASS`.
- idempotent DDL، custom equivalent index، wrong-prefix rejection، exact queryها، write synchronization و migration callback PASS.
- Hook inventory RC34=`55 actions / 6 filters / 2 shortcodes / 1 activation hook` و نسبت به RC33 بدون تغییر است.

**معیار Production D9:** Schema=`2026.10.06.2`، Ledger=`23/23`، Lifecycle=`17/17`، Registry=`17/10`، Birthday migrations=`1`، Birthday lookup=`birth_md/exact/index ready`، همه قراردادهای D1–D8 سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D9، مرحله بعدی **Phase D / D10 — conditional asset loading** است.


# 49. تأیید Production Phase D / D9 — RC34

**وضعیت:** ✅ D9 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc34` موارد زیر تأیید شدند:

- Schema=`2026.10.06.2`
- Ledger=`23/23`
- Lifecycle=`17/17`
- Registry=`17/10`
- Birthday migrations=`1`
- Birthday lookup=`birth_md / exact / index ready`
- Batch export=`streaming / chunk250 / keyset / bounded`
- Health snapshot=`lightweight / 15m/on-demand`
- Queue dispatch index=`ready/indexed`
- Queue throughput=`adaptive / 20s / max30 / rate-limit stop`
- Programs query plan=`batch/grouped/batch`
- Home summary=`lightweight/compact/deferred`
- Mini-app feature load=`lazy/lazy/on_view`
- Runtime schema probes=`0`
- Physical diagnostics=`12/12`
- Module Healthها `ok`
- Pending/Sending=`0`
- Worker/Cron/Retention سالم

با این تأیید، D9 روی Production بسته شد و D10 آغاز شد.

# 50. گزارش اجرای Phase D / D10 — 1.3.1-rc35

**وضعیت:** 🟨 D10 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف D10 بستن `QYS-P2-12 — Asset Loading غیرشرطی در /qys/` است.

اصلاحات اجراشده:

- `QYS_Portal_Asset_Policy` اضافه شد؛ قرارداد `shell=base / feature=conditional / scope=section_permission` است.
- `QYS_Multitenant` فقط shell مشترک portal را eager enqueue می‌کند.
- Attendance، Targeted، Programs و Excel assetهای خود را فقط در section مجاز خود و پس از بررسی module availability + permission enqueue می‌کنند.
- assetهای Attendance/QR/Targeted/Programs دیگر روی Home/Members/Messages/More بارگذاری نمی‌شوند.
- حدود `79,767` بایت asset خام feature از viewهای غیرمرتبط حذف شد؛ raw QuranYar portal load آن viewها از `145,112` به `65,345` بایت رسید (حدود 55٪ کاهش، پیش از compression).
- Health Center کارت `Portal assets = shell: base | feature: conditional | scope: section+permission` دارد.
- migration جدید `20261006_024_rc35_conditional_portal_assets` متعلق به module `universities` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های D10:

- PHP `54/54`، JavaScript `10/10`، CSS `10/10` PASS.
- D10 source/static contracts: `35/35 PASS`.
- Portal Asset Policy runtime: `20/20 PASS`.
- Feature enqueue runtime: `18/18 PASS`.
- D10 migration contract: PASS.
- Performance/C7 source regression: `12/12 PASS`.
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`؛ دو action جدید فقط برای asset ownership مستقیم Attendance/Targeted هستند.

**معیار Production D10:** Schema=`2026.10.06.2`، Ledger=`24/24`، Lifecycle=`17/17`، Registry=`17/10`، Universities migrations=`1`، Portal assets=`base/conditional/section+permission`، Birthday lookup و تمام قراردادهای D1–D9 سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

پس از Production Verification D10، **ده گام Phase D کامل** می‌شوند و مرحله بعدی **Phase E / E1 — جداسازی Membership از Messaging Consent** است.


# 51. تأیید Production Phase D / D10 و تکمیل Phase D — RC35

**وضعیت:** ✅ D10 Production Verified — Phase D Complete

در Health Center تولیدی پس از نصب `1.3.1-rc35` موارد زیر تأیید شدند:

- Schema=`2026.10.06.2`
- Ledger=`24/24`
- Lifecycle=`17/17`
- Registry=`17/10`
- Universities migrations=`1`
- Portal assets=`base / conditional / section+permission`
- Birthday lookup=`birth_md / exact / index ready`
- Batch export=`streaming / chunk250 / keyset / bounded`
- Health snapshot=`lightweight / 15m/on-demand`
- Queue dispatch index=`ready/indexed`
- Queue throughput=`adaptive / 20s / max30 / rate-limit stop`
- Programs query plan=`batch/grouped/batch`
- Home summary=`lightweight/compact/deferred`
- Mini-app feature load=`lazy/lazy/on_view`
- Runtime schema probes=`0`
- Physical diagnostics=`12/12`
- Module Healthها `ok`
- Pending/Sending=`0`
- Worker/Cron/Retention سالم

با این تأیید، همه ده گام Phase D روی Production بسته شدند و Phase E آغاز شد.

# 52. گزارش اجرای Phase E / E1 — 1.3.1-rc36

**وضعیت:** 🟨 E1 Implemented + Local/Artifact Tests PASS — Pending Production Verification

هدف E1 بستن `QYS-P2-21 — تشکیل پرونده به رضایت دریافت پیام وابسته است` است.

اصلاحات اجراشده:

- تشکیل پرونده دیگر به `requestWriteAccess` یا `consent=true` وابسته نیست.
- عضو جدید بدون opt-in پیام با `consent=0` ساخته می‌شود؛ opt-in صریح می‌تواند همان ابتدا consent را فعال کند.
- ویرایش پرونده consent موجود را دست‌نخورده حفظ می‌کند و هیچ implicit re-enable ندارد.
- Welcome برای عضو جدید فقط در صورت consent فعال attempt می‌شود.
- Status API فیلدهای صریح `membership_active` و `messaging_consent` را در کنار `registered/enabled` سازگار اضافه کرده است.
- mini-app دکمه ثبت پرونده را مستقل از مجوز پیام نگه می‌دارد و ردشدن مجوز، عضویت را مسدود نمی‌کند.
- وضعیت account در بخش More دیگر صرفاً به‌خاطر messaging-off attention نمی‌گیرد.
- نمایش فعال بودن تبریک تولد به birthday opt-in + messaging consent وابسته شد.
- Health Center کارت `Membership / messaging = membership: independent | messaging: optional | default: off` دارد.
- migration جدید `20261006_025_rc36_membership_messaging_consent` متعلق به module `members` است.
- Schema بدون تغییر `2026.10.06.2` باقی می‌ماند و هیچ consent تاریخی rewrite نمی‌شود.

تست‌های E1:

- PHP `54/54`، JavaScript `10/10`، CSS `10/10` PASS.
- E1 source/static contracts: `30/30 PASS`.
- E1 runtime contract: `7/7 PASS`.
- D10 Portal Asset Policy: `20/20 PASS`.
- D10 Feature enqueue: `18/18 PASS`.
- D10 migration contract: PASS.
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` و نسبت به RC35 بدون تغییر است.

**معیار Production E1:** Schema=`2026.10.06.2`، Ledger=`25/25`، Lifecycle=`17/17`، Registry=`17/10`، Members migrations=`3`، Membership/Messaging=`independent/optional/default off`، قراردادهای Phase D سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

علاوه بر Health Center، Live UX Acceptance اصلی E1 باید تأیید کند که یک عضو جدید بدون فعال‌کردن دریافت پیام می‌تواند پرونده بسازد و بعداً همان consent را جداگانه فعال کند.

پس از Production Verification E1، مرحله بعدی **Phase E / E2 — Gate واضح Programs** است.


# 53. تأیید Production Phase E / E1 — RC36

**وضعیت:** ✅ E1 Production Verified

در Health Center تولیدی پس از نصب `1.3.1-rc36`، Schema=`2026.10.06.2`، Ledger=`25/25`، Lifecycle=`17/17`، Registry=`17/10`، Members migrations=`3` و قرارداد `Membership / messaging = independent / optional / default off` تأیید شد. تمام قراردادهای Phase D نیز سالم باقی ماندند.

سناریوی اصلی دستی E1 نیز PASS شد: عضو جدید بدون فعال‌کردن پیام پرونده ساخت، messaging خاموش باقی ماند و سپس consent به‌صورت جداگانه فعال شد.

از این نقطه بنا بر تصمیم محصول، تست‌های دستی عادی UX/End-to-End مراحل بعدی تا حد امکان در **پکیج پذیرش نهایی پروژه** تجمیع می‌شوند؛ هر RC همچنان باید تست‌های خودکار/Artifact و Health Center را پاس کند. فقط ریسک‌های مخرب یا غیرقابل‌پوشش خودکار استثنا هستند.

# 54. گزارش اجرای Phase E / E2 — 1.3.1-rc37

**وضعیت:** 🟨 E2 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E2 بستن `QYS-P2-22 — Gate برنامه‌ها بدون توضیح Redirect می‌کند` است.

اصلاحات اجراشده:

- redirect خاموش Programs→Profile از mini-app حذف شد.
- Programs view یک Gate پایه و مستقل از feature bundle دارد که علت مسدودبودن را در همان صفحه توضیح می‌دهد.
- سه state صریح `membership_required / profile_incomplete / verification_pending` هرکدام CTA متناسب «تشکیل پرونده / تکمیل پرونده / مشاهده وضعیت پرونده» دارند.
- تا زمانی که Gate بسته است، asset و API کامل Programs lazy-load نمی‌شود.
- پس از آماده‌شدن status، Gate باز و Feature در همان مسیر معمول lazy-load می‌شود.
- backend همچنان authoritative است و REST error metadata شامل `gate / cta_label / cta_view` است.
- `QYS_Programs_Module::access_gate_contract()` قرارداد `explain / explicit / none` را ارائه می‌کند.
- Health Center کارت `Programs access gate = mode: explain | cta: explicit | redirect: none` دارد.
- migration جدید `20261006_026_rc37_programs_access_gate` متعلق به module `programs` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های E2:

- PHP `54/54`، JavaScript `10/10`، CSS `10/10` PASS.
- E2 source/static contracts: `12/12 PASS`.
- Backend Gate runtime: `18/18 PASS`.
- Client Gate runtime/static: `13/13 PASS`.
- Hook inventory بدون تغییر مورد انتظار نسبت به RC36: `57 / 6 / 2 / 1`.

**معیار Production Health E2:** Schema=`2026.10.06.2`، Ledger=`26/26`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`3`، Programs access gate=`explain/explicit/none`، E1 و تمام قراردادهای Phase D سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual UX Acceptance سه state Gate به بسته تست نهایی پروژه منتقل شده است.

پس از Production Health Verification E2، مرحله بعدی **Phase E / E3 — Confirmation برای destructive actions** است.


# 55. تأیید Production Phase E / E2 — RC37

**وضعیت:** ✅ E2 Production Health Verified

در Health Center تولیدی پس از نصب `1.3.1-rc37` موارد زیر تأیید شدند:

- Schema=`2026.10.06.2`
- Ledger=`26/26`
- Lifecycle=`17/17`
- Registry=`17/10`
- Programs migrations=`3`
- Programs access gate=`explain / explicit / none`
- Membership/Messaging=`independent / optional / default off`
- تمام قراردادهای Phase D سالم
- Runtime probes=`0` و Physical diagnostics=`12/12`
- Module Healthها `ok` و Worker/Cron/Retention سالم

Manual UX Acceptance سه حالت Gate طبق تصمیم محصول به پکیج پذیرش نهایی منتقل شده است.

# 56. گزارش اجرای Phase E / E3 — 1.3.1-rc38

**وضعیت:** 🟨 E3 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E3 بستن `QYS-P2-23 — Actionهای برگشت‌ناپذیر confirmation ندارند` است.

اصلاحات اجراشده:

- چهار عمل ثبت‌شده در ممیزی (`completed / cancelled / archived / rejected`) اکنون confirmation صریح دارند.
- `qys-confirm.js` فقط در Programs بارگذاری می‌شود و از `data-qys-confirm` برای نمایش پیام قبل از submit استفاده می‌کند.
- Cancel submit را در capture phase متوقف می‌کند؛ Confirm marker `qys_destructive_confirmed=1` می‌گذارد.
- Backend همین marker را قبل از Program terminal transition یا applicant reject اجباری می‌کند؛ client-only bypass مجاز نیست.
- سایر transitionهای غیرپایانی رفتار قبلی را حفظ کرده‌اند.
- Health Center کارت `Destructive confirmation = program: complete/cancel/archive | applicant: reject | server: required` دارد.
- migration جدید `20261006_027_rc38_destructive_confirmations` متعلق به module `programs` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های E3:

- PHP `54/54`، JavaScript `11/11`، CSS `10/10` PASS.
- E3 source/static contracts: `33/33 PASS`.
- E3 PHP runtime contract: `17/17 PASS`.
- E3 client confirmation runtime: `13/13 PASS`.
- E3 migration contract: PASS.
- Hook inventory بدون تغییر: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`.

**معیار Production Health E3:** Schema=`2026.10.06.2`، Ledger=`27/27`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`4`، Destructive confirmation=`complete/cancel/archive + reject + server required`، E2/E1 و تمام قراردادهای Phase D سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual destructive-flow acceptance به پکیج تست نهایی پروژه منتقل شده است.

پس از Production Health Verification E3، مرحله بعدی **Phase E / E4 — Program Edit workflow** است.

# 51. تأیید Production Health Phase E / E3 — RC38

**وضعیت:** ✅ E3 Production Health Verified — Manual UX Acceptance Deferred

در Health Center تولیدی پس از نصب `1.3.1-rc38`، Schema=`2026.10.06.2`، Ledger=`27/27`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`4` و قرارداد `Destructive confirmation = program: complete/cancel/archive | applicant: reject | server: required` تأیید شد. Worker/Cron/Retention و تمام Module Healthها سالم بودند.

طبق تصمیم محصول، تست دستی Cancel/Confirm این عملیات با داده آزمایشی در پکیج پذیرش نهایی پروژه تجمیع می‌شود.

# 52. گزارش اجرای Phase E / E4 — 1.3.1-rc39

**وضعیت:** 🟨 E4 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E4 بستن `QYS-P2-24 — Program مشخصات اصلی قابل ویرایش نیست` است.

اصلاحات اجراشده:

- `QYS_Program_Service::edit_policy()` مرجع state-aware برای ویرایش مشخصات اصلی Program شد.
- Draft: عنوان، توضیح، ظرفیت، Audience و Attendance configuration قابل ویرایش‌اند.
- Registration open/closed و Confirmed: عنوان/توضیح/ظرفیت و Attendance تا قبل از link قابل ویرایش؛ Audience قفل است.
- Running: فقط عنوان و توضیح قابل اصلاح است.
- Completed/Cancelled/Archived: فقط خواندنی.
- ظرفیت محدود نمی‌تواند پایین‌تر از تعهد جاری accepted/enrolled/participating قرار گیرد.
- Target universities فقط در Draft و فقط برای مدیرکل قابل تغییر است؛ manager university باید در scope باقی بماند.
- Attendance پس از ایجاد link واقعی immutable می‌شود.
- Repository write boundaryهای `update_program_details()` و `replace_program_universities()` اضافه شدند.
- هر ویرایش موفق `program_updated` را در audit ثبت می‌کند.
- UI جزئیات برنامه بخش «ویرایش برنامه» و quick-nav «مشخصات» دارد و field lock reason را نمایش می‌دهد.
- Health Center کارت `Program edit = mode: state-aware | capacity: reserved-floor | audience: draft-only | attendance: pre-link` دارد.
- Migration `20261006_028_rc39_program_edit_workflow` متعلق به module `programs` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های E4:

- PHP `54/54`، JavaScript `11/11`، CSS `10/10` PASS.
- E4 source/static contracts: `31/31 PASS`.
- E4 runtime state/capacity/tenant: `21/21 PASS`.
- E4 module contract: `8/8 PASS`.
- E4 migration contract: PASS.
- Hook inventory بدون تغییر: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`.

**معیار Production E4:** Schema=`2026.10.06.2`، Ledger=`28/28`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`5`، Program edit=`state-aware/reserved-floor/draft-only/pre-link`، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

پس از Production Health Verification E4، مرحله بعدی **Phase E / E5 — Member Withdraw** است.

# 53. تأیید Production Health Phase E / E4 — RC39

**وضعیت:** ✅ E4 Production Health Verified — Manual UX Acceptance Deferred

در Health Center تولیدی پس از نصب `1.3.1-rc39`، Schema=`2026.10.06.2`، Ledger=`28/28`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`5` و قرارداد `Program edit = state-aware / reserved-floor / draft-only / pre-link` تأیید شد. Worker/Cron/Retention و تمام Module Healthها سالم بودند و قراردادهای E1–E3 و Phase D بدون Regression باقی ماندند.

طبق سیاست پروژه، سناریوهای دستی ویرایش Program در stateهای مختلف به پکیج پذیرش نهایی UX/E2E منتقل شده‌اند.

# 54. گزارش اجرای Phase E / E5 — 1.3.1-rc40

**وضعیت:** 🟨 E5 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E5 بستن `QYS-P2-25 — عضو امکان Withdraw ندارد` است.

اصلاحات اجراشده:

- endpoint جدید `POST /qys/v1/programs/withdraw` برای self-service عضو اضافه شد.
- member id هرگز از client گرفته نمی‌شود و از identity احرازشده Eitaa resolve می‌شود.
- confirmation صریح `confirmed=1` قبل از اجرای انصراف لازم است.
- Applicationهای فعال `submitted/under_review/waitlisted/accepted` در نبود Enrollment به `withdrawn` می‌روند.
- Enrollmentهای فعال `enrolled/participating` به `withdrawn` می‌روند و `withdrawn_at_utc` ثبت می‌شود.
- اگر Enrollment وجود داشته باشد، withdrawal روی Enrollment انجام می‌شود و Application پذیرفته‌شده برای history حفظ می‌شود.
- Programهای terminal و stateهای قبلاً terminal قابل Withdraw دوباره نیستند.
- هیچ form answer یا Attendance history حذف نمی‌شود؛ `member_withdrawal` در Audit ثبت می‌شود.
- Mini-app برای stateهای مجاز CTA «پس‌گرفتن درخواست» یا «انصراف از برنامه» با confirmation نشان می‌دهد.
- can-withdraw از read model موجود محاسبه می‌شود و query per-row جدید وارد Programs list/record نشده است.
- Health Center کارت `Member withdraw = self-service / withdrawable / explicit / preserved` دارد.
- migration جدید `20261006_029_rc40_member_withdraw` متعلق به module `programs` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های E5:

- PHP `54/54`، JavaScript `11/11`، CSS `10/10` PASS.
- E5 source/static contracts: `36/36 PASS`.
- E5 domain runtime: `19/19 PASS`.
- Hook inventory بدون تغییر: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`.

**معیار Production E5:** Schema=`2026.10.06.2`، Ledger=`29/29`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`6`، Member withdraw=`self-service/application+enrollment withdrawable/explicit/preserved`، E4/E3/E2/E1 و Phase D سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual UX Acceptance انصراف به پکیج پذیرش نهایی منتقل شده است.

پس از Production Health Verification E5، مرحله بعدی **Phase E / E6 — Error / Empty / Loading استاندارد** است.

# 55. تأیید Production Health Phase E / E5 — RC40

**وضعیت:** ✅ E5 Production Health Verified — Manual UX Acceptance Deferred

در Health Center تولیدی پس از نصب `1.3.1-rc40`، Schema=`2026.10.06.2`، Ledger=`29/29`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`6` و قرارداد `Member withdraw = self-service / application+enrollment withdrawable / explicit / history preserved` تأیید شد. Worker/Cron/Retention و Module Healthها سالم بودند.

تست دستی journey انصراف طبق تصمیم محصول به پکیج پذیرش نهایی UX/E2E منتقل شده است.

# 56. گزارش اجرای Phase E / E6 — 1.3.1-rc41

**وضعیت:** 🟨 E6 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E6 بستن `QYS-P2-26 — Home Programs خطا را با Empty یکی می‌کند` و استانداردکردن stateهای async اصلی Member App است.

اصلاحات اجراشده:

- `QYS_Member_Experience::async_state_contract()` قرارداد canonical را تعریف می‌کند: `loading=visible / empty=distinct / error=visible / retry=explicit`.
- Home Programs در Loading قابل مشاهده است و `aria-busy` دارد.
- Home Programs نتیجه خالی را با Empty صریح نمایش می‌دهد و دیگر section را مخفی نمی‌کند.
- خطای Home Programs visible و retryable است؛ Error دیگر به Empty تبدیل نمی‌شود.
- primitive مشترک `qys-async-state` برای Loading/Empty/Error اضافه شد.
- Lazy Feature load برای Programs/Attendance از Loading/Error مشترک استفاده می‌کند.
- Programs list و record fetch نیز از primitive مشترک استفاده می‌کنند؛ network/server error CTA Retry دارد ولی E2 access gate مسیر Profile خودش را حفظ می‌کند.
- migration جدید `20261006_030_rc41_async_member_states` متعلق به module `members` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های E6:

- PHP `54/54`، JavaScript `11/11`، CSS `10/10` PASS.
- E6 source/static contracts: `35/35 PASS`.
- Async state markup runtime: `10/10 PASS`.
- Member async contract runtime: `6/6 PASS`.
- Hook inventory بدون تغییر: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`.

**معیار Production E6:** Schema=`2026.10.06.2`، Ledger=`30/30`، Lifecycle=`17/17`، Registry=`17/10`، Members migrations=`4`، Programs migrations=`6`، Async states=`loading visible / empty distinct / error visible / retry explicit`، E1–E5 و Phase D سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual UX Acceptance حالات empty/error/retry به پکیج پذیرش نهایی منتقل شده است.

پس از Production Health Verification E6، مرحله بعدی **Phase E / E7 — Draft فرم کاربر** است.

# 57. تأیید Production Health Phase E / E6 — RC41

**وضعیت:** ✅ E6 Production Health Verified — Manual UX Acceptance Deferred

در Health Center تولیدی پس از نصب `1.3.1-rc41`، Schema=`2026.10.06.2`، Ledger=`30/30`، Lifecycle=`17/17`، Registry=`17/10`، Members migrations=`4` و قرارداد `Async states = loading visible / empty distinct / error visible / retry explicit` تأیید شد. Worker/Cron/Retention و Module Healthها سالم بودند و E1–E5 و Phase D بدون Regression باقی ماندند.

Manual UX Acceptance حالات async طبق سیاست پروژه به پکیج پذیرش نهایی منتقل شده است.

# 58. گزارش اجرای Phase E / E7 — 1.3.1-rc42

**وضعیت:** 🟨 E7 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E7 بستن `QYS-P2-28 — Draft فرم کاربر وجود ندارد` است.

اصلاحات اجراشده:

- Draft پاسخ فرم Program روی localStorage همان دستگاه نگهداری می‌شود.
- scope کلید برابر member + program + form fingerprint است و شناسه خام member ذخیره نمی‌شود.
- TTL برابر ۲۴ ساعت است؛ draft منقضی خودکار حذف می‌شود.
- input/change با debounce ذخیره می‌شوند و pagehide/navigation flush فوری دارد.
- بازکردن فرم Draft را خودکار restore می‌کند و CTA دارای Draft به «ادامه پیش ثبت‌نام» تغییر می‌کند.
- submit موفق Draft را پاک می‌کند؛ submit ناموفق آن را حفظ می‌کند.
- تغییر ساختار فرم باعث fingerprint جدید و جلوگیری از restore پاسخ‌های ناسازگار می‌شود.
- هیچ endpoint، schema، permission، tenant policy یا domain transition تغییر نکرده است.
- Health Center کارت `Form draft = storage local / scope member+program+form / ttl 24h / clear successful-submit` دارد.
- migration جدید `20261006_031_rc42_member_form_draft` متعلق به module `programs` است؛ Schema بدون تغییر `2026.10.06.2` می‌ماند.

تست‌های E7:

- PHP `54/54`، JavaScript `11/11`، CSS `10/10` PASS.
- E7 source/static contracts: `35/35 PASS`.
- Draft runtime: `24/24 PASS`.
- PHP contract/migration runtime: `8/8 PASS`.
- Programs migration registrations=`7`.

**معیار Production E7:** Schema=`2026.10.06.2`، Ledger=`31/31`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`7`، Form draft=`local/member+program+form/24h/successful-submit`، E1–E6 و Phase D سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual Draft UX Acceptance به پکیج نهایی UX/E2E منتقل شده است.

پس از Production Health Verification E7، مرحله بعدی **Phase E / E8 — validation field-level** است.


# 59. تأیید Production Health Phase E / E7 — RC42

**وضعیت:** ✅ E7 Production Health Verified — Manual UX Acceptance Deferred

در Health Center تولیدی پس از نصب `1.3.1-rc42`، Schema=`2026.10.06.2`، Ledger=`31/31`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`7` و قرارداد `Form draft = local / member+program+form / 24h / successful-submit` تأیید شد. Async states، E1–E6 و Phase D بدون Regression باقی ماندند.

Manual UX Acceptance Draft طبق سیاست پروژه به پکیج پذیرش نهایی منتقل شده است.

# 60. گزارش اجرای Phase E / E8 — 1.3.1-rc43

**وضعیت:** 🟨 E8 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E8 بستن `QYS-P2-29 — Validation checkbox ناقص است` و ایجاد validation فیلدبه‌فیلد برای فرم Program است.

اصلاحات اجراشده:

- `field_validation_contract()` قرارداد `field-level / client+server / one-or-more / inline / first-invalid / server-authoritative` را تعریف می‌کند.
- required multi-choice سمت Client حداقل یک انتخاب را enforce می‌کند.
- required/text length/number/date/single-choice/yes-no در Client قبل از شبکه بررسی می‌شوند.
- پیام خطا inline، `aria-invalid` و focus اولین فیلد نامعتبر اضافه شده است.
- Backend validation همچنان authoritative است و برای خطا `field_key/field_type` برمی‌گرداند تا Client همان فیلد را علامت‌گذاری کند.
- Draft E7 در validation failure حفظ می‌شود و فقط submit موفق آن را پاک می‌کند.
- Health Center کارت `Field validation = field-level / client+server / one-or-more / inline` دارد.
- migration جدید `20261006_032_rc43_field_level_validation` متعلق به module `programs` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های E8:

- PHP `54/54`، JavaScript `11/11`، CSS `10/10` PASS.
- E8 source/static contracts: `34/34 PASS`.
- E8 PHP validation runtime: `13/13 PASS`.
- E8 Client validation runtime: `15/15 PASS`.
- E8 contract runtime: `7/7 PASS`.
- Hook inventory بدون تغییر: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`.

**معیار Production E8:** Schema=`2026.10.06.2`، Ledger=`32/32`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`8`، Field validation=`field-level/client+server/one-or-more/inline`، Form draft و E1–E6 سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual UX Acceptance validation به پکیج نهایی UX/E2E منتقل شده است.

پس از Production Health Verification E8، مرحله بعدی **Phase E / E9 — bulk applicant management** است.


# 61. تأیید Production Health Phase E / E8 — RC43

**وضعیت:** ✅ E8 Production Health Verified — Manual UX Acceptance Deferred

در Health Center تولیدی پس از نصب `1.3.1-rc43`، Schema=`2026.10.06.2`، Ledger=`32/32`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`8` و قرارداد `Field validation = field-level / client+server / one-or-more / inline` تأیید شد. Form draft، Async states، E1–E7 و Phase D بدون Regression باقی ماندند و Worker/Cron/Retention سالم بودند.

Manual UX Acceptance validation طبق سیاست پروژه به پکیج پذیرش نهایی منتقل شده است.

# 62. گزارش اجرای Phase E / E9 — 1.3.1-rc44

**وضعیت:** 🟨 E9 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E9 بستن `QYS-P2-30 — مدیریت متقاضیان عملیات گروهی ندارد` است.

اصلاحات اجراشده:

- بخش متقاضیان Program دارای جست‌وجوی نام و فیلترهای وضعیت درخواست، گروه عضو و داشتن/نداشتن ثبت‌نام قطعی شد.
- فیلترها در Repository و با حفظ Tenant Scope اعمال می‌شوند؛ جست‌وجوی عمومی فقط نام/نام خانوادگی را پوشش می‌دهد و کد ملی/موبایل را به جست‌وجوی عمومی اضافه نمی‌کند.
- حداکثر ۱۰۰ متقاضی انتخاب‌شده در هر اجرا قابل پردازش‌اند.
- عملیات گروهی شامل انتقال به `under_review`، `waitlisted`، `accepted`، `rejected` و ثبت‌نام قطعی Applicationهای پذیرفته‌شده است.
- همه شناسه‌های انتخاب‌شده پیش از اجرای Bulk به‌صورت batch از نظر Program + Tenant Scope preflight می‌شوند؛ وجود شناسه خارج از دامنه، کل درخواست را fail-closed می‌کند.
- هر ردیف از `transition_application()` یا `enroll_application()` canonical عبور می‌کند؛ Capacity، Waitlist، Eligibility، Transaction و Audit دور زده نمی‌شوند.
- رد گروهی Client confirmation و marker سروری `qys_destructive_confirmed=1` می‌خواهد.
- نتیجه Bulk به‌صورت `موفق / نامرتبط با وضعیت فعلی / ناموفق` گزارش می‌شود و اولین خطای امن نیز در صورت وجود نمایش داده می‌شود.
- فیلترهای فعال بعد از عملیات تک‌ردیفی و گروهی حفظ می‌شوند.
- Health Center کارت `Bulk applicants = selected / status+role+enrollment+search / transition+enroll / tenant / max100` دارد.
- migration جدید `20261006_033_rc44_bulk_applicant_management` متعلق به module `programs` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

تست‌های E9:

- PHP syntax: `54/54 PASS`.
- JavaScript syntax: `12/12 PASS`.
- CSS structural: `10/10 PASS`.
- E9 source/static contracts: `24/24 PASS`.
- E9 contract runtime: `9/9 PASS`.
- E9 repository runtime: `17/17 PASS`.
- E9 client runtime: `13/13 PASS`.
- Hook inventory بدون تغییر: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`.

**معیار Production E9:** Schema=`2026.10.06.2`، Ledger=`33/33`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`9`، Bulk applicants=`selected/status+role+enrollment+search/transition+enroll/tenant/max100`، Field validation و E1–E8 سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual UX Acceptance جست‌وجو/فیلتر/select-all/bulk success-partial/reject confirmation طبق سیاست پروژه به پکیج نهایی UX/E2E منتقل می‌شود.

پس از Production Health Verification E9، مرحله بعدی **Phase E / E10 — اصلاح progress پرونده** است.


# 63. تأیید Production Health Phase E / E9 — RC44

**وضعیت:** ✅ E9 Production Health Verified — Manual UX Acceptance Deferred

در Health Center تولیدی پس از نصب `1.3.1-rc44`، Schema=`2026.10.06.2`، Ledger=`33/33`، Lifecycle=`17/17`، Registry=`17/10`، Programs migrations=`9` و قرارداد `Bulk applicants = selected / status+role+enrollment+search / transition+enroll / tenant / max100` تأیید شد. Field validation، E1–E8 و Phase D بدون Regression باقی ماندند و Worker/Cron/Retention سالم بودند.

Manual UX Acceptance عملیات گروهی طبق سیاست پروژه به پکیج پذیرش نهایی منتقل شده است.

# 64. گزارش اجرای Phase E / E10 — 1.3.1-rc45

**وضعیت:** 🟨 E10 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

هدف E10 اصلاح Progress پرونده و حذف اختلاف بین درصد Client و `profile_complete` سرور است.

اصلاحات اجراشده:

- `profile_progress()` در Members، ۷ جزء canonical لازم برای کامل‌بودن پرونده را در Server محاسبه می‌کند: دانشگاه فعال، نقش عضو، نام، نام خانوادگی، کد ملی، موبایل و تاریخ تولد.
- `profile_complete()` از همان Progress canonical استفاده می‌کند؛ بنابراین Progress و Gateهای وابسته دیگر دو منبع حقیقت جدا ندارند.
- Status API فیلد `profile_progress` شامل `percent / completed / total / complete / missing` را برمی‌گرداند؛ `missing` فقط field keyهای غیرحساس است.
- درصد `100` فقط در صورت `complete=true` تولید می‌شود؛ پرونده‌ای با همه متن‌های ظاهراً پر اما دانشگاه غیرفعال دیگر `100٪` نمایش داده نمی‌شود.
- Client محاسبه مستقل بر اساس non-empty field را حذف کرده و فقط مقدار Server را render می‌کند؛ fallback محافظه‌کارانه برای پاسخ قدیمی API `100` فقط با `profile_complete=true` و در غیر این صورت `0` است.
- Progress پرونده کامل همچنان پنهان می‌شود تا فضای Home اشغال نشود.
- Health Center کارت `Profile progress = server / canonical-required / 7 / complete-only / incomplete-only` دارد.
- migration جدید `20261006_034_rc45_profile_progress` متعلق به module `members` است؛ Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.

**معیار Production E10:** Schema=`2026.10.06.2`، Ledger=`34/34`، Lifecycle=`17/17`، Registry=`17/10`، Members migrations=`5`، Profile progress=`server/canonical-required/7/complete-only/incomplete-only`، Bulk applicants و E1–E9 سالم، probes=`0`، Physical=`12/12` و failed/blocked/degraded=`0`.

Manual UX Acceptance درصد پرونده ناقص/کامل طبق سیاست پروژه به پکیج نهایی UX/E2E منتقل می‌شود.

پس از Production Health Verification E10، **Phase E از نظر کد و Health کامل می‌شود** و مرحله بعدی **Phase F / F1 — Design Tokens** است.


# 65. Maintenance Freeze — 1.3.1-rc46

**وضعیت:** 🟨 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

پس از E10، شروع Phase F بنا بر تصمیم محصول **فریز** شد تا سه مشکل جاری پیش از توسعه Design System اصلاح شوند. هیچ گام Phase F در RC46 آغاز نشده است.

## M1 — UX/UI صفحه ارسال پیام + ویرایش پیام تأییدشده

- جزئیات ۲۰۰ ردیفی گیرندگان از Preview حذف شد؛ Preview فقط یک نمونه متن نهایی + Aggregateهای کارزار را نمایش می‌دهد.
- پیام `draft / scheduled / active` فقط تا زمانی قابل ویرایش است که **همه** ردیف‌های Queue هنوز `pending` باشند.
- Edit در Transaction، Campaign copy و تمام payloadهای pending را بازسازی می‌کند.
- با خروج اولین ردیف از pending، Edit قفل می‌شود تا دو متن متفاوت در یک کارزار ارسال نشود.
- Worker بعد از Claim، payload canonical را دوباره از DB می‌خواند؛ race بین انتخاب batch و Edit متن قدیمی ایجاد نمی‌کند.
- کارزارهای سیستمی اعلان Program با `audience=program_status` از لیست انسانی ارسال هدفمند حذف شده‌اند.
- Migration: `20261007_035_rc46_targeted_message_edit`، متعلق به `targetedmessaging`.

## M2 — اعلان تغییر وضعیت متقاضی Program

- رویدادهای مدیریتی `under_review / waitlisted / accepted / rejected / enrolled` پس از موفقیت Domain Transition، اعلان را همان لحظه در Queue canonical قرار می‌دهند.
- Bulk نیز فقط برای ردیف‌های واقعاً موفق اعلان می‌سازد و پیام‌ها به تفکیک Program + University گروه‌بندی می‌شوند.
- متن‌های پنج وضعیت در خود Program قابل ویرایش‌اند. مدیرکل متن عمومی Program و نماینده دانشگاه override دانشگاه خودش را ذخیره می‌کند.
- متغیرها: `{نام}`، `{نام خانوادگی}`، `{برنامه}`، `{وضعیت}`، `{دانشگاه}`.
- فقط عضو `verified + consent` با University فعال و منطبق با snapshot درخواست وارد Queue می‌شود.
- شکست Queue باعث rollback تصمیم پذیرش نمی‌شود؛ نتیجه عملیاتی امن به مدیر گزارش می‌شود.
- Migration: `20261007_036_rc46_program_status_notifications`، متعلق به `programs`.

## مرز و معیار Production RC46

Schema فیزیکی تغییر نمی‌کند و `2026.10.06.2` باقی می‌ماند. Upgrade مستقیم از RC44 سه Migration pending (`034/035/036`) دارد و Runner ممکن است آن‌ها را در چند request متوالی کامل کند.

معیار Health:

- Ledger=`36/36`
- Lifecycle=`17/17`
- Registry=`17/10`
- Members migrations=`5`
- Programs migrations=`10`
- TargetedMessaging migrations=`1`
- `Message preview / edit` = ready
- `Program status notify` = ready
- E10 Profile progress = ready
- probes=`0` / Physical=`12/12` / failed-blocked-degraded=`0`

**بعد از RC46:** Phase F همچنان Frozen می‌ماند تا کاربر صریحاً دستور ادامه Roadmap را بدهد.


# 66. تأیید Production Health Maintenance RC46

**وضعیت:** ✅ RC46 Production Health Verified — UX Follow-up Required

در Production، Schema=`2026.10.06.2`، Ledger=`36/36`، Lifecycle=`17/17`، Registry=`17/10`، Members migrations=`5`، Programs migrations=`10` و TargetedMessaging migrations=`1` تأیید شدند. کارت‌های `Message preview / edit` و `Program status notify` نیز Ready بودند. Preview تک‌نمونه در صفحه ارسال پیام عملاً تأیید شد.

مشاهده UX نشان داد فرم Edit پیام تأییدشده هرچند امن و عملیاتی است، به‌صورت پیش‌فرض باز می‌شود و ارتفاع صفحه را دوباره زیاد می‌کند. این بدهی کوچک به RC47 منتقل شد.

# 67. Maintenance UX Follow-up — 1.3.1-rc47

**وضعیت:** 🟨 Implemented + Local/Artifact Tests PASS — Pending Production Verification

- فرم ویرایش Draft/Approved در Preview پیام هدفمند پیش‌فرض بسته شد و فقط با اقدام صریح باز می‌شود.
- CTA Summary حالت «باز کردن ویرایش / بستن ویرایش» دارد.
- Preview یک نمونه و ایمنی Transaction/pending-only/worker reload RC46 بدون تغییر است.
- Editor پیام‌های وضعیت Program نیز Disclosure بسته با CTA واضح و توضیح ۵ متن قابل ویرایش دارد؛ Quicknav «پیام‌های وضعیت» حفظ شده است.
- Health contract هر دو کارت مقدار `editor: collapsed-disclosure` را گزارش می‌کند.
- Migration جدید وجود ندارد؛ Schema=`2026.10.06.2` و Ledger=`36/36` باقی می‌مانند.
- Phase F همچنان **Frozen / Not Started** است.


# 68. Maintenance Product Correction — 1.3.1-rc48

**وضعیت:** 🟨 Implemented + Local/Artifact Tests PASS — Pending Production Verification

بازبینی محصول پس از RC47 مشخص کرد تنظیم پیام‌های وضعیت متقاضی نباید در سطح هر Program باشد. قرارداد صحیح این است که متن‌ها یک‌بار در scope پیام‌رسانی تعریف و برای همه Programها استفاده شوند.

اصلاحات:

- Template storage از `Program + University` به `Site default + University-wide override` منتقل شد.
- هیچ کلید جدید تنظیمات شامل `program_id` نیست.
- `scope_templates()` و `save_scope_templates()` API canonical هستند.
- facadeهای قدیمی Program-aware فقط برای backward compatibility باقی مانده و Program id را نادیده می‌گیرند.
- Optionهای Legacy per-Program حفظ ولی Runtime آن‌ها را ignore می‌کند؛ پاک‌سازی خودکار داده انجام نمی‌شود.
- Editor ۵ پیام از صفحه جزئیات Program حذف و به index «برنامه‌ها» منتقل شد.
- Quicknav هر Program دیگر «پیام‌های وضعیت» ندارد.
- Queue/Consent/Tenant/Delivery semantics RC46 بدون تغییر است و `{برنامه}` همچنان عنوان Program جاری را در متن سراسری جای‌گذاری می‌کند.
- Migration `20261007_037_rc48_global_program_status_templates` متعلق به Programs اضافه شد؛ Schema فیزیکی تغییر نمی‌کند.

**معیار Production RC48:** Schema=`2026.10.06.2`، Ledger=`37/37`، Programs migrations=`11`، Program status notify=`templates university+global+editable / scope all-programs / editor collapsed-disclosure`، تمام Healthهای قبلی سالم و Phase F همچنان Frozen.

RC47 به‌عنوان artifact UX تاریخی حفظ می‌شود، اما برای ادامه Production نسخه RC48 مبناست.


# 69. Maintenance Product Correction — 1.3.1-rc49

**وضعیت:** 🟨 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## تصمیم محصول

تصمیم E1/RC36 مبنی بر استقلال Membership از Messaging Consent عمداً **supersede** شد. فلسفه جاری قرآن‌یار بر اطلاع‌رسانی مستقیم عضو تکیه دارد؛ بنابراین مجوز دریافت پیام شرط لازم پرونده فعال است. تاریخچه E1 حذف نمی‌شود، اما قرارداد Runtime از RC49 به بعد `permission-first / required` است.

## اصلاحات

- عضو جدید قبل از بازشدن فرم پرونده باید `requestWriteAccess` ایتا را با موفقیت تأیید کند.
- REST تشکیل/ویرایش پرونده بدون consent فعال با `consent_required` fail-closed می‌شود؛ bypass جاوااسکریپت مسیر جایگزین ایجاد نمی‌کند.
- `profile_progress` اکنون ۸ شرط canonical دارد و `messaging_consent` یکی از آن‌هاست؛ consent خاموش ⇒ `profile_complete=false`.
- عضو موجود با consent خاموش نمی‌تواند پرونده را ویرایش کند و باید ابتدا «فعال‌سازی مجدد دریافت پیام» را انجام دهد.
- self-service «توقف دریافت پیام» از برنامک عضو حذف/مسدود شد. توقف مدیریتی مدیرکل برای suspension عملیاتی حفظ شده و در این حالت پرونده کامل/فعال تلقی نمی‌شود.
- Status API: وجود رکورد همچنان `registered=true` می‌دهد، ولی `membership_active` فقط با consent فعال true است.
- Programs access gate حالت `consent_required` را جدا از `profile_incomplete` گزارش می‌کند.
- Attendance UI، فهرست واجدان شرایط و export حضور نیز consent فعال را enforce می‌کنند.
- شمارنده `members_profile_complete` در Readiness فقط consent=1 را کامل حساب می‌کند.
- Progress server-authoritative E10 حفظ شده و Client اکنون `profile_progress` Server را در event داخلی نیز عبور می‌دهد.

## Migration

`20261007_038_rc49_required_messaging_consent` — module `members`

- code/product contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema=`2026.10.06.2`
- Ledger هدف=`38/38`
- Members migrations هدف=`6`

Migrationهای تاریخی E1 و E10 برای Fresh Install / replay با قرارداد جدید tolerant شده‌اند؛ M4/RC49 مرجع تصمیم محصول جاری است.

## معیار Production

- Schema=`2026.10.06.2`
- Ledger=`38/38`
- Lifecycle=`17/17`
- Registry=`17/10`
- Members migrations=`6`
- Programs migrations=`11`
- TargetedMessaging migrations=`1`
- Membership/messaging=`requires-messaging / required / before-profile / blocked-for-member`
- Profile progress=`server / canonical-required-plus-consent / fields8 / consent-required / complete-only`
- Runtime probes=`0` / Physical=`12/12` / failed-blocked-degraded=`0`

Phase F همچنان **Frozen / Not Started** است.


# 70. Maintenance Data Model Correction — 1.3.1-rc50

**وضعیت:** 🟨 Implemented + Local Tests PASS — Pending Production Verification

## مسئله

نقش `staff_family` به‌تنهایی مشخص نمی‌کرد فرد همسر/فرزند کدام پرسنل است و مدل single-role نمی‌توانست حالت‌های واقعی مانند «دو والد هر دو همکار» یا «فرزندی که خودش نیز همکار است» را بدون از دست‌دادن نقش سازمانی نمایش دهد.

## تصمیم معماری

`member_role` و family relationship از هم جدا شدند. جدول canonical جدید `qys_family_links` graph رابطه عضو→پرسنل را با `relation_type=spouse|child` و University Scope نگهداری می‌کند. بنابراین نقش سازمانی هر عضو مستقل باقی می‌ماند.

## قرارداد محصول

- `staff_family` بدون حداقل یک link معتبر، profile complete نیست.
- staff/faculty/student می‌توانند family link اختیاری داشته باشند.
- دو والد همکار با دو child link پشتیبانی می‌شوند.
- کارمند/استادی که خودش فرزند پرسنل است نقش اصلی خود را حفظ می‌کند.
- target باید در همان دانشگاه `verified staff/faculty` باشد.
- lookup عمومی پرسنل وجود ندارد؛ افزودن relation جدید با کد ملی دقیق است.
- رابطه در Global/Campus member list، Program applicants و Attendance people view نمایش داده می‌شود؛ CSVهای Program/Attendance نیز آن را صادر می‌کنند.
- انتقال دانشگاه relation scope مقصد را جداگانه نگه می‌دارد و پس از approval scope قدیمی عضو prune می‌شود.
- targetی که بعداً staff/faculty یا verified نباشد دیگر relation معتبر برای profile completeness ایجاد نمی‌کند.

## Schema / Migration

- Schema=`2026.10.11.1`
- Migration=`20261011_039_rc50_family_attribution` / owner=`members`
- Ledger هدف=`39/39`
- Members migrations هدف=`7`
- Physical diagnostics هدف=`13/13`

## Legacy Policy

هیچ عضو قدیمی `staff_family` به‌صورت خودکار یا حدسی به پرسنل منتسب نمی‌شود. تا ثبت relation واقعی، پرونده او ناقص می‌شود؛ داده هویتی موجود حفظ می‌شود.

## Test Result

PHP `56/56`، JS `12/12`، CSS `10/10`، static/product `50/50` و runtime family model `14/14` همگی PASS. Hook inventory=`57/6/2/1`.

Phase F همچنان **Frozen / Not Started** است.

Artifact RC50 نیز با همین مجموعه تست‌ها پس از استخراج ZIP دوباره PASS شد. SHA256 بسته نصب: `a3288a5b83e10221eef854f37893748222e671e11a7119d2807e2b7b02cd5d3f`.


# 71. Maintenance Identity Hardening — 1.3.1-rc51

**وضعیت:** 🟨 Implemented + Local Tests PASS — Pending Production Verification / IPPanel configuration

## مسئله

شماره موبایل پرونده تا RC50 فقط normalize و unique می‌شد و مالکیت واقعی شماره اثبات نمی‌شد. برای ثبت‌نام معتبر، شماره جدید باید با OTP متعلق به همان کاربر Eitaa تأیید شود.

## تصمیم محصول و معماری

- ثبت‌نام جدید بدون OTP شماره موبایل fail-closed است.
- تغییر شماره عضو موجود نیز بدون OTP fail-closed است.
- Provider: IPPanel Edge API با `Pattern SMS`. الگوی OTP از قبل در IPPanel تأیید می‌شود و QuranYar کد ۶ رقمی را Server-side تولید می‌کند.
- API key در `QYS_Token_Store` رمزگذاری می‌شود؛ sender/pattern/parameter در option غیر-autoload نگهداری می‌شوند.
- OTP خام در DB، option یا log ذخیره نمی‌شود؛ challenge فقط HMAC در transient کوتاه‌عمر است.
- TTL کد ۳۰۰ ثانیه، حداکثر ۵ تلاش، cooldown ارسال ۶۰ ثانیه، سقف ۵ درخواست/ساعت برای کاربر و ۳ درخواست/ساعت برای شماره است.
- نتیجه network-uncertain challenge را معتبر نگه می‌دارد تا اگر SMS واقعاً رسیده باشد کاربر بتواند همان کد را تأیید کند؛ provider rejection challenge را باطل می‌کند.

## Legacy policy

برای اعضای موجود هیچ ادعای جعلی OTP ساخته نمی‌شود. `mobile_verification_required=0` برای داده Legacy یعنی شماره فعلی grandfathered است؛ هنگام تغییر شماره مقدار به ۱ می‌رود و OTP اجباری می‌شود. کاربر Legacy می‌تواند همان شماره را نیز داوطلبانه OTP-verify کند و از آن پس `mobile_verified_at_utc` ثبت می‌شود.

## UX

در مرحله اطلاعات شناسایی:

1. کاربر شماره را وارد می‌کند.
2. «ارسال کد تأیید» را می‌زند.
3. کد ۶ رقمی را وارد می‌کند.
4. پس از پاسخ `verified`، ذخیره پرونده فعال است.

Client gate صرفاً UX است؛ Server در `save_profile()` همان mobile+Eitaa identity را دوباره با claim تأییدشده بررسی می‌کند.

## Schema / Migration

- Schema=`2026.10.11.2`
- Migration=`20261011_040_rc51_mobile_otp_verification` / owner=`members`
- ستون‌ها: `mobile_verified_at_utc`, `mobile_verification_required`
- Ledger هدف=`40/40`
- Members migrations هدف=`8`
- Physical diagnostics همچنان=`13/13` (جدول جدیدی افزوده نشده است).

## تنظیمات مدیرکل

در «تنظیمات و اتصال» چهار مقدار IPPanel وجود دارد: API Key، sender number، Pattern Code و نام متغیر OTP (پیش‌فرض `code`). بدون تنظیم کامل Provider، endpoint ارسال OTP با `503 otp_provider_not_configured` بسته می‌ماند.

## معیار Production

- Health Center: `Mobile OTP = ippanel / pattern-sms / registration required / change required / configured yes`.
- Schema=`2026.10.11.2`, Ledger=`40/40`, Members migrations=`8`, Physical=`13/13`.
- ثبت‌نام جدید بدون verify کد رد شود.
- کد درست همان شماره را verify کند و کد اشتباه/منقضی رد شود.
- تغییر شماره بدون OTP رد شود.
- شماره Legacy بدون تغییر همچنان قابل استفاده باشد اما «OTP verified» گزارش نشود.
- هیچ OTP خام/Token در Health/Incident log ظاهر نشود.

Phase F همچنان **Frozen / Not Started** است.


# RC52 — Maintenance: خوانایی و زبان انسانی برنامک عضو

**وضعیت:** 🟨 Implemented + Local/Artifact PASS — Pending Production UX/Health Verification  
**Schema:** `2026.10.11.2` — بدون تغییر  
**Migration Ledger:** `40/40` — Migration جدید ندارد

این RC به درخواست محصول برای اصلاح ضعف UX/UI برنامک عضو اجرا شد، بدون شروع Phase F. دامنه عمداً محدود به خوانایی، کنتراست، سلسله‌مراتب متنی و Microcopy است.

اصلاحات:

- پایه تایپوگرافی Member App به 16px افزایش یافت و متن‌های ثانویه/فشرده بزرگ‌تر شدند.
- palette متن برای کنتراست بالاتر اصلاح و رنگ‌های اصلی به‌صورت خودکار با آستانه WCAG AA بررسی شدند.
- متن Bottom Navigation، کارت‌ها، فرم، Programs و Attendance خواناتر شد.
- KPI غیرضروری Services حذف شد و وضعیت هر خدمت روی کارت خودش باقی ماند.
- نسخه فنی برنامک از UI عضو حذف شد.
- عبارت‌های ماشینی/نیمه‌فنی و خطاهای خام شبکه انسانی‌سازی شدند.
- Microcopy خانواده، OTP، پرونده، برنامه‌ها و حضور ساده‌تر و مستقیم‌تر شد.
- وزن‌های فونت Member به وزن‌های واقعاً موجود 400/500/600/700/800 محدود شدند.

**خارج از دامنه:** Design Tokens سراسری، Button System عمومی، Typography Scale کل پنل دانشگاه، Icon System و سایر Phase F همچنان Frozen هستند.

## تست‌های RC52

- PHP `57/57 PASS`
- JavaScript `12/12 PASS`
- CSS structural `10/10 PASS`
- static/readability contracts `47/47 PASS`
- targeted WCAG contrast pairs `9/9 PASS`

این نتایج Local/Artifact هستند و جای مشاهده دستی Home، Profile، Programs، Attendance، Services و More روی موبایل Production را نمی‌گیرند.

Artifact استخراج‌شده RC52 نیز PHP `57/57`، JavaScript `12/12`، CSS `10/10`، قراردادهای `47/47` و contrast checks `9/9` را PASS کرد.
**SHA256 بسته نصب RC52:** `389a9a5a4d46cfc3f89977bbe595ef3863774d332ee9c875d985e43216cfac57`


# RC53 — Maintenance: ثبت‌نام مجدد پس از انصراف Program

**وضعیت:** 🟨 Implemented + Local/Artifact Tests PASS — Pending Production Health/UX Verification  
**Schema:** `2026.10.11.2` — بدون تغییر فیزیکی  
**Migration Ledger هدف:** `41/41`  
**Programs migrations هدف:** `12`

## مسئله

در E5 امکان انصراف عضو اضافه شده بود، اما وضعیت `withdrawn` عمداً terminal بود و جداول Application/Enrollment نیز برای هر `program_id + member_id` فقط یک رکورد canonical دارند. در نتیجه Member App با وجود بازبودن ثبت‌نام فقط متن «انصراف شما ثبت شده است» را نشان می‌داد و مسیر بازگشت به فرایند ثبت‌نام وجود نداشت.

## اصلاح RC53

- قرارداد `member_reapply_contract()` و endpoint امن `POST /qys/v1/programs/reapply` اضافه شد.
- فقط رابطه‌ای که واقعاً `withdrawn` است و Program در `registration_open` قرار دارد امکان درخواست مجدد دارد.
- بازه زمانی ثبت‌نام، Eligibility، Tenant snapshot و ظرفیت دوباره Server-side بررسی می‌شوند.
- درخواست مجدد به `submitted` بازمی‌گردد؛ پذیرش/ثبت‌نام قطعی قبلی خودکار احیا نمی‌شود.
- اگر Enrollment قبلی `withdrawn` باشد، پس از پذیرش مجدد همان رکورد Enrollment به `enrolled` بازفعال می‌شود؛ رکورد موازی ساخته نمی‌شود.
- `member_reapplied` و `enrollment_reactivated` در Program Audit ثبت می‌شوند؛ Attendance history حذف یا بازنویسی نمی‌شود.
- Capacity policy اصلاح شد: انصراف قدیمی همچنان صندلی را آزاد می‌کند، اما پذیرش جدید پس از Reapply با مقایسه `decided_at_utc > withdrawn_at_utc` دوباره صندلی را رزرو می‌کند.
- Member App دکمه «ثبت‌نام مجدد» و confirmation روشن دارد. پس از Reapply وضعیت فعال Application بر Enrollment قدیمیِ withdrawn اولویت نمایشی دارد.
- درخواست Reapply در حالت `submitted/under_review/waitlisted/accepted` دوباره قابل پس‌گرفتن است؛ وجود Enrollment قدیمی withdrawn مانع Withdrawal جدید نمی‌شود.
- عملیات تک‌نفره و Bulk Enrollment مدیر، Enrollment withdrawn را پس از پذیرش جدید به‌درستی re-activate می‌کنند.

## Migration

`20261011_041_rc53_program_reapply_after_withdrawal` متعلق به module `programs` و code-contract only است؛ table/column/index جدید یا rewrite داده وجود ندارد.

## معیار Production

- Schema=`2026.10.11.2`
- Ledger=`41/41 completed`
- Programs migrations=`12`
- Physical diagnostics=`13/13`
- Health card: `Program reapply = self-service / withdrawn-only / submitted / normal-flow / rechecked / reactivate-after-accept`
- سناریوی دستی: Withdraw → نمایش «ثبت‌نام مجدد» → Reapply → Submitted → Accept → Enroll → Active.
- سناریوی ظرفیت: Withdrawal ظرفیت را آزاد کند؛ Acceptance جدید دوباره همان یک صندلی را رزرو کند.
- هیچ Attendance history یا Audit قبلی حذف نشود.

Phase F همچنان **Frozen / Not Started** است.


# RC54 — Maintenance: Program POST Routing & Redirect Safety

**وضعیت:** 🟨 Implemented + Local/Artifact Tests PASS — Pending Production Verification  
**Schema:** `2026.10.11.2` — بدون تغییر  
**Migration Ledger هدف:** `42/42`  
**Programs migrations هدف:** `13`

## مسئله Production

پس از تغییر وضعیت متقاضی Program از پنل دانشگاه، خود transition در دیتابیس انجام می‌شد اما در برخی درخواست‌ها صفحه به URL Programs برمی‌گشت و Main سفید می‌ماند؛ فقط Header/Shell پنل قابل مشاهده بود.

## علت فنی

POSTهای Programs تا داخل `QYS_Programs_Module::page()` پردازش می‌شدند. در standalone portal، template قبل از `do_shortcode()` بخشی از HTML را چاپ می‌کند. بنابراین PRG redirect پس از شروع render اجرا می‌شد و به buffering/headers محیط وابسته بود.

## اصلاح

- Portal POSTها روی `template_redirect` قبل از render پردازش می‌شوند.
- Global wp-admin POSTها روی `admin_init` پردازش می‌شوند.
- `page()` read/render-only شد و دیگر mutation/redirect در render ندارد.
- redirect context شامل `program_id`، applicant filters و anchor حفظ می‌شود.
- fallback `headers_sent` از blank shell جلوگیری می‌کند.
- notification status پیام secondary و fail-soft است؛ exception غیرمنتظره Queue بعد از commit transition نباید UI را بشکند.

## ماتریس وضعیت

مسیرهای `under_review / waitlisted / accepted / rejected / enrolled` و Bulk متناظر بررسی شدند و همگی از boundary جدید pre-render استفاده می‌کنند.

## Migration

`20261011_042_rc54_program_pre_render_post_redirect` / owner=`programs` / code-contract only.

## معیار Production

- Schema=`2026.10.11.2`
- Ledger=`42/42`
- Programs migrations=`13`
- Physical=`13/13`
- Health: `Program action routing = template-redirect / admin-init / pre-render / fail-soft`
- تغییر وضعیت متقاضی نباید صفحه سفید ایجاد کند.

Phase F همچنان **Frozen / Not Started** است.
