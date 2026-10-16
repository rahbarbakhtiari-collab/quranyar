# چک‌پوینت پروژه قرآن‌یار — Phase D / D6 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc31`  
**Schema:** `2026.10.06.1` — بدون تغییر نسبت به RC30  
**مبنای ارتقا:** RC30 / Migration Ledger `19/19` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D6 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D6

Worker صف دیگر به batch ثابت `5` پیام در هر اجرای دقیقه‌ای محدود نباشد. ظرفیت هر اجرا باید بر اساس **time budget واقعی اجرای قبلی** تطبیق پیدا کند و در عین حال safety مربوط به lease، outbound marker، rate limit و نتیجه نامشخص شبکه حفظ شود.

D6 عمداً delivery-state semantics را تغییر نمی‌دهد و `retryable_failed / uncertain` را کورکورانه retry نمی‌کند.

## 2. QYS_Queue_Throughput

کلاس جدید:

`QYS_Queue_Throughput`

قرارداد:

- mode = `adaptive`
- time budget = `20s`
- safety reserve = `2s`
- hard max = `30` پیام در هر Worker run
- initial estimated message cost = `1500ms`
- حداقل فاصله شروع دو outbound request = `150ms`
- Provider `429 / retryable_failed` ⇒ توقف همان run
- نتیجه `uncertain` ⇒ توقف همان run

Cap هر run از EMA هزینه واقعی outboundهای قبلی محاسبه می‌شود:

- اجرای سریع ⇒ cap بعدی رشد می‌کند
- اجرای کند ⇒ cap بعدی کاهش می‌یابد
- cap همیشه بین `1..30` باقی می‌ماند

اولین run با نبود telemetry قبلی cap=`12` دارد؛ بنابراین محدودیت ثابت تاریخی 5 پیام حذف شده، اما Worker از همان ابتدا با جهش ناگهانی به سقف 30 شروع نمی‌کند.

## 3. Safety و semantics صف

موارد زیر بدون تغییر باقی مانده‌اند:

- قفل `qys_batch_lock`
- lease سه‌دقیقه‌ای claim
- recovery محافظه‌کارانه sending منقضی
- `mark_outbound_started()` به‌عنوان آخرین مرز امن قبل از network I/O
- tenant permission check قبل از claim/send
- member eligibility قبل از network I/O
- `finish_delivery()` و stateهای B1/B2

D6 فقط تعداد کار قابل شروع در یک run و pacing را تطبیقی می‌کند.

در صورت Provider throttle:

- همان row با state موجود `retryable_failed` تمام می‌شود؛
- باقی صف در همان run ارسال نمی‌شود؛
- Cron بعدی با budget تازه اجرا می‌شود.

در صورت network/response نامشخص:

- row همان `uncertain` باقی می‌ماند؛
- Worker همان run را متوقف می‌کند تا موجی از outcomeهای نامشخص ایجاد نشود.

## 4. Telemetry بدون PII

Option غیر-autoload جدید:

`qys_queue_throughput`

فقط aggregateهای عملیاتی ذخیره می‌شوند:

- last cap
- rows scanned
- outbound attempted
- sent / retryable_failed / definitive_failed / uncertain / cancelled counts
- duration ms
- stop reason امن
- EMA message ms

هیچ member ID، campaign ID، chat ID، متن پیام، token یا raw network error ذخیره نمی‌شود.

## 5. Health Center

کارت جدید:

`Queue throughput`

انتظار ثابت قرارداد:

`mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`

Heartbeat نتیجه‌های امن جدید را نیز می‌پذیرد:

- `budget_exhausted`
- `rate_limited`
- `network_uncertain`

`budget_exhausted` یک stop طبیعی bounded-run است و Health issue ایجاد نمی‌کند. `rate_limited` و `network_uncertain` به‌عنوان warning عملیاتی نمایش داده می‌شوند، نه critical.

## 6. Migration D6

Migration جدید:

`20261006_020_rc31_adaptive_queue_throughput`

ویژگی‌ها:

- performance/code contract only
- متعلق به lifecycle module `messaging`
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.1`
- Ledger مورد انتظار پس از نصب: `20/20 completed`
- Messaging migration count پس از RC31 = `2`

Migration فقط وجود و صحت قرارداد adaptive را تأیید می‌کند.

## 7. تست‌های اجراشده

### Work Tree

- PHP syntax: `52/52 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D6 source/static contracts: `32/32 PASS`
- D6 throughput/runtime assertions: `25 PASS`
  - initial adaptive cap = `12`
  - fixed `pending_batch(5)` حذف شده
  - cap با EMA رشد/کاهش می‌کند
  - hard cap = `30`
  - success path چندپیامی PASS
  - Provider 429 روی پیام دوم ⇒ فقط `2` outbound و سپس stop
  - uncertain روی پیام اول ⇒ فقط `1` outbound و سپس stop
  - telemetry aggregate بدون identifier PASS
- RC30 → RC31 Migration regression: `7 assertions PASS`
  - Ledger `19/20 → 20/20`
  - bounded runner فقط Migration 020 را اجرا می‌کند
  - checkpoint = `complete`
- Hook call-site parity RC30→RC31: `53 actions / 5 filters / 2 shortcodes / 1 activation hook` = exact PASS
- فایل‌های Domain/Feature خارج از integration D6 بدون تغییر باقی مانده‌اند.

تمام syntax/static/runtime/migration تست‌ها پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا شدند و PASS هستند.

## 8. خارج از دامنه D6

عمداً انجام نشده‌اند:

- auto-retry برای `retryable_failed`
- reconciliation برای `uncertain`
- تغییر cron cadence یک‌دقیقه‌ای
- D7: Health snapshot سبک‌تر
- D8: Batch export
- D9: Birthday index
- D10: conditional asset loading عمومی
- UX/UI feature جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC30 را با RC31 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 020 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.1`
- Migration Ledger = `20/20 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `2`
- Members migration count = `2`
- Messaging migration count = `2`
- Queue dispatch index = `(status,id): ready | pending scan: indexed`
- Queue throughput = `mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Home summary = `endpoint: lightweight | programs: compact | forms: deferred`
- Mini-app feature load = `programs: lazy | attendance: lazy | data: on_view`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker/Cron/Retention سالم

وجود Pending=`0` در لحظه نصب مانع تأیید قرارداد D6 نیست؛ throughput واقعی فقط هنگام وجود صف مصرف می‌شود و Health card خود قرارداد adaptive را گزارش می‌کند.

## 10. Rollback

D6 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC30 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 020 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D7 — Health snapshot سبک‌تر»**
