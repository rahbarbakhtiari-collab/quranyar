# چک‌پوینت پروژه قرآن‌یار — Phase E / E3 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc38`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC37  
**مبنای ارتقا:** RC37 / Migration Ledger `26/26` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E3 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E3

بستن `QYS-P2-23 — Actionهای برگشت‌ناپذیر confirmation ندارند` برای چهار مسیر ثبت‌شده در ممیزی:

- پایان برنامه
- لغو برنامه
- بایگانی برنامه
- رد متقاضی

E3 فقط لایه Safety/UX این عملیات را تغییر می‌دهد و state machine، مجوز، Tenant scope و transitionهای Domain را تغییر نمی‌دهد.

## 2. قرارداد تأیید عملیات برگشت‌ناپذیر

`QYS_Programs_Module::destructive_confirmation_contract()`:

`program: complete/cancel/archive | applicant: reject | server: required`

هر فرم برگشت‌ناپذیر دارای پیام توضیحی صریح است که اثر اصلی عملیات را قبل از submit بیان می‌کند. Transitionهای غیرپایانی مانند باز/بسته‌کردن ثبت‌نام، قطعی‌کردن، شروع، بررسی، انتظار و پذیرش بدون confirmation جدید باقی مانده‌اند.

## 3. Client confirmation

Asset جدید:

`assets/qys-confirm.js`

ویژگی‌ها:

- فقط در صفحه/section Programs بارگذاری می‌شود؛ روی سایر صفحات Portal بار اضافه ایجاد نمی‌کند.
- فرم‌های دارای `data-qys-confirm` را در capture phase کنترل می‌کند.
- Cancel ⇒ submit کاملاً متوقف می‌شود و handlerهای بعدی نیز اجرا نمی‌شوند.
- Confirm ⇒ hidden marker `qys_destructive_confirmed=1` تنظیم می‌شود و submit عادی ادامه می‌یابد.
- روی `pageshow` marker به `0` برمی‌گردد تا BFCache باعث عبور بدون تأیید در اقدام بعدی نشود.
- همان asset در wp-admin مدیرکل Programs و پنل دانشگاه استفاده می‌شود.

## 4. Server-side guard

Client-only confirmation کافی دانسته نشده است.

`QYS_Programs_Module::require_destructive_confirmation()` قبل از اجرای Domain transition بررسی می‌کند:

- `program_status → completed/cancelled/archived`
- `application_status → rejected`

در این چهار حالت، نبود `qys_destructive_confirmed=1` درخواست را با خطای امن متوقف می‌کند. بنابراین POST مستقیم، JavaScript خاموش یا حذف dialog در browser نمی‌تواند عملیات برگشت‌ناپذیر را بدون marker تأیید اجرا کند.

Nonce، Permission، Program ownership/visibility، Applicant scope و Domain state machine بعد از همین guard مانند قبل enforce می‌شوند.

## 5. پیام‌های تأیید

- **پایان برنامه:** بسته‌شدن درخواست‌های بلاتکلیف و نهایی‌شدن وضعیت ثبت‌نام‌های فعال توضیح داده می‌شود.
- **لغو برنامه:** لغوشدن درخواست‌ها و ثبت‌نام‌های فعال مرتبط توضیح داده می‌شود.
- **بایگانی:** پایان مدیریت فعال و انتقال به وضعیت بایگانی توضیح داده می‌شود.
- **رد متقاضی:** نهایی و غیرقابل‌بازگشت بودن وضعیت `rejected` در مسیر فعلی توضیح داده می‌شود.

## 6. Health Center و Migration

کارت جدید:

`Destructive confirmation`

انتظار:

`program: complete/cancel/archive | applicant: reject | server: required`

Migration جدید:

`20261006_027_rc38_destructive_confirmations`

ویژگی‌ها:

- متعلق به lifecycle module `programs`
- UX safety/code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `27/27 completed`
- Programs migration count پس از RC38 = `4`

## 7. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `11/11 PASS`
- CSS structural: `10/10 PASS`
- E3 source/static contracts: `33/33 PASS`
- E3 PHP runtime contract: `17/17 PASS`
  - contract و چهار target دقیق
  - targetهای غیرمخرب بدون confirmation
  - program terminal بدون marker ⇒ blocked
  - program terminal با marker ⇒ allowed
  - reject بدون marker ⇒ blocked
  - reject با marker ⇒ allowed
- E3 client confirmation runtime: `13/13 PASS`
  - capture handler
  - Cancel ⇒ preventDefault + stop propagation
  - Confirm ⇒ marker=`1`
  - pageshow ⇒ marker reset
- E3 migration contract: `PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC37

همه تست‌های syntax/static/runtime فوق پس از ساخت ZIP نهایی روی Artifact استخراج‌شده نیز دوباره اجرا می‌شوند.

## 8. تست دستی

طبق تصمیم محصول، تست دستی E3 اکنون درخواست نمی‌شود و در **پکیج پذیرش نهایی پروژه** با داده آزمایشی اختصاصی انجام خواهد شد. آن بسته باید Cancel/Confirm هر چهار عملیات را بررسی کند تا هیچ داده واقعی برای آزمایش مرحله‌ای مصرف نشود.

## 9. خارج از دامنه E3

عمداً انجام نشده‌اند:

- E4: Program Edit workflow
- E5: Member Withdraw
- E6: Error/Empty/Loading استاندارد
- E7: Draft فرم
- E8: validation field-level
- E9: bulk applicant management
- E10: اصلاح progress پرونده
- تغییر visual hierarchy دکمه‌های خطر (Phase F)
- تغییر Domain transition یا eligibility
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 10. نصب روی Production

RC37 را با RC38 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 027 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `27/27 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `4`
- Members migration count = `3`
- Universities migration count = `1`
- Birthday migration count = `1`
- Destructive confirmation = `program: complete/cancel/archive | applicant: reject | server: required`
- Programs access gate = `mode: explain | cta: explicit | redirect: none`
- Membership / messaging = `membership: independent | messaging: optional | default: off`
- Portal assets = `shell: base | feature: conditional | scope: section+permission`
- Birthday lookup = `column: birth_md | query: exact | index: ready`
- Batch export = `mode: streaming | chunk: 250 | cursor: keyset | memory: bounded`
- Health snapshot = `heartbeat: lightweight | full: 15m/on-demand`
- Queue dispatch index = `(status,id): ready | pending scan: indexed`
- Queue throughput = `mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention سالم

## 11. Rollback

E3 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC37 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 027 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از Production Health Verification

**«Phase E / E4 — Program Edit workflow»**
