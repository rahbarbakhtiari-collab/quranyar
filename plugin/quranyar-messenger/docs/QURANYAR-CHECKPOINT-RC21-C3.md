# چک‌پوینت پروژه قرآن‌یار — Phase C / C3 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc21`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC20  
**مبنای ارتقا:** RC20 / Migration Ledger `9/9`  
**وضعیت:** C3 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف C3

ایجاد یک قرارداد Lifecycle واقعی برای ماژول‌های قرآن‌یار، بدون ورود هم‌زمان به Refactorهای بعدی Phase C.

از RC21، هر ماژول runtime باید از قرارداد واحد زیر عبور کند:

- شناسه ماژول
- وابستگی‌های Boot
- Critical / Optional
- Migrationهای متعلق به ماژول
- Boot
- Health

## 2. قرارداد و Module Manager

دو جزء جدید اضافه شده‌اند:

- `QYS_Module_Contract`
- `QYS_Module_Manager`

برای سازگاری با معماری موجود، `QYS_Callback_Module` به‌عنوان Adapter استفاده می‌شود تا کلاس‌های فعلی بدون Rewrite بزرگ وارد Lifecycle شوند.

Module Manager:

- همه ماژول‌ها را قبل از Boot ثبت می‌کند.
- Migrationهای متعلق به ماژول‌ها را قبل از `init` وردپرس در Ledger ثبت می‌کند.
- Boot را با توجه به dependencyهای اعلام‌شده انجام می‌دهد.
- خرابی ماژول غیرحیاتی را در همان ماژول ایزوله می‌کند.
- ماژول وابسته به ماژول failed را به `blocked` می‌برد، بدون Boot کورکورانه.
- خطای ماژول حیاتی را fail-closed تلقی می‌کند.
- پیام خام Exception یا PII را وارد telemetry نمی‌کند.

## 3. ماژول‌های ثبت‌شده

RC21 دارای ۱۷ ماژول Lifecycle است.

زیرساخت/هسته حیاتی:

- lifecycle
- installer
- upgrade
- security
- core
- health
- universities
- members
- messaging
- feature-navigation

ماژول‌های غیرحیاتی و قابل ایزوله:

- retention
- birthday
- welcome
- targetedmessaging
- programs
- attendance
- spreadsheetmessaging

## 4. حفظ Feature Registry فعلی

C3 عمداً `QYS_Feature_Registry` را به Module Registry نهایی تبدیل نمی‌کند؛ این کار مرحله بعدی مربوط به انتهای Phase C است.

فقط یک Compatibility Bridge اضافه شده است:

- Feature وابسته به ماژول `failed/blocked` در Navigation نمایش داده نمی‌شود.
- اگر مستقیماً به section آن Feature مراجعه شود، پیام «موقتاً در دسترس نیست» نمایش داده می‌شود.
- Featureهای سالم و مستقل همچنان کار می‌کنند.

بنابراین Fault Isolation در سطح محصول اعمال می‌شود، بدون تغییر ناگهانی قرارداد Navigation موجود.

## 5. Migration C3

Migration جدید:

`20261006_010_rc21_module_lifecycle`

ویژگی‌ها:

- Infrastructure-only
- بدون تغییر فیزیکی جدول یا داده دامنه
- متعلق به lifecycle module
- پیش از اجرای automatic Upgrade Coordinator در Ledger ثبت می‌شود.
- پس از RC20 انتظار می‌رود Ledger از `9/9` به `10/10` برسد.
- Schema دامنه همچنان `2026.10.05.7` باقی می‌ماند.

`QYS_Migration_Ledger::status().current_id` نیز از این نسخه آخرین تعریف واقعی Ledger را به‌صورت پویا گزارش می‌کند؛ بنابراین با Migrationهای Module-owned آینده همگام می‌ماند.

## 6. Health Center

Health Center اکنون علاوه بر Migration/Upgrade، وضعیت Lifecycle را نیز گزارش می‌کند:

- total modules
- booted
- failed
- blocked
- critical failed
- degraded health

یک جدول `Module Lifecycle` نیز وضعیت هر ماژول، Critical بودن، dependencyهای Boot، تعداد Migration، Health و error code امن را نمایش می‌دهد.

کدهای جدید Incident:

- `module_critical_failed` — بحرانی
- `module_degraded` — هشدار

## 7. تست‌ها

- PHP syntax: `39/39 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- C3 source/static contracts: `17/17 PASS`
- Module lifecycle runtime assertions: `14 PASS`
- Critical dependency fail-closed: `PASS`
- Plugin include/init boot smoke: `17/17 modules booted`
- Migration C1/C2/C3 + automatic RC20→RC21 regression: `13 assertions PASS`
- Legacy Hook Registration Parity RC20→RC21:
  - actions: `53 = 53 PASS`
  - filters: `4 = 4 PASS`
  - shortcodes: `2 = 2 PASS`
  - activation hooks: `1 = 1 PASS`
- تمام فایل‌های Domain خارج از Core lifecycle integration نسبت به RC20 دست‌نخورده مانده‌اند.

## 8. خارج از دامنه C3

عمداً انجام نشده‌اند:

- C4: استخراج Config / TokenStore / TableNames / TextNormalizer / CalendarService و Bootstrap Facade از `QYS_Messenger`
- C5: شکستن cycleهای Programs ↔ Attendance، Permission ↔ Targeted و Security ↔ Multitenant
- C6: Repository ownership کامل
- C7: تبدیل FeatureRegistry به Module Registry نهایی
- Phase D Performance
- Feature محصولی جدید

بنابراین C3 «قرارداد و هماهنگ‌کننده Lifecycle» را می‌سازد، نه Refactor هم‌زمان کل معماری را.

## 9. نصب روی Production

RC20 را با RC21 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 010 تغییر فیزیکی دیتابیس ندارد. اولین درخواست عادی WordPress باید automatic runner را اجرا کند.

پس از نصب، در Health Center انتظار می‌رود:

- Schema = `2026.10.05.7`
- Migration Ledger = `10/10 completed`
- Upgrade gate = آماده
- Module lifecycle = `17/17 booted`
- failed = 0
- blocked = 0
- degraded = 0
- Worker/Cron/Retention سالم

## 10. Rollback

C3 هیچ داده دامنه یا Schema فیزیکی را تغییر نمی‌دهد.

Rollback فایل به RC20 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 010 می‌تواند در Ledger باقی بماند و برای RC20 فقط یک رکورد تاریخی تکمیل‌شده خواهد بود.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase C / C4 — Core Service Extraction»**
