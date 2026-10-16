# چک‌پوینت پروژه قرآن‌یار — Phase D / D9 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc34`  
**Schema:** `2026.10.06.2`  
**مبنای ارتقا:** RC33 / Migration Ledger `22/22` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D9 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D9

بستن `QYS-P2-10 — Birthday با LIKE '%MM/DD'`.

تا RC33، مسیرهای تولد برای یافتن ماه/روز شمسی از `birth_jalali LIKE '%/MM/DD'` استفاده می‌کردند. چون wildcard در ابتدای الگو قرار داشت، index معمولی روی `birth_jalali` قابل استفاده نبود و با رشد اعضا scan بزرگ ایجاد می‌شد.

D9 باید این lookup را به equality indexable تبدیل کند، بدون تغییر business rule تولد، رضایت پیام، tenant scope یا dedupe سالانه.

## 2. کلید materialized تولد

ستون جدید جدول اعضا:

`birth_md char(5) NOT NULL DEFAULT ''`

مقدار canonical آن `MM/DD` است؛ مثال:

- `birth_jalali = 1400/07/14`
- `birth_md = 07/14`

`QYS_Member_Repository::insert()` و `update()` هر بار که `birth_jalali` نوشته شود، `birth_md` را نیز از همان مقدار canonical استخراج و همگام می‌کنند. بنابراین منبع هویتی اصلی همچنان `birth_jalali` است و `birth_md` فقط کلید مشتق‌شده Performance است.

## 3. index جدید

Fresh install و existing install هر دو باید index زیر را داشته باشند:

`birthday_lookup (birth_md,birthday_opt_in,verified,consent,id)`

این ترتیب برای scan روز جاری مناسب است:

- `birth_md` equality
- سه flag eligibility به‌صورت equality
- `id` برای keyset `id > last_id` و `ORDER BY id`

Migration هر index با هر نامی را می‌پذیرد اگر prefix دقیق فوق را داشته باشد. اگر index مناسب وجود نداشته باشد، آن را idempotent اضافه می‌کند.

## 4. حذف leading wildcard

سه مسیر Repository تغییر کردند:

1. `birthday_count_enabled_campuses()`
2. `birthday_members_after()`
3. `birthday_complete_count_for_university()`

هر سه اکنون `birth_md=%s` exact match دارند. در سورس runtime دیگر عبارت `birth_jalali LIKE` برای Birthday وجود ندارد.

Callerهای تاریخی می‌توانند suffix را همچنان به‌شکل `/MM/DD` بدهند؛ Repository آن را به `MM/DD` canonical تبدیل می‌کند.

## 5. Migration D9

Migration جدید:

`20261006_023_rc34_birthday_lookup_index`

ویژگی‌ها:

- متعلق به lifecycle module `birthday`
- اگر `birth_md` موجود نباشد، ستون را اضافه می‌کند
- رکوردهای موجود با format canonical `YYYY/MM/DD` را با `RIGHT(birth_jalali,5)` backfill می‌کند
- برای مقدار نامعتبر/خالی، `birth_md=''` نگه می‌دارد
- index مناسب را فقط در صورت نبود index معادل اضافه می‌کند
- اجرای دوباره DDL ایجاد نمی‌کند
- هیچ Member، consent، verified state یا birth_jalali را rewrite نمی‌کند
- Schema از `2026.10.06.1` به `2026.10.06.2` می‌رسد
- Ledger مورد انتظار پس از نصب: `23/23 completed`
- Birthday migration count پس از RC34 = `1`

## 6. Health Center

کارت جدید:

`Birthday lookup`

انتظار:

`column: birth_md | query: exact | index: ready`

`Schema diagnostics` همچنان باید `Physical: 12 / 12` باشد؛ check اعضا اکنون وجود ستون و index D9 را نیز در همان check فیزیکی اعضا verify می‌کند.

## 7. تست‌های اجراشده

- PHP syntax: `53/53 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D9 source/static contracts: `24/24 PASS`
- D9 repository/migration runtime: `21 assertions PASS`
  - derive `MM/DD` از birth_jalali canonical
  - reject مقدار غیرcanonical
  - تشخیص نبود index
  - افزودن ستون و backfill
  - افزودن index
  - اجرای مجدد بدون DDL تکراری
  - پذیرش index معادل با نام سفارشی
  - رد prefix اشتباه
  - هر سه query تولد exact و بدون LIKE
  - sync شدن `birth_md` در insert/update
  - suffix نامعتبر بدون SQL رد می‌شود
  - callback Migration D9 contract را repair/verify می‌کند
- Hook inventory RC34 = `55 actions / 6 filters / 2 shortcodes / 1 activation hook`؛ نسبت به RC33 بدون تغییر
- diff runtime نسبت به RC33 فقط در Config/version، Installer، MemberRepository، ModuleManager و HealthCenter است.

همه syntax/static/runtime تست‌ها پس از ساخت ZIP نهایی نیز باید روی Artifact استخراج‌شده دوباره PASS شوند.

## 8. خارج از دامنه D9

عمداً انجام نشده‌اند:

- D10: conditional asset loading عمومی
- تغییر الگوریتم Birthday Worker یا سقف ارسال تولد در هر tick
- تغییر consent / verified / campus due / dedupe سالانه
- Member Search leading wildcard (`QYS-P2-11`)
- UX/UI feature جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC33 را با RC34 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 023 یک ستون مشتق‌شده، backfill bounded-by-table و یک index فیزیکی ایجاد می‌کند. روی جدول اعضای بسیار بزرگ، همان درخواست ارتقا ممکن است از migrationهای code-only طولانی‌تر باشد؛ Upgrade gate تا تکمیل migration بسته می‌ماند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `23/23 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Core migration count = `3`
- Programs migration count = `2`
- Members migration count = `2`
- Messaging migration count = `2`
- Health migration count = `1`
- Birthday migration count = `1`
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

D9 داده هویتی `birth_jalali` را تغییر نمی‌دهد. ستون مشتق‌شده `birth_md` و index non-unique آن در rollback فایل می‌توانند باقی بمانند. RC33 migration ناشناخته 023 را اجرا نمی‌کند اما Ledger completed history را می‌تواند نگه دارد و Upgrade Coordinator نسخه Schema مورد انتظار RC33 را در مسیر ready خود normalize می‌کند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D10 — conditional asset loading»**
