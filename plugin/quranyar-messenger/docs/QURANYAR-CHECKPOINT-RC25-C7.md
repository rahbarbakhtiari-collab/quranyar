# چک‌پوینت پروژه قرآن‌یار — Phase C / C7 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc25`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC24  
**مبنای ارتقا:** RC24 / Migration Ledger `13/13` / Module Lifecycle `17/17`  
**وضعیت:** C7 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف C7

بستن آخرین کار Phase C: تبدیل Registry قابلیت‌های پنل از یک Navigation Registry مستقل به **Module Registry واقعی**؛ به‌گونه‌ای که هر قابلیت محصولی مالک Lifecycle مشخص داشته باشد و metadata قابلیت همراه همان ماژول ثبت شود.

هم‌زمان cycle باقی‌مانده Registry ↔ Multitenant باید جمع شود، بدون تغییر business workflow و بدون تغییر Schema دامنه.

## 2. QYS_Module_Registry — منبع canonical

کلاس جدید:

`QYS_Module_Registry`

اکنون منبع canonical برای این دو دسته است:

- ماژول‌های Lifecycle
- Feature metadata پنل دانشگاه

`QYS_Module_Manager` دیگر آرایه مستقل module object نگه نمی‌دارد و برای register/list/lookup ماژول‌ها از Registry استفاده می‌کند.

هر `QYS_Module_Contract` علاوه بر dependency / critical / migrations / boot / health اکنون `features()` نیز دارد.

## 3. مالکیت Featureها توسط ماژول‌ها

۱۰ Feature موجود به مالک واقعی خود منتقل شدند:

- `universities`: home + more
- `members`: members
- `messaging`: messages
- `programs`: programs
- `attendance`: attendance
- `targetedmessaging`: targeted
- `spreadsheetmessaging`: excel
- `birthday`: birthday
- `welcome`: welcome

Module Registry هنگام register ماژول، شناسه Feature، section، permission، handler، nav group/order، docs/tests و مالک ماژول را validate و ثبت می‌کند.

## 4. حذف FeatureRegistry از Runtime داخلی

`QYS_Feature_Registry` دیگر منبع حقیقت یا بخشی از wiring داخلی نیست.

برای backward compatibility فقط یک Facade نازک باقی مانده که به Registry/Portal Feature Service delegate می‌کند؛ هیچ فایل runtime داخلی قرآن‌یار به `QYS_Feature_Registry` وابسته نیست.

ماژول تاریخی `feature-navigation` نیز حذف و با ماژول حیاتی `module-registry` جایگزین شد. تعداد Lifecycle moduleها همچنان `17` است.

## 5. شکستن cycle Registry ↔ Multitenant

Registry جدید pure metadata است و به این implementationها dependency ندارد:

- `QYS_Multitenant`
- `QYS_Permission_Service`
- `QYS_Module_Manager`

مرز جدید:

`QYS_Portal_Feature_Service`

مسئولیت آن:

- اعمال permission برای کاربر جاری
- فیلتر Featureهای قابل مشاهده
- بررسی Boot availability ماژول مالک
- dispatch امن Feature

جهت dependency اکنون:

`Multitenant / Portal → PortalFeatureService → ModuleRegistry + PermissionService + ModuleManager`

است؛ Registry به Multitenant بازنمی‌گردد.

## 6. Permission vocabulary

واژگان permission برای validation Registry به `QYS_Config::PERMISSIONS` منتقل شد.

`QYS_Multitenant::PERMISSIONS` به‌عنوان compatibility alias باقی مانده است. `QYS_Permission_Service` و Installer برای vocabulary به Config متکی‌اند، نه به constant implementation Multitenant.

این تغییر Permission Registry کامل Phase G نیست؛ فقط dependency معکوس C7 را حذف می‌کند.

## 7. Migration C7

Migration جدید:

`20261006_014_rc25_module_registry`

ویژگی‌ها:

- Infrastructure-only
- بدون جدول/ستون جدید
- بدون rewrite داده دامنه
- متعلق به Lifecycle module `module-registry`
- readiness Registry و مالک‌بودن ۱۰ Feature را پیش از complete شدن تأیید می‌کند
- Ledger مورد انتظار: `14/14 completed`
- Schema همچنان `2026.10.05.7`

## 8. Health Center

Health Center در RC25 علاوه بر Module Lifecycle یک readiness جدید دارد:

- `Module registry — Modules: 17 | Features: 10`

جدول Module Lifecycle نیز ستون `Feature` را نمایش می‌دهد تا تعداد Featureهای متعلق به هر ماژول قابل مشاهده باشد.

انتظار مهم:

- `universities` → Feature count = `2`
- `members/messaging/programs/attendance/targetedmessaging/spreadsheetmessaging/birthday/welcome` → هرکدام `1`
- `module-registry` → Migration count = `1`

## 9. تست‌های اجراشده

- PHP syntax: `49/49 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- C7 source/static contracts: `40/40 PASS`
- Module Registry runtime: `32 assertions PASS`
- Module lifecycle: `17/17 modules booted`
- Registry health: `17 modules / 10 features PASS`
- RC24 → RC25 Migration regression: `18 assertions PASS` و Ledger `13/14 → 14/14`
- Full plugin boot smoke: `19 assertions PASS`
- Hook parity RC24→RC25:
  - actions `53 = 53 PASS`
  - filters `4 = 4 PASS`
  - shortcodes `2 = 2 PASS`
  - activation hooks `1 = 1 PASS`
- C6 Repository runtime: `15 assertions PASS`
- C5 regressions: `11 + 10 + 7 + 6 assertions PASS`
- C6 source/static contracts: `35/35 PASS`

## 10. خارج از دامنه C7

عمداً انجام نشده‌اند:

- Phase D Performance Hardening
- شکستن God Classهای feature-local به‌عنوان P2-04
- Permission Registry کامل Phase G
- UX/UI جدید
- Feature محصولی جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 11. نصب روی Production

RC24 را با RC25 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 014 تغییر فیزیکی دیتابیس ندارد. automatic runner باید آن را روی اولین درخواست عادی اجرا کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `14/14 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module registry = `Modules: 17 | Features: 10`
- `module-registry`: Migration count = `1`
- `universities`: Feature count = `2`
- Programs: Migration count = `1`
- Attendance dependency شامل `programs`
- failed = `0`
- blocked = `0`
- degraded = `0`
- Worker/Cron/Retention سالم
- Queue بدون وضعیت غیرعادی

## 12. Rollback

C7 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC24 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 014 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

با تأیید RC25، **Phase C کامل می‌شود** و مرحله بعدی Roadmap:

**«مرحله بعدی — Phase D / D1 — حذف Runtime Schema Introspection»**
