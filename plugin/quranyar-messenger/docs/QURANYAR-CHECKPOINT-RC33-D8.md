# چک‌پوینت پروژه قرآن‌یار — Phase D / D8 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc33`  
**Schema:** `2026.10.06.1` — بدون تغییر نسبت به RC32  
**مبنای ارتقا:** RC32 / Migration Ledger `21/21` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D8 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D8

خروجی‌های CSV نباید گزارش کامل را ابتدا در حافظه جمع کنند یا به‌علت سقف داخلی، بخشی از داده را بی‌صدا حذف کنند. D8 یک مرز مشترک برای **streaming export با حافظه محدود** ایجاد می‌کند و مسیرهای اصلی خروجی را به chunkهای ثابت و cursor/keyset تبدیل می‌کند.

این مرحله مجوزها، Tenant Scope، masking اطلاعات حساس، CSV Injection protection یا semantics داده را تغییر نمی‌دهد.

## 2. سرویس مشترک Batch Export

کلاس جدید:

`QYS_Batch_Export`

قرارداد:

- mode = `streaming`
- chunk size = `250`
- cursor = `keyset`
- memory = `bounded`
- UTF-8 BOM و CSV serialization از یک boundary مشترک
- `Cache-Control: no-store` و `X-Content-Type-Options: nosniff`
- flush در پایان هر chunk

از RC33 به بعد `fputcsv` و `php://output` فقط در همین سرویس مشترک قرار دارند؛ Featureها فقط row shaping و مجوز خودشان را مدیریت می‌کنند.

## 3. Program CSV

مسیر Program Export دیگر:

`applications_for_program(..., 1000, ...)`

را استفاده نمی‌کند؛ بنابراین سقف خاموش ۱۰۰۰ متقاضی حذف شده است.

Repository جدید:

`QYS_Program_Repository::applications_export_chunk()`

ویژگی‌ها:

- keyset بر اساس `application.id`
- بدون `OFFSET`
- Tenant scope داخل هر query
- هر chunk حداکثر ۲۵۰ Application
- Answers فقط برای Applicationهای همان chunk به‌صورت batch خوانده می‌شوند
- Form fields یک‌بار در آغاز خروجی خوانده می‌شوند

## 4. Excel Follow-up CSV

Export گزارش افراد ارسال‌نشده دیگر `followup_rows()` کامل را پیش از شروع دانلود در حافظه نمی‌سازد.

دو منبع به‌ترتیب stream می‌شوند:

1. ردیف‌های import-time exclusions از `excel_followup` با keyset `id`
2. Queueهای غیر `sent` با `QYS_Campaign_Repository::non_sent_rows_after()`

اطلاعات تماس اعضا فقط برای همان chunk Queue batch fetch می‌شود. مجوز `sensitive_identity` و scope کارزار بدون تغییر باقی مانده است.

## 5. Attendance CSV

Attendance که قبلاً chunk داشت، از OFFSET pagination به keyset منتقل شد:

`QYS_Member_Repository::attendance_export_chunk_after()`

- cursor = `member_id`
- chunk = 250
- University/Role scope در هر query
- masking کد ملی/موبایل بر اساس permission قبلی بدون تغییر

متد OFFSET قبلی برای backward compatibility حذف نشده است اما مسیر Export جدید آن را مصرف نمی‌کند.

## 6. Birthday Archive CSV

آرشیو تولد نیز از OFFSET export به keyset نزولی `id` منتقل شد و از boundary مشترک CSV استفاده می‌کند.

سقف امنیتی/عملیاتی موجود `5000` رکورد برای این آرشیو **عمداً حفظ شده است**؛ D8 این Policy را تغییر نمی‌دهد. Audit دسترسی و CSV-safe identifierها نیز بدون تغییر باقی مانده‌اند.

## 7. Health Center و Migration

کارت جدید:

`Batch export`

انتظار:

`mode: streaming | chunk: 250 | cursor: keyset | memory: bounded`

Migration جدید:

`20261006_022_rc33_batch_export`

ویژگی‌ها:

- متعلق به lifecycle module `core`
- performance/code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.1`
- Ledger مورد انتظار پس از نصب: `22/22 completed`
- Core migration count پس از RC33 = `3`

## 8. تست‌های اجراشده

- PHP syntax: `53/53 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D8 source/static contracts: `17/17 PASS`
- Batch export helper runtime: `10/10 assertions PASS`
  - contract streaming/keyset/bounded
  - chunk size = 250
  - UTF-8 BOM/header/row serialization
- Repository keyset runtime: `16/16 assertions PASS`
  - Program cursor/scope/order/limit و عدم OFFSET
  - Campaign cursor/status/order/limit و عدم OFFSET
  - Attendance cursor/tenant/role/order/limit و عدم OFFSET
- D8 migration contract: `PASS`
- Hook call-site parity RC32 → RC33: `55 actions / 6 filters / 2 shortcodes / 1 activation hook` = exact PASS
- serialization boundary check: `fputcsv` و `php://output` فقط در `QYS_Batch_Export`
- D7/D6/D5 و قراردادهای Performance قبلی در source regression حفظ شده‌اند.

تمام موارد بالا پس از ساخت ZIP نهایی دوباره روی Artifact استخراج‌شده اجرا می‌شوند.

## 9. خارج از دامنه D8

عمداً انجام نشده‌اند:

- D9: Birthday index
- D10: conditional asset loading عمومی
- تغییر Permission یا Tenant policy خروجی‌ها
- تغییر سقف ۵۰۰۰ رکورد آرشیو تولد
- async/background export job برای گزارش‌های بسیار عظیم
- UX/UI feature جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 10. نصب روی Production

RC32 را با RC33 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 022 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.1`
- Migration Ledger = `22/22 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Core migration count = `3`
- Programs migration count = `2`
- Members migration count = `2`
- Messaging migration count = `2`
- Health migration count = `1`
- Batch export = `mode: streaming | chunk: 250 | cursor: keyset | memory: bounded`
- Health snapshot = `heartbeat: lightweight | full: 15m/on-demand`
- Queue dispatch index = `(status,id): ready | pending scan: indexed`
- Queue throughput = `mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Home summary = `endpoint: lightweight | programs: compact | forms: deferred`
- Mini-app feature load = `programs: lazy | attendance: lazy | data: on_view`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker/Cron/Retention سالم

## 11. Rollback

D8 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC32 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 022 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D9 — Birthday index»**
