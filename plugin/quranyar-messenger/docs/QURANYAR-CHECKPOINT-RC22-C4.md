# چک‌پوینت پروژه قرآن‌یار — Phase C / C4 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc22`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC21  
**مبنای ارتقا:** RC21 / Migration Ledger `10/10` / Module Lifecycle `17/17`  
**وضعیت:** C4 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف C4

کاهش نقش God Class تاریخی `QYS_Messenger` با استخراج سرویس‌های پایه به مرزهای مستقل، بدون شکستن قراردادهای عمومی موجود و بدون ورود زودهنگام به cycle-breaking مرحله C5.

## 2. سرویس‌های استخراج‌شده

شش مرز پایه اضافه شده‌اند:

- `QYS_Config`
- `QYS_Token_Store`
- `QYS_Table_Names`
- `QYS_Text_Normalizer`
- `QYS_Calendar_Service`
- `QYS_Bootstrap_Facade`

### Config

منبع canonical نسخه، Schema، دانشگاه پیش‌فرض، endpoint ارسال و مسیر runtime افزونه است. ثابت‌های قدیمی `QYS_Messenger` برای backward compatibility باقی مانده‌اند اما مقدار canonical را از `QYS_Config` می‌گیرند.

### TokenStore

خواندن منبع توکن، اعتبارسنجی، رمزگذاری/decryption و persistence توکن‌های `webapp / delivery` از Trait پیام‌رسانی خارج شده است. APIهای قدیمی `QYS_Messenger::secret()` و هم‌خانواده‌ها به‌عنوان facade سازگار باقی مانده‌اند.

### TableNames

ساخت نام جدول، بررسی وجود جدول و scalar count از helper مرکزی خارج شده‌اند. Repository/Domain code برای این مسئولیت دیگر به `QYS_Messenger` وابسته نیست.

### TextNormalizer

نرمال‌سازی ارقام، موبایل، کد ملی، header، طول/clip متن و template rendering به سرویس مستقل منتقل شده‌اند.

### CalendarService

محاسبات Jalali/Gregorian، اعتبارسنجی تاریخ تولد، تبدیل تاریخ و ساعت ایران در مرز مستقل قرار گرفته‌اند.

### Bootstrap Facade

ثبت activation hook، bootstrap تنظیمات و تحویل Boot به `QYS_Module_Manager` اکنون یک نقطه ورود صریح دارد. فایل اصلی افزونه دیگر مستقیماً Installer و Module Manager را wire نمی‌کند.

## 3. Compatibility Strategy

C4 یک big-bang rewrite نیست.

- Traitهای قدیمی `QYS_Support_Module` و APIهای token در `QYS_Messaging_Module` حفظ شده‌اند ولی فقط delegate می‌کنند.
- ثابت‌های عمومی قدیمی `QYS_Messenger` حفظ شده‌اند.
- call siteهای مربوط به سرویس‌های استخراج‌شده در Domain/Repository تا حد امکان مستقیماً به سرویس جدید منتقل شده‌اند.
- رفتار business/domain تغییر داده نشده است.
- Member role registry، failure classification و domain workflows عمداً در C4 جابه‌جا نشده‌اند چون متعلق به مراحل دیگر هستند.

## 4. Migration C4

Migration جدید:

`20261006_011_rc22_core_services`

ویژگی‌ها:

- Infrastructure-only
- بدون تغییر فیزیکی جدول/ستون
- بدون rewrite داده دامنه
- متعلق به `core` lifecycle module
- callback فقط آماده‌بودن boundaryهای پایه را تأیید می‌کند
- پس از RC21 انتظار می‌رود Ledger از `10/10` به `11/11` برسد
- Schema همچنان `2026.10.05.7` است

## 5. Module Lifecycle

تعداد ماژول‌ها تغییر نکرده است: `17`.

ماژول `core` اکنون:

- یک Migration متعلق به C4 دارد؛
- Health خود را از `QYS_Bootstrap_Facade::health()` می‌گیرد؛
- در صورت نبودن یکی از service boundaryهای حیاتی degraded/failed را از Lifecycle پنهان نمی‌کند.

Token storage رمزگذاری‌شده اگر OpenSSL در محیط موجود نباشد، به‌تنهایی Core را fail-closed نمی‌کند؛ زیرا fallback مبتنی بر ثابت‌های wp-config همچنان یک مسیر معتبر است. این مرحله dependency جدید اجباری به محیط اضافه نمی‌کند.

## 6. تست‌های اجراشده

- PHP syntax: `45/45 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- C4 source/static contracts: `54/54 PASS`
- Legacy RC21 → C4 helper behavior parity: `PASS`
- Module lifecycle runtime: `13 assertions PASS`؛ `17/17 modules booted`
- RC21-ledger → RC22 migration regression: `17 assertions PASS`
  - قبل: 10 completed + Migration 011 pending
  - اجرا: یک Migration در bounded runner
  - بعد: `11/11 completed`
- Full plugin include/init boot smoke: `15 assertions PASS`؛ `17/17 modules booted`
- Runtime hook parity RC21 → RC22:
  - actions: `53 = 53 PASS`
  - filters: `4 = 4 PASS`
  - shortcodes: `2 = 2 PASS`
  - activation hooks: `1 = 1 PASS`

## 7. خارج از دامنه C4

عمداً انجام نشده‌اند:

- C5: شکستن cycleهای Programs ↔ Attendance، Permission ↔ Targeted و Security ↔ Multitenant
- C6: Repository ownership کامل
- C7: تبدیل FeatureRegistry به Module Registry واقعی
- Phase D Performance
- تغییر UX/UI یا Feature محصولی جدید

## 8. نصب روی Production

RC21 را با RC22 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 011 تغییر فیزیکی دیتابیس ندارد. اولین درخواست عادی WordPress باید automatic runner را اجرا کند.

پس از نصب، Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `11/11 completed`
- Upgrade gate = آماده
- Module lifecycle = `17/17 booted`
- Core Health = `ok`
- failed = 0
- blocked = 0
- degraded = 0
- Worker/Cron/Retention سالم

## 9. Rollback

C4 هیچ داده دامنه یا Schema فیزیکی را تغییر نمی‌دهد.

Rollback فایل به RC21 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 011 می‌تواند در Ledger باقی بماند و برای RC21 یک رکورد تاریخی completed خواهد بود.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase C / C5 — Dependency Cycle Break»**
