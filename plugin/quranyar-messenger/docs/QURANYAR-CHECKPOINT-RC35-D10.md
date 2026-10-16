# چک‌پوینت پروژه قرآن‌یار — Phase D / D10 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc35`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC34  
**مبنای ارتقا:** RC34 / Migration Ledger `23/23` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D10 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D10

بستن `QYS-P2-12 — Asset Loading غیرشرطی در /qys/` بدون تغییر business workflow، Permission، Tenant scope یا UI.

در RC34، shell دانشگاه در هر صفحه `/qys/` علاوه بر assetهای پایه، assetهای Attendance، QR، Targeted و Programs را نیز eager بارگذاری می‌کرد؛ حتی در Home، Members، Messages و More که به آن‌ها نیازی نبود.

## 2. Portal Asset Policy

کلاس جدید:

`QYS_Portal_Asset_Policy`

قرارداد:

` shell: base | feature: conditional | scope: section+permission `

Feature bundle فقط وقتی مجاز به enqueue است که:

1. درخواست واقعاً مربوط به portal دانشگاه باشد؛
2. کاربر وارد شده و campus معتبر داشته باشد؛
3. section فعلی دقیقاً همان Feature باشد؛
4. module آن Feature در وضعیت `booted` باشد؛
5. permission همان Feature برای campus جاری فعال باشد.

section نامعتبر، کاربر خروج‌کرده، campus نامعتبر، module unavailable یا permission ردشده هیچ asset اختصاصی Feature دریافت نمی‌کند.

## 3. تفکیک Asset Ownership

`QYS_Multitenant::enqueue_portal_assets()` اکنون فقط shell مشترک را enqueue می‌کند:

- `qys-fonts.css`
- `qys-admin-ui.css`
- `qys-university-portal.css`
- `qys-university-portal.js`

Assetهای Feature به مالک واقعی منتقل/محدود شدند:

- Programs → فقط `section=programs`: `qys-programs.css`
- Attendance → فقط `section=attendance`: `attendance-admin.css/js` + QR vendor
- Targeted → فقط `section=targeted`: `targeted-admin.js` + nonce/scope portal
- Excel → فقط `section=excel`: `qys-excel.css/js`

Birthday، Welcome، Messages Hub، Members، Home و More asset اختصاصی اضافه ندارند.

## 4. کاهش بار اولیه

در RC34، assetهای Feature که روی صفحات غیرمرتبط portal نیز eager بودند جمعاً `79,767` بایت خام داشتند:

- Attendance admin CSS/JS + QR vendor = `62,562` bytes
- Targeted JS = `6,996` bytes
- Programs CSS = `10,209` bytes

Shell پایه قرآن‌یار `65,345` بایت خام است. بنابراین روی Home/Members/Messages/More، بار assetهای خود قرآن‌یار از حدود `145,112` بایت به `65,345` بایت کاهش می‌یابد؛ حدود **55.0٪ کاهش خام** در assetهای portal خود افزونه، پیش از gzip/brotli و بدون احتساب assetهای WordPress/theme.

## 5. ایمنی و سازگاری

- Permission و Tenant policy تغییر نکرده‌اند.
- Feature dispatch همچنان از `QYS_Portal_Feature_Service` و Module Registry عبور می‌کند.
- module unavailable asset اختصاصی دریافت نمی‌کند.
- Targeted nonce همچنان به campus جاری bind است.
- Attendance JS اکنون dependency صریح QR vendor دارد؛ ترتیب لود deterministic شده است.
- D4 lazy-loading mini-app دست‌نخورده مانده است.
- wp-admin assetها همان شرط‌های صفحه‌ای قبلی را حفظ کرده‌اند.

## 6. Health Center و Migration

کارت جدید:

`Portal assets`

انتظار:

` shell: base | feature: conditional | scope: section+permission `

Migration جدید:

`20261006_024_rc35_conditional_portal_assets`

ویژگی‌ها:

- متعلق به lifecycle module `universities`
- performance/code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `24/24 completed`
- Universities migration count پس از RC35 = `1`

## 7. تست‌های اجراشده

### Work Tree

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D10 source/static contracts: `35/35 PASS`
- Portal Asset Policy runtime: `20/20 assertions PASS`
- Feature enqueue runtime: `18/18 assertions PASS`
- D10 migration contract: `PASS`
- Performance/C7 source regression: `12/12 PASS`
- Hook inventory RC34 → RC35:
  - actions `55 → 57` — دو hook مورد انتظار برای مالکیت مستقیم asset در Attendance و Targeted
  - filters `6 = 6`
  - shortcodes `2 = 2`
  - activation hooks `1 = 1`

همه syntax/static/runtime تست‌ها پس از ساخت ZIP نهایی روی Artifact استخراج‌شده نیز دوباره اجرا می‌شوند.

## 8. خارج از دامنه D10

عمداً انجام نشده‌اند:

- تغییر UI/UX portal
- تغییر permission vocabulary
- تغییر route/REST contract
- تغییر mini-app lazy loading D4
- تغییر assetهای Theme/WordPress خارج از قرآن‌یار
- async asset bundling/minification
- Phase E — UX Safety & Workflow
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC34 را با RC35 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 024 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `24/24 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Universities migration count = `1`
- Birthday migration count = `1`
- Portal assets = `shell: base | feature: conditional | scope: section+permission`
- Birthday lookup = `column: birth_md | query: exact | index: ready`
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

## 10. Rollback

D10 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC34 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 024 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

با Production Verification RC35، ده گام Phase D کامل می‌شوند. مرحله بعدی:

**«Phase E / E1 — جداسازی Membership از Messaging Consent»**
