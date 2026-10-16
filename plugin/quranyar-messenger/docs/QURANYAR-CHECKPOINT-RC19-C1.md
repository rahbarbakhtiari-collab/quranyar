# چک‌پوینت پروژه قرآن‌یار — Phase C / C1 آماده نصب

**تاریخ:** 2026-10-05  
**نسخه:** QuranYar Messenger `1.3.1-rc19`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC18  
**مبنای ارتقا:** RC18  
**وضعیت:** C1 Implemented + Local/Artifact Tests PASS — Pending Production Ledger/Health Verification

---

## هدف C1

ایجاد Migration Ledger مستقل، ترتیبی، idempotent و checkpointدار بدون replay کردن Migrationهای تاریخی RC12 تا RC18.

## رفتار روی Production موجود RC18

- `qys_install_state=ready` و Schema `2026.10.05.7` به‌عنوان baseline معتبر شناخته می‌شود.
- ۷ migration تاریخی RC12 تا RC18 فقط با status=`completed` وارد Ledger می‌شوند.
- هیچ `ensure_schema`، backfill یا terminal reconciliation تاریخی دوباره اجرا نمی‌شود.
- Migration C1 با ID `20261005_008_rc19_migration_ledger` یک بار اجرا و completed می‌شود.
- Schema دیتابیس تغییر نمی‌کند.

## قرارداد Migration جدید

هر migration دارای:

- شناسه ترتیبی
- status: `pending / running / completed / failed`
- attempts
- plugin_version
- schema_version
- started/completed/failed timestamps
- checkpoint
- error_code امن و بدون PII

Migration completed دوباره اجرا نمی‌شود. Migration failed قابل retry است و checkpoint حفظ می‌شود.

## Lock و Recovery

- Lock مستقل Migration Ledger
- TTL برابر ۵ دقیقه
- runner دوم هنگام Lock فعال متوقف می‌شود
- Lock رهاشده پس از TTL بازیابی می‌شود
- شناسه retroactive قدیمی‌تر/مساوی C1 ثبت نمی‌شود

## Health Center

پس از نصب و اولین ورود مدیر انتظار می‌رود:

- Schema = `2026.10.05.7`
- Migration ledger = Ready
- completed = 8 / total = 8
- pending = 0
- running = 0
- failed = 0
- ۷ ردیف baseline با attempts=0
- C1 با attempts=1 و checkpoint=`complete`

failed یا running stale در Health Center بحرانی است.

## تست‌های C1

- Migration Ledger runtime: PASS
- Existing RC18 upgrade without replay: PASS
- Fresh install compatibility: PASS
- Future migration path without legacy replay: PASS
- Checkpoint + failed retry: PASS
- Concurrent lock: PASS
- Stale-lock recovery: PASS
- Historical baseline version/schema preservation: PASS
- Phase A regression: PASS
- B1–B6 regression: PASS
- Plugin boot smoke: PASS

## خارج از دامنه C1

- وابستگی Upgrade به `admin_init` هنوز باقی است؛ این موضوع در C2 رفع می‌شود.
- Live Edge Acceptance قبلی Phase A+B همچنان Deferred و در Roadmap ثبت است.

## گام بعدی پس از Production Verification

**Phase C / C2 — Upgrade Trigger مستقل از admin_init**
