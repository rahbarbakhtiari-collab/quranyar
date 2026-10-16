# چک‌پوینت پروژه قرآن‌یار — Phase D / D7 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc32`  
**Schema:** `2026.10.06.1` — بدون تغییر نسبت به RC31  
**مبنای ارتقا:** RC31 / Migration Ledger `20/20` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D7 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D7

بستن `QYS-P1-10 — Health Snapshot سنگین در هر دقیقه`.

در RC31 پایان هر اجرای دقیقه‌ای `qys_send_batch`، `QYS_Health_Center::snapshot(true)` را اجرا می‌کرد. این snapshot شامل aggregateهای Queue، Readiness چند دامنه، وضعیت Welcome/Activation/Birthday، Migration، Module Health و سایر telemetryهای کامل بود؛ بنابراین Worker حتی در صف خالی نیز هر دقیقه هزینه full-health را پرداخت می‌کرد.

هدف D7:

- heartbeat Worker در هر دقیقه فقط metadata سبک شروع/پایان را ثبت کند؛
- snapshot کامل سلامت روی schedule مستقل دوره‌ای اجرا شود؛
- صفحه Health و Ability همچنان بتوانند snapshot کامل تازه را on-demand محاسبه کنند؛
- هیچ PII جدیدی وارد telemetry نشود.

## 2. جداسازی Heartbeat از Full Snapshot

`heartbeat_finish()` دیگر `snapshot(true)` را فراخوانی نمی‌کند.

در هر Worker run فقط این موارد سبک ثبت می‌شوند:

- زمان شروع و پایان
- مدت اجرا
- source اجرا
- run id
- نتیجه امن Worker

بنابراین heartbeat یک‌دقیقه‌ای دیگر Queue aggregate، Birthday/Welcome/Activation counts یا Module Health کامل را محاسبه نمی‌کند.

## 3. Snapshot دوره‌ای مستقل

hook جدید:

`qys_health_snapshot`

schedule جدید:

`qys_health_15m` = هر `900` ثانیه

Health Center در `init` وجود event را تضمین می‌کند. callback دوره‌ای `scheduled_snapshot()` همان snapshot کامل فعلی را اجرا می‌کند و transitionهای Incident را ثبت می‌کند.

صفحه «سلامت سیستم» و Ability `qys-messenger/health` همچنان on-demand snapshot کامل تازه می‌سازند؛ بنابراین کاهش هزینه Worker باعث stale شدن مشاهده دستی سلامت نمی‌شود.

## 4. Telemetry دوره‌ای بدون PII

Option غیر-autoload:

`qys_health_snapshot_meta`

فقط aggregateهای زیر را نگه می‌دارد:

- آخرین زمان snapshot دوره‌ای
- مدت اجرای snapshot
- status نهایی `healthy / warning / critical`

هیچ member id، university id، chat id، message text، token یا raw network error ذخیره نمی‌شود.

## 5. قرارداد Performance و Health Center

قرارداد D7:

`heartbeat: lightweight | full: 15m/on-demand`

`QYS_Health_Center::snapshot_contract()` اعلام می‌کند:

- heartbeat = `lightweight`
- full = `periodic_on_demand`
- interval = `900s`
- worker_full_snapshot = `false`

Health Center کارت جدید `Health snapshot` را نمایش می‌دهد. وضعیت OK فقط وقتی است که قرارداد آماده و event دوره‌ای زمان‌بندی شده باشد.

## 6. Migration D7

Migration جدید:

`20261006_021_rc32_lightweight_health_snapshot`

ویژگی‌ها:

- performance/code contract only
- متعلق به lifecycle module `health`
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.1`
- Ledger مورد انتظار پس از نصب: `21/21 completed`
- Health module migration count پس از RC32 = `1`

Migration وجود contract، callback دوره‌ای و interval 900 ثانیه را verify می‌کند.

## 7. تست‌های اجراشده

- PHP syntax: `52/52 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D7 source/static contracts: `27/27 PASS`
- D7 runtime lightweight snapshot: `10/10 assertions PASS`
  - دو hook heartbeat حفظ شدند
  - hook مستقل snapshot ثبت شد
  - schedule دقیقاً 900 ثانیه است
  - اجرای heartbeat = `0` فراخوانی full queue health metrics
  - snapshot دوره‌ای = یک full metrics pass
  - on-demand snapshot همچنان fresh اجرا می‌شود
  - metadata دوره‌ای aggregate و بدون شناسه است
- D7 migration contract: `PASS`
- Hook call-site inventory RC32: `55 actions / 6 filters / 2 shortcodes / 1 activation hook`
  - افزایش نسبت به RC31 (`53/5/2/1`) فقط ناشی از `init` scheduler، hook مستقل `qys_health_snapshot` و filter schedule جدید است.
- D6 throughput contract و D5 queue index contract در static regression حفظ شدند.

همه syntax/static/runtime تست‌های بالا پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا می‌شوند و باید قبل از تحویل نهایی PASS باشند.

## 8. خارج از دامنه D7

عمداً انجام نشده‌اند:

- D8: Batch export
- D9: Birthday index
- D10: conditional asset loading عمومی
- تغییر cadence یک‌دقیقه‌ای Worker
- تغییر Queue throughput D6
- تغییر delivery-state semantics
- UX/UI feature جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC31 را با RC32 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 021 تغییر فیزیکی دیتابیس ندارد. automatic runner باید در اولین درخواست عادی آن را complete کند و event `qys_health_snapshot` توسط init زمان‌بندی شود.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.1`
- Migration Ledger = `21/21 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `2`
- Members migration count = `2`
- Messaging migration count = `2`
- Health migration count = `1`
- Queue dispatch index = `(status,id): ready | pending scan: indexed`
- Queue throughput = `mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`
- Health snapshot = `heartbeat: lightweight | full: 15m/on-demand`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Home summary = `endpoint: lightweight | programs: compact | forms: deferred`
- Mini-app feature load = `programs: lazy | attendance: lazy | data: on_view`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker/Cron/Retention سالم

## 10. Rollback

D7 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC31 نیازمند rollback دیتابیس نیست. Event دوره‌ای در صورت rollback بدون callback عملیاتی می‌ماند و ورود دوباره RC32 آن را reuse می‌کند؛ entry تکمیل‌شده Migration 021 نیز می‌تواند history Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D8 — Batch export»**
