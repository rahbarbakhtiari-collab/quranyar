# چک‌پوینت پروژه قرآن‌یار — Phase E / E9 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc44`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC43  
**مبنای ارتقا:** RC43 / Migration Ledger `32/32` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E9 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E9

بستن `QYS-P2-30 — مدیریت متقاضیان عملیات گروهی ندارد`، بدون تغییر قواعد دامنه Program و بدون تضعیف مرز Tenant.

## 2. جست‌وجو و فیلتر متقاضیان

در بخش «متقاضیان و ثبت‌نام‌ها» فیلترهای Server-side اضافه شده‌اند:

- جست‌وجوی نام / نام خانوادگی؛
- وضعیت Application؛
- گروه عضو؛
- داشتن / نداشتن Enrollment.

جست‌وجوی عمومی عمداً فقط نام را پوشش می‌دهد و `national_id` یا `mobile` را وارد سطح جست‌وجوی عمومی نمی‌کند.

Repository جدید `applications_for_program_filtered()` همه شروط را داخل query و با Tenant Scope جاری اعمال می‌کند. سقف نمای فعلی همچنان 500 ردیف است و E9 pagination عمومی اضافه نمی‌کند.

## 3. Bulk management

قرارداد E9:

`mode: selected | filters: status+role+enrollment+search | actions: transition+enroll | scope: tenant | result: partial-summary | max: 100 | destructive: explicit`

مدیر می‌تواند حداکثر 100 متقاضی انتخاب‌شده را در هر اجرا برای این عملیات پردازش کند:

- انتقال به `under_review`؛
- انتقال به `waitlisted`، فقط وقتی Waitlist برنامه فعال است؛
- انتقال به `accepted`؛
- انتقال به `rejected`؛
- ثبت‌نام قطعی Applicationهای پذیرفته‌شده و بدون Enrollment.

## 4. Safety و Tenant boundary

قبل از اجرای هر Bulk:

- شناسه‌ها sanitize، deduplicate و به 100 محدود می‌شوند؛
- `applications_in_scope_by_ids()` همه ردیف‌ها را به‌صورت batch با `program_id + tenant scope` می‌خواند؛
- اگر حتی یک شناسه انتخاب‌شده خارج از Program/Tenant باشد، کل درخواست `403 fail-closed` می‌شود؛
- Programهای `completed/cancelled/archived` عملیات Bulk نمی‌پذیرند؛
- Bulk waitlist وقتی Waitlist خاموش است متوقف می‌شود؛
- Bulk accept قبل از اجرا ظرفیت باقی‌مانده را با تعداد ردیف‌های واقعاً قابل پذیرش مقایسه می‌کند و اگر ظرفیت کافی نباشد، قبل از هر تغییر متوقف می‌شود.

پس از preflight، هر ردیف همچنان از مسیر canonical زیر عبور می‌کند:

- `QYS_Program_Service::transition_application()` یا
- `QYS_Program_Service::enroll_application()`.

بنابراین Eligibility، Capacity، Waitlist، Transaction، Audit و concurrent conflict checks موجود دور زده نمی‌شوند.

## 5. UX عملیات گروهی

- Checkbox انتخاب ردیف‌ها و «انتخاب همه ردیف‌های نمایش‌داده‌شده» اضافه شد؛ Select-all بیش از 100 ردیف انتخاب نمی‌کند.
- شمارنده انتخاب زنده است.
- Bulk reject دارای confirmation صریح Client و marker سروری `qys_destructive_confirmed=1` است.
- submit بدون انتخاب، بدون action یا بیش از limit در Client متوقف می‌شود و Server نیز همه قواعد را دوباره enforce می‌کند.
- فیلترهای فعال بعد از عملیات تک‌ردیفی و گروهی حفظ می‌شوند.
- نتیجه عملیات به سه شمارنده `موفق / نامرتبط با وضعیت فعلی / ناموفق` تبدیل می‌شود؛ اولین خطای امن نیز در صورت وجود نمایش داده می‌شود.

## 6. Health Center و Migration

کارت جدید:

`Bulk applicants`

انتظار:

`mode: selected | filters: status+role+enrollment+search | actions: transition+enroll | scope: tenant | max: 100`

Migration جدید:

`20261006_033_rc44_bulk_applicant_management`

ویژگی‌ها:

- متعلق به lifecycle module `programs`؛
- UX/workflow/repository contract only؛
- بدون table/column/index جدید؛
- بدون rewrite داده دامنه؛
- Schema همچنان `2026.10.06.2`؛
- Ledger مورد انتظار پس از نصب: `33/33 completed`؛
- Programs migration count پس از RC44 = `9`.

## 7. تست‌های اجراشده

### Work Tree

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `12/12 PASS`
- CSS structural: `10/10 PASS`
- E9 source/static contracts: `25/25 PASS`
- E9 contract runtime: `9/9 PASS`
- E9 repository runtime: `17/17 PASS`
- E9 client runtime: `13/13 PASS`
- E8 regression contract: `8/8 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`

همین syntax/static/runtime تست‌ها روی ZIP نهایی استخراج‌شده دوباره اجرا می‌شوند.

## 8. خارج از دامنه E9

عمداً انجام نشده‌اند:

- E10: اصلاح progress پرونده؛
- responsive card layout کامل جدول متقاضیان (Phase F / QYS-P2-33)؛
- pagination عمومی متقاضیان؛
- bulk action روی Enrollment statusهای شرکت (`participating/completed/...`)؛
- تغییر Permission/Tenant/Capacity policy؛
- تغییر physical schema؛
- Live Edge Acceptance Deferred مربوط به Phase A/B.

## 9. نصب روی Production

RC43 را با RC44 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 033 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `33/33 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `9`
- Members migration count = `4`
- Bulk applicants = `mode: selected | filters: status+role+enrollment+search | actions: transition+enroll | scope: tenant | max: 100`
- Field validation و Form draft سالم
- Async states و E1–E8 سالم
- Runtime schema probes = `0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention سالم

## 10. Manual UX Acceptance

طبق سیاست پروژه، تست دستی جست‌وجو، فیلترها، select-all، Bulk success/partial، capacity guard و reject confirmation اکنون درخواست نمی‌شود و در پکیج پذیرش نهایی UX/E2E انجام خواهد شد.

## 11. Rollback

E9 هیچ Schema فیزیکی یا rewrite داده‌ای ندارد.

Rollback فایل به RC43 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 033 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از Production Health Verification

**Phase E / E10 — اصلاح progress پرونده**
