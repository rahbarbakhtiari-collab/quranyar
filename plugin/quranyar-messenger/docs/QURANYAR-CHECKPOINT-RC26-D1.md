# چک‌پوینت پروژه قرآن‌یار — Phase D / D1 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc26`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC25  
**مبنای ارتقا:** RC25 / Migration Ledger `14/14` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D1 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D1

حذف `SHOW TABLES / SHOW COLUMNS / SHOW INDEX` از مسیر عادی Runtime و جایگزینی readiness پرتکرار با gate ارزان مبتنی بر **Schema Version + Migration Ledger**.

Physical introspection فقط در دو مسیر مجاز باقی می‌ماند:

- Installer / Migration که واقعاً باید ساختار فیزیکی را بررسی کند.
- Health Center، آن هم به‌صورت Diagnostic صریح و on-demand.

این مرحله business workflow، Schema فیزیکی و داده دامنه را تغییر نمی‌دهد.

## 2. QYS_Schema_Runtime — Runtime Readiness Gate

کلاس جدید:

`QYS_Schema_Runtime`

منبع runtime readiness اکنون این قرارداد است:

- `qys_install_state = ready`
- `qys_schema_version = QYS_Config::SCHEMA_VERSION`
- Migration Ledger initialized/ready

نتیجه در سطح request cache می‌شود. مسیر Runtime برای readiness دیگر هیچ `SHOW*` query اجرا نمی‌کند.

Telemetry بدون PII نیز ارائه می‌شود:

- mode = `version_ledger`
- runtime checks
- diagnostic query count

## 3. QYS_Table_Names — تفکیک Runtime از Diagnostic

`QYS_Table_Names` اکنون دو مرز صریح دارد:

- `runtime_exists()` — فقط Runtime gate؛ بدون introspection دیتابیس
- `diagnostic_*()` — بررسی فیزیکی table / column / index با cache در همان request

همه `SHOW TABLES`, `SHOW COLUMNS`, `SHOW INDEX`های سورس در یک نقطه Diagnostic متمرکز شده‌اند.

`exists()` تاریخی فقط compatibility alias برای diagnostic باقی مانده و runtime داخلی دیگر از آن استفاده نمی‌کند.

## 4. Repository / Domain Readiness

مسیر عادی `schema_ready()` یا table readiness در این مرزها به Runtime gate منتقل شد:

- MemberRepository
- CampaignRepository
- UniversityRepository
- ProgramRepository
- Multitenant
- CampusWelcome
- CampusBirthday
- Welcome / Activation log readiness
- Attendance
- RecurringAttendance
- RetentionService
- SpreadsheetMessaging
- Support helpers

در کنار آن `diagnostic_schema_ready()` یا diagnostic table contract برای Installer/Health نگه داشته شده است.

## 5. Installer / Migration Safety

D1 physical verification را حذف نکرده است.

Fresh install، `dbDelta`، backfillهای migration و migrationهای تاریخی که باید table/column واقعی را بررسی کنند از diagnostic contract استفاده می‌کنند. بنابراین runtime سریع شده، ولی installer به «فرض وجود schema» تبدیل نشده است.

برای حفظ C5، هیچ reference معکوس Programs → Attendance ایجاد نشده است. Diagnostic مربوط به Attendance نیز از همان `QYS_Program_Attendance_Port` و Adapter عبور می‌کند.

## 6. Health Center

Health Center دو سیگنال جدید دارد:

### Runtime schema gate

انتظار:

`mode: version_ledger | probes: 0`

این کارت نشان می‌دهد مسیر عادی runtime به schema probe فیزیکی متکی نیست.

### Schema diagnostics

Health Center در بازدید صریح مدیر deep diagnostic فیزیکی را اجرا می‌کند و انتظار می‌رود:

`Physical: 12 / 12`

این diagnostic از worker heartbeat و snapshotهای پرتکرار جداست و فقط در صفحه Health اجرا می‌شود.

## 7. Migration D1

Migration جدید:

`20261006_015_rc26_runtime_schema_guard`

ویژگی‌ها:

- Infrastructure-only
- متعلق به lifecycle module `core`
- بدون table/column جدید
- بدون rewrite داده دامنه
- readiness قرارداد جدید Runtime/Diagnostic را پیش از complete شدن تأیید می‌کند
- Ledger مورد انتظار: `15/15 completed`
- Schema همچنان `2026.10.05.7`

پس از RC26، migration count ماژول `core` برابر `2` خواهد بود (C4 + D1).

## 8. تست‌های اجراشده

### Work Tree

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D1 source/static contracts: `27/27 PASS`
- Runtime schema guard: `10 assertions PASS`
  - runtime `SHOW*` queries = `0`
  - diagnostic cached queries = `1`
- Module Registry/Lifecycle runtime: `34 assertions PASS`
  - modules `17/17`
  - features `10`
  - module-registry migrations `1`
  - core migrations `2`
- RC25 → RC26 Migration regression: `17 assertions PASS`
  - Ledger `14/15 → 15/15`
- Full plugin boot smoke: `20 assertions PASS`
  - modules `17/17`
  - actions `53`
  - filters `4`
  - shortcodes `2`
  - activation hooks `1`
- C6 source/static contracts: `35/35 PASS`
- C6 Repository runtime: `15 assertions PASS`
- C5 regressions: `12 + 10 + 7 + 6 assertions PASS`
- Hook parity RC25 → RC26:
  - actions `53 = 53 PASS`
  - filters `4 = 4 PASS`
  - shortcodes `2 = 2 PASS`
  - activation hooks `1 = 1 PASS`

تمام موارد بالا روی ZIP نهایی استخراج‌شده نیز دوباره اجرا و در Test Report ثبت شده‌اند.

## 9. خارج از دامنه D1

عمداً انجام نشده‌اند:

- D2: اصلاح N+1های Programs
- D3: Home Summary endpoint سبک
- D4: Lazy load Programs/Attendance در برنامک
- D5: Queue index `(status,id)`
- D6: Queue throughput adaptive
- D7: Health snapshot سبک‌تر
- D8: Batch export
- D9: Birthday index
- D10: conditional asset loading
- UX/UI یا Feature محصولی جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 10. نصب روی Production

RC25 را با RC26 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 015 تغییر فیزیکی دیتابیس ندارد و automatic runner باید آن را روی اولین درخواست عادی اجرا کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `15/15 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Core: Migration count = `2`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed = `0`
- blocked = `0`
- degraded = `0`
- Worker/Cron/Retention سالم
- Queue بدون وضعیت غیرعادی

## 11. Rollback

D1 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC25 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 015 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D2 — اصلاح N+1های Programs»**
