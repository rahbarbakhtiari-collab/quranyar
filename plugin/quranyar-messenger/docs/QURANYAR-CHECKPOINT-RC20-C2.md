# چک‌پوینت پروژه قرآن‌یار — Phase C / C2 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc20`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC19  
**مبنای ارتقا:** RC19 / Migration Ledger 8/8  
**وضعیت:** C2 Implemented + Local/Artifact Tests PASS — Pending Production Verification

---

## 1. هدف C2

حذف وابستگی Upgrade به بازدید مدیر از `wp-admin`.

از RC20 به بعد، Migrationهای post-baseline باید بتوانند روی درخواست عادی WordPress، REST، Cron یا Admin به‌صورت امن و محدود اجرا شوند؛ در عین حال Fresh Install همچنان برای تعیین مالک اولیه به مدیر نیاز دارد.

---

## 2. Automatic Upgrade Coordinator

کلاس جدید:

`QYS_Upgrade_Coordinator`

وظایف:

- اجرای Upgrade روی `init` با اولویت `-1000`
- حداکثر یک Migration در هر درخواست عادی
- budget شروع Migration بعدی: 1500ms
- cooldown کوتاه برای جلوگیری از تلاش تکراری شدید
- استفاده از Ledger lock موجود برای جلوگیری از concurrency
- telemetry بدون PII برای آخرین تلاش Upgrade

---

## 3. Fresh Install در برابر Upgrade

### Fresh Install

- خودکار baseline نمی‌شود.
- همچنان مدیر دارای `manage_options` باید bootstrap مالک اولیه را تکمیل کند.
- `admin_init` فقط به‌عنوان Fresh Install / fallback باقی مانده است.

### Existing RC19+

- Ledger موجود مستقیماً توسط runner خودکار advance می‌شود.
- نیاز به ورود مدیر برای اجرای Migration جدید ندارد.

### Skip-version از RC18

اگر:

- `qys_install_state = ready`
- Schema = `2026.10.05.7`
- Ledger هنوز وجود ندارد

RC20 نصب موجود را به‌عنوان baseline معتبر به‌صورت خودکار import می‌کند؛ Fresh/Pending install وارد این مسیر نمی‌شود.

---

## 4. Readiness Gate مرکزی

تا زمانی که Migration کامل نشده باشد:

- RESTهای `/qys/v1/*` → HTTP 503
- برنامک عضو → unavailable state
- پنل دانشگاه → unavailable state
- qys admin-post → 503
- qys AJAX → 503
- صفحات مدیریتی قرآن‌یار → 503
- Worker پیام → متوقف
- Welcome/Activation worker → متوقف
- Birthday worker/scheduler → متوقف
- Targeted activation worker → متوقف

صفحه `qys-health` برای diagnostics باز می‌ماند.

سایر صفحات و RESTهای WordPress مسدود نمی‌شوند.

---

## 5. Migration Ledger

Migration جدید:

`20261006_009_rc20_upgrade_coordinator`

C1 نیز در تعریف‌های Core حفظ شده است:

`20261005_008_rc19_migration_ledger`

پس از نصب RC20 انتظار می‌رود:

- Migration Ledger = `9/9 completed`
- pending = 0
- running = 0
- failed = 0
- C2 attempts = 1
- C2 checkpoint = `complete`

---

## 6. Bounded Migration Runner

API جدید:

`QYS_Migration_Ledger::run_pending_bounded()`

ویژگی‌ها:

- max migrations/request
- budget پیش از شروع Migration بعدی
- lock concurrency
- checkpoint/retry حفظ می‌شود
- completed دوباره اجرا نمی‌شود
- failure به‌صورت fail-closed باقی می‌ماند

---

## 7. Health Center

Health Center اکنون گزارش می‌کند:

- Upgrade gate ready/closed
- Automatic runner enabled
- آخرین تلاش
- نتیجه آخرین تلاش
- تعداد Migration اجراشده
- تعداد remaining
- مدت اجرای اخیر

اگر Upgrade ناقص باشد، Health Center برای جلوگیری از خطای schema، domain tableهای در حال Migration را probe نمی‌کند و فقط telemetry امن را نمایش می‌دهد.

---

## 8. Schema / Migration

C2 هیچ جدول یا ستون دامنه‌ای اضافه نمی‌کند.

**Schema همچنان:** `2026.10.05.7`

فقط entry جدید Migration Ledger افزوده می‌شود.

---

## 9. تست‌های اجراشده

- PHP syntax: 37/37 PASS
- JavaScript syntax: 10/10 PASS
- CSS structural: 10/10 PASS
- C2/static + regression contracts: 39/39 PASS
- C2 runtime-like assertions: 29 PASS
- C1 checkpoint/retry regression: PASS
- Health degraded-mode test: PASS
- Plugin include/init boot smoke: PASS
- Fresh install auto-baseline prevention: PASS
- RC18 skip-version auto-baseline path: PASS
- RC19→RC20 auto migration without admin: PASS
- bounded future migrations: PASS
- concurrent Ledger lock: PASS
- failed migration fail-closed REST 503: PASS
- unrelated WordPress REST remains available: PASS

---

## 10. نصب روی Production

RC19 را با RC20 جایگزین کنید؛ افزونه حذف و از صفر نصب نشود.

برای اجرای C2 نیازی به ورود به wp-admin نیست؛ اولین درخواست عادی WordPress باید Migration 009 را اجرا کند.

سپس Health Center را باز کنید.

انتظار:

- Schema = `2026.10.05.7`
- Migration Ledger = `9/9`
- Upgrade gate = آماده
- pending/running/failed = 0
- Worker/Cron/Retention سالم

---

## 11. Rollback

C2 تغییر فیزیکی Schema یا داده دامنه ندارد.

Rollback فایل به RC19 نیازمند rollback دیتابیس نیست.

Entry `...009_rc20_upgrade_coordinator` ممکن است در Ledger باقی بماند، اما چون completed است RC19 آن را به‌عنوان داده تاریخی می‌بیند و replay نمی‌کند.

---

## 12. Acceptance Debt

Live Edge Acceptance مربوط به Phase A و B همچنان Deferred است و باید در Roadmap باقی بماند.

---

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase C / C3»**
