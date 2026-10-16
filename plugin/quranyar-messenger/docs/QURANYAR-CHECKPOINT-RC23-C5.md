# چک‌پوینت پروژه قرآن‌یار — Phase C / C5 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc23`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC22  
**مبنای ارتقا:** RC22 / Migration Ledger `11/11` / Module Lifecycle `17/17`  
**وضعیت:** C5 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف C5

شکستن dependency cycleهای اصلی بدون تغییر business workflow:

- Programs ↔ Attendance
- Permission ↔ Targeted
- Security ↔ Multitenant

اصل این مرحله این است که dependency direction یک‌طرفه و قابل مشاهده در Module Lifecycle باشد؛ C5 هنوز Repository ownership کامل یا Module Registry نهایی را انجام نمی‌دهد.

## 2. Programs → Attendance حذف شد

مرز جدید:

- `QYS_Program_Attendance_Port`
- `QYS_Program_Attendance_Boundary`
- `QYS_Attendance_Program_Adapter`

اکنون Programs برای عملیات cross-feature فقط contract هسته را می‌شناسد. implementation واقعی در Attendance ثبت می‌شود.

عملیات عبوری از این مرز:

- terminalize کردن Attendance هنگام پایان/لغو Program
- ایجاد Attendance Program متصل
- خواندن Attendance Program برای UI
- summary حضور عضو در Member Record
- backfill سازگار برنامه‌های حضور legacy

در مقابل، Attendance همچنان می‌تواند Program policy/repository را مصرف کند؛ بنابراین جهت dependency صریحاً:

`Attendance → Programs`

است، نه دوطرفه.

در Module Lifecycle نیز Attendance اکنون به `programs` وابستگی اعلام‌شده دارد. شکست Programs باعث `blocked` شدن Attendance می‌شود، اما شکست Attendance نباید Programs را از Boot خارج کند.

## 3. Permission → Targeted حذف شد

`QYS_Permission_Service` دیگر implementation کلاس `QYS_Targeted` را نمی‌شناسد.

متادیتای کارزار Targeted از طریق storage boundary در `QYS_Campaign_Repository` خوانده می‌شود:

- `targeted_meta_table()`
- `targeted_meta_ready()`
- `targeted_meta()`
- `targeted_visible_in_scope()`

`QYS_Targeted` نیز table/schema contract خود را به همان boundary delegate می‌کند.

جهت dependency اکنون:

`Targeted → Permission → Repository`

است.

## 4. Security → Multitenant حذف شد

کلیدهای canonical زیر به `QYS_Config` منتقل شدند:

- owner option
- portal page option
- home page option
- mini-app page option
- entry slugs

`QYS_Security_Service` برای تشخیص مدیرکل و محافظت صفحات عمومی دیگر به `QYS_Multitenant` وابسته نیست.

ثابت‌های قدیمی `QYS_Multitenant` به‌عنوان alias سازگار به `QYS_Config` باقی مانده‌اند تا قراردادهای موجود شکسته نشوند.

جهت dependency اکنون Multitenant می‌تواند Security را مصرف کند، ولی Security به implementation Multitenant بازنمی‌گردد.

## 5. Migration C5

Migration جدید:

`20261006_012_rc23_dependency_cycle_break`

ویژگی‌ها:

- Infrastructure-only
- بدون جدول/ستون جدید
- بدون rewrite داده دامنه
- متعلق به lifecycle module
- readiness contract مرز جدید را تأیید می‌کند
- Ledger مورد انتظار پس از نصب: `12/12 completed`
- Schema همچنان `2026.10.05.7`

## 6. تست‌های اجراشده

- PHP syntax: `47/47 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- C5 source/static contracts: `28/28 PASS`
- Program/Attendance boundary runtime: `11 assertions PASS`
- Attendance adapter runtime/backfill: `10 assertions PASS`
- Permission/Targeted authorization runtime: `7 assertions PASS`
- Security/global-owner runtime: `6 assertions PASS`
- Module lifecycle runtime: `15 assertions PASS` و `17/17 modules booted`
- Migration regression RC22→RC23: `19 assertions PASS` و Ledger `11/12 → 12/12`
- Full plugin boot smoke: `15 assertions PASS` و `17/17 modules booted`
- Hook parity RC22→RC23:
  - actions `53 = 53 PASS`
  - filters `4 = 4 PASS`
  - shortcodes `2 = 2 PASS`
  - activation hooks `1 = 1 PASS`
- C4 helper behavior parity: `PASS`

## 7. خارج از دامنه C5

عمداً انجام نشده‌اند:

- C6: Repository ownership کامل
- C7: FeatureRegistry → Module Registry نهایی
- cycle مربوط به FeatureRegistry ↔ Multitenant که با C7 جمع می‌شود
- Phase D Performance
- UX/UI یا Feature محصولی جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 8. نصب روی Production

RC22 را با RC23 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 012 تغییر فیزیکی دیتابیس ندارد و automatic runner باید آن را روی اولین درخواست عادی اجرا کند.

پس از نصب، Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `12/12 completed`
- Upgrade gate = آماده
- Module lifecycle = `17/17 booted`
- در ردیف Attendance، dependencyهای Boot شامل `programs` باشد
- failed = 0
- blocked = 0
- degraded = 0
- Worker/Cron/Retention سالم
- Queue بدون وضعیت غیرعادی

## 9. Rollback

C5 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC22 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 012 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase C / C6 — Repository Ownership»**
