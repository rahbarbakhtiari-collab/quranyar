# چک‌پوینت پروژه قرآن‌یار — Phase D / D4 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc29`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC28  
**مبنای ارتقا:** RC28 / Migration Ledger `17/17` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D4 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D4

کد و payload کامل Featureهای **Programs** و **Attendance** نباید در بازشدن اولیه mini-app بارگذاری شوند.

قرارداد D4:

- Home فقط shell پایه و `home-summary` فشرده D3 را مصرف می‌کند.
- JS/CSS کامل Programs فقط در اولین ورود کاربر به view `programs` بارگذاری می‌شود.
- JS/CSS کامل Attendance فقط در اولین ورود کاربر به view `attendance` بارگذاری می‌شود.
- `programs/list` و `programs/record` فقط پس از بازشدن view مربوط فراخوانی می‌شوند.
- `attendance/sessions` و `attendance/history` فقط پس از بازشدن view حضور فراخوانی می‌شوند.
- asset و data کامل هر Feature پس از اولین بار در همان page session reuse می‌شوند.

## 2. Lazy Asset Manifest

دو Feature اکنون manifest صریح دارند:

- `QYS_Programs_Module::public_asset_manifest()`
- `QYS_Attendance::public_asset_manifest()`

هر manifest شامل:

- CSS URL
- JS URL
- نام global config
- endpointهای همان Feature

`QYS_Members_Module::miniapp()` این manifestها را با `QYSLazyFeatures` به shell پایه می‌دهد؛ دیگر Attendance را eager enqueue نمی‌کند و Programs نیز روی mini-app از hook عمومی eager enqueue نمی‌شود.

## 3. Base Shell Loader

`qys-public-ui.js` اکنون loader مشترک Feature دارد:

- CSS با `<link>` در اولین navigation
- JS با `<script>` در اولین navigation
- dedupe برای جلوگیری از بارگذاری دوباره
- retry در صورت شکست asset
- replay رویداد `qys:viewchange` بعد از آماده‌شدن Feature

بنابراین Feature پس از load شدن دقیقاً وضعیت جاری پرونده و view فعال را دریافت می‌کند.

## 4. Home Programs مستقل از Programs Feature

منطق D3 مربوط به Home Program cards از `qys-programs-public.js` به shell پایه منتقل شد.

نتیجه:

- Home همچنان endpoint سبک `home-summary` را دریافت می‌کند.
- برای نمایش کارت‌های Home نیازی به دانلود JS/CSS کامل Programs نیست.
- CSS لازم کارت‌های Home در `qys-public-ui.css` پایه قرار گرفته است.
- `qys-programs-public.js` اکنون فقط مسئول view کامل Programs است.

## 5. Attendance واقعاً On-View

قبل از D4، دریافت status معتبر می‌توانست `attendance/sessions` و `attendance/history` را حتی در Home اجرا کند.

در RC29:

- دریافت `qys:status` فقط eligibility را نگه می‌دارد.
- Full Attendance data فقط وقتی `root.dataset.qysView === 'attendance'` باشد load می‌شود.
- Home برای کاربر آماده، پیام سبک «حضور و غیاب آماده استفاده است» نشان می‌دهد.
- پس از اولین ورود به Attendance، summary واقعی حضور همچنان می‌تواند Home card را به‌روز کند.

## 6. Health Center

کارت جدید:

`Mini-app feature load`

انتظار:

`programs: lazy | attendance: lazy | data: on_view`

قرارداد برنامه‌ای:

`QYS_Messenger::lazy_feature_contract()`

همچنین کارت‌های D1/D2/D3 قبلی باید بدون Regression باقی بمانند.

## 7. Migration D4

Migration جدید:

`20261006_018_rc29_lazy_member_features`

ویژگی‌ها:

- Performance/code-contract only
- متعلق به lifecycle module `members`
- بدون جدول/ستون جدید
- بدون rewrite داده دامنه
- فقط آماده‌بودن manifest و lazy-load contract را تأیید می‌کند
- Ledger مورد انتظار پس از نصب: `18/18 completed`
- Schema همچنان `2026.10.05.7`

پس از RC29، migration count ماژول `members` از `1` به `2` می‌رسد.

## 8. تست‌های اجراشده

### Work Tree

- PHP syntax: `51/51 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D4 source/static contracts: `27/27 PASS`
- D4 manifest/runtime contract: `15 assertions PASS`
- RC28 → RC29 Migration regression: `18 assertions PASS`
  - Ledger `17/18 → 18/18`
  - Members migrations = `2`
- Full plugin boot smoke: `27 assertions PASS`
  - Modules `17/17`
  - Programs migrations `2`
  - Members migrations `2`
  - lazy contract ready
- Hook parity RC28 → RC29:
  - actions `53 = 53 PASS`
  - filters `4 = 4 PASS`
  - shortcodes `2 = 2 PASS`
  - activation hooks `1 = 1 PASS`
- D3 Home Summary runtime: `16 assertions PASS`؛ Home Program query=`1`؛ Forms in Home=`0`
- D2 Program batch runtime: `24 assertions PASS`
- D1 Runtime Schema Guard: `10 assertions PASS`؛ runtime SHOW queries=`0`
- C6 Repository runtime: `15 assertions PASS`
- C5 regressions: `12 + 10 + 7 + 6 assertions PASS`

تمام تست‌های بالا روی ZIP استخراج‌شده نیز دوباره اجرا شدند و PASS هستند؛ نتیجه در Test Report ثبت شده است.

## 9. خارج از دامنه D4

عمداً انجام نشده‌اند:

- D5: Queue index `(status,id)`
- D6: Queue throughput adaptive
- D7: Health snapshot سبک‌تر
- D8: Batch export
- D9: Birthday index
- D10: conditional asset loading عمومی برای سایر صفحات
- UX/UI feature جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

D4 فقط **Programs و Attendance در mini-app** را lazy می‌کند؛ conditional asset loading عمومی کل افزونه در D10 باقی می‌ماند.

## 10. نصب روی Production

RC28 را با RC29 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 018 تغییر فیزیکی دیتابیس ندارد و automatic runner باید آن را در اولین درخواست عادی اجرا کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `18/18 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `2`
- Members migration count = `2`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Home summary = `endpoint: lightweight | programs: compact | forms: deferred`
- Mini-app feature load = `programs: lazy | attendance: lazy | data: on_view`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker/Cron/Retention سالم

## 11. Rollback

D4 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC28 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 018 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D5 — Queue index `(status,id)`»**
