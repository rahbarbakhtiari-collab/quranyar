# چک‌پوینت پروژه قرآن‌یار — Phase D / D5 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc30`  
**Schema:** `2026.10.06.1` — تغییر فیزیکی فقط برای index صف  
**مبنای ارتقا:** RC29 / Migration Ledger `18/18` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D5 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D5

مسیر داغ Worker پیام‌ها از queue با الگوی زیر مصرف می‌کند:

`WHERE status='pending' ... ORDER BY id`

در RC29 جدول queue indexهای `campaign_status`، `member_status` و `status_lease` را داشت، اما index دقیقی که هم filter روی `status` و هم ترتیب `id` را پوشش دهد نداشت.

D5 index مرکب زیر را اضافه می‌کند:

`KEY status_id (status,id)`

هدف، کاهش scan/filesort غیرضروری در انتخاب batchهای pending است؛ business logic، اندازه batch و semantics ارسال در D5 تغییر نمی‌کند.

## 2. Fresh Install و Upgrade

### Fresh Install

تعریف canonical جدول queue در Installer اکنون شامل:

`KEY status_id (status,id)`

است.

### Existing RC29

Migration 019 ابتدا indexهای فیزیکی queue را بررسی می‌کند. اگر هر index موجود prefix برابر `status,id` داشته باشد، migration بدون DDL تکمیل می‌شود.

اگر چنین prefixی وجود نداشته باشد:

`ALTER TABLE ... ADD KEY status_id (status,id)`

یک‌بار اجرا می‌شود و پس از پاک‌کردن cache diagnostics، وجود index دوباره verify می‌شود.

این migration idempotent است و index سفارشی معادل با نام دیگر را نیز معتبر می‌پذیرد.

## 3. Repository Contract

مرزهای جدید در `QYS_Campaign_Repository`:

- `diagnostic_queue_status_id_index_ready()`
- `ensure_queue_status_id_index()`
- `queue_dispatch_index_contract()`

تشخیص index فقط در Migration/Health diagnostics انجام می‌شود و runtime worker probe فیزیکی تازه‌ای اضافه نمی‌کند.

## 4. Health Center

کارت جدید:

`Queue dispatch index`

انتظار Production:

`(status,id): ready | pending scan: indexed`

این کارت فقط هنگام بازدید صریح Health Center schema/index diagnostic را اجرا می‌کند؛ مسیر عادی Worker همچنان به runtime schema gate D1 متکی است.

## 5. Migration D5

Migration جدید:

`20261006_019_rc30_queue_status_id_index`

ویژگی‌ها:

- متعلق به lifecycle module `messaging`
- تغییر فیزیکی: افزودن یک non-unique composite index به queue
- بدون table/column جدید
- بدون rewrite داده دامنه
- بدون تغییر delivery state semantics
- Ledger مورد انتظار: `19/19 completed`
- Schema جدید: `2026.10.06.1`

پس از RC30، migration count ماژول `messaging` برابر `1` خواهد بود.

## 6. تست‌های اجراشده

### Work Tree

- PHP syntax: `51/51 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D5 source/static contracts: `20/20 PASS`
- D5 index runtime assertions: `16 PASS`
  - missing index تشخیص داده شد
  - ALTER فقط یک بار اجرا شد
  - cache diagnostic بعد از DDL invalidate شد
  - اجرای مجدد idempotent بود
  - index سفارشی با prefix `status,id` پذیرفته شد
  - prefix اشتباه `status,lease_expires_at_utc,id` رد شد
- Source hook registration parity RC29→RC30: unchanged (`53 actions / 5 filters / 2 shortcodes / 1 activation hook` by static call-site count)
- D4/D3/D2/D1/C6/C5 source files خارج از integration D5 بدون تغییر رفتاری باقی مانده‌اند.

تمام syntax/static/runtime تست‌های D5 روی ZIP نهایی استخراج‌شده نیز دوباره اجرا شدند و PASS هستند.

## 7. خارج از دامنه D5

عمداً انجام نشده‌اند:

- D6: Queue throughput adaptive
- D7: Health snapshot سبک‌تر
- D8: Batch export
- D9: Birthday index
- D10: conditional asset loading عمومی
- تغییر اندازه batch یا rate limit provider
- UX/UI feature جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 8. نصب روی Production

RC29 را با RC30 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 019 یک index فیزیکی می‌سازد. روی جدول queue بزرگ، همان درخواست ارتقا ممکن است از migrationهای code-only قبلی کمی طولانی‌تر باشد؛ در زمان اجرا Upgrade gate بسته می‌ماند و پس از تکمیل خودکار باز می‌شود.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.1`
- Migration Ledger = `19/19 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `2`
- Members migration count = `2`
- Messaging migration count = `1`
- Queue dispatch index = `(status,id): ready | pending scan: indexed`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Home summary = `endpoint: lightweight | programs: compact | forms: deferred`
- Mini-app feature load = `programs: lazy | attendance: lazy | data: on_view`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker/Cron/Retention سالم
- Queue بدون وضعیت غیرعادی

## 9. Rollback

D5 داده دامنه را تغییر نمی‌دهد. در rollback فایل به RC29، حذف index لازم نیست؛ index non-unique جدید می‌تواند باقی بماند.

RC29 در اولین مسیر Upgrade خود schema option را به نسخه مورد انتظار خودش normalize می‌کند، چون migrationهای شناخته‌شده تا 018 completed هستند. Entry 019 نیز می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D6 — Queue throughput adaptive»**
