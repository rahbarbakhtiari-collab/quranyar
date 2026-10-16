# Acceptance Pass نهایی Phase A + B — قرآن‌یار RC18

**تاریخ:** 2026-10-05  
**نسخه مبنا:** QuranYar Messenger `1.3.1-rc18`  
**Schema:** `2026.10.05.7`  
**وضعیت:** Automated/Artifact Acceptance = PASS؛ Live Edge Acceptance = Remaining

---

## 1. Production Health مبنا

بر اساس آخرین Health Center ارسالی کاربر:

- وضعیت کلی: سالم
- Schema: `2026.10.05.7`
- Worker و Cron فعال
- Pending = 0
- Sending = 0
- نیازمند بررسی = 0
- Lease فعال/منقضی/بدون Lease = 0
- Batch lock آزاد
- Retention آخرین اجرا: حدود 2 دقیقه قبل
- Retention result = `ok`
- Retention changed count = 0

نتیجه: B6 از نظر Production Health و اجرای واقعی اولین Cleanup تأیید شده است.

---

## 2. Automated Acceptance — اجرا روی ZIP نهایی استخراج‌شده RC18

همه تست‌های زیر دوباره روی سورس نهایی استخراج‌شده RC18 اجرا شدند و PASS شدند.

### Phase A — Tenant & Data Boundaries

- Source contracts: 26/26 PASS
- Cross-tenant contracts: 14/14 PASS

موارد پوشش‌داده‌شده:

- University Transfer workflow
- حفظ دانشگاه فعلی تا تأیید مقصد
- تأیید مقصد با Scope صحیح
- جلوگیری از انتقال در حضور باز
- Program visibility/ownership separation
- Application tenant snapshot
- Enrollment tenant snapshot
- Applicant action scope
- Attendance tenant binding
- Attendance export scope
- Program export scope
- Sensitive export permission scope
- Legacy unresolved shared rows fail-closed
- CSV injection protection
- Eligibility revalidation در Submit/Accept/Enroll/Attendance

---

## 3. Phase B / B1 — Delivery State

- 16 classification + normalization PASS

تأیید شد:

- sent
- retryable_failed
- definitive_failed
- uncertain
- network ambiguity handling

---

## 4. Phase B / B2 — Sending Lease & Recovery

Repository lease/recovery: PASS

موارد:

- expired preflight → pending
- expired post-outbound → uncertain
- legacy unleased sending → uncertain
- active lease untouched
- old worker cannot cross network after recovery
- attempts increment
- outbound marker persisted
- finish clears lease
- migration idempotent

QueueService race/order: PASS

موارد:

- recovery قبل از انتخاب pending
- claim → outbound marker → gateway → finish
- failed marker prevents network send
- failed marker prevents finalize
- batch lock cleanup

Schema guard: PASS

---

## 5. Phase B / B3 — Welcome / Activation Reliability

Runtime-like: 18 سناریو PASS

نمونه‌ها:

- welcome only once
- missing token keeps pending
- uncertain never auto-resend
- definitive failure not retried
- retryable obeys due time/backoff
- retry limit enforced
- scope change cancels pending retry
- activation requires verified member
- activation network uncertainty persists
- legacy migration conservative

Low-level recovery: 5 سناریو PASS

---

## 6. Phase B / B4 — Program Terminal State

Domain scenarios: 26 PASS

موارد:

- completed orchestration
- cancelled orchestration
- archived reconciliation
- preservation of historical decisions
- participating → completed
- enrolled → no_show
- active → cancelled
- terminal state cannot reopen
- terminal migration idempotent
- attendance failure triggers rollback

Attendance terminalization: 6/6 PASS

---

## 7. Phase B / B5 — Capacity / Waitlist

Admission scenarios: 16/16 PASS

موارد:

- global shared-program capacity
- accepted reservation + active enrollment exact counting
- full capacity blocks accept
- waitlist enabled/disabled enforcement
- last-seat anti-overbooking
- waitlisted → accepted when seat exists
- accepted → enrollment no double charge
- legacy over-capacity detection
- accepted withdrawal releases seat
- terminal enrollment releases seat
- unlimited capacity
- full/no-waitlist blocks submit
- full/waitlist-enabled permits submit

---

## 8. Phase B / B6 — Retention

Retention scenarios: 20 PASS

موارد:

- daily event scheduled
- scheduled first-run state
- cleanup success
- terminal queue payload minimization
- Excel follow-up anonymization
- Birthday two-stage retention
- terminal log deletion
- old audit deletion
- campaign transactional purge
- PII-free telemetry
- no raw error persistence
- protected nonterminal SQL states
- uncertain/sending birthday protection
- cleanup lock
- failed-cleanup telemetry
- concurrency protection

---

## 9. Plugin/Artifact integrity

- Plugin include/init smoke: PASS
- Registered features: 10
- RC18 final artifact used for tests
- No source modification performed during Acceptance Pass

---

# 10. وضعیت نهایی Automated Acceptance

**Phase A automated acceptance: PASS**  
**Phase B automated acceptance: PASS**  
**RC18 Production Health/B6 first cleanup: PASS**

بنابراین هیچ مانع کدی شناخته‌شده‌ای از تست‌های موجود برای ورود به Live Edge Acceptance باقی نمانده است.

---

# 11. Live Edge Acceptance باقی‌مانده

این موارد فقط در محیط واقعی و با دسترسی عملیاتی قابل اثبات‌اند و نباید از تست‌های Stub نتیجه‌گیری شوند.

## A — Cross-Tenant

1. ایجاد/استفاده از دو دانشگاه A و B
2. Representative A نتواند داده B را ببیند
3. Representative B نتواند داده A را ببیند
4. Program مشترک:
   - visibility برای هر دو
   - management فقط مالک/مدیرکل
   - applicants هر Tenant فقط همان Tenant
5. Export هر Tenant فقط رکوردهای خودش
6. University Transfer واقعی:
   - درخواست انتقال
   - عدم تغییر قبل از تأیید مقصد
   - تأیید مقصد
   - جلوگیری در صورت Attendance باز

## B2 — Worker crash/recovery واقعی

1. stale sending قبل از outbound → pending
2. stale sending بعد از outbound → uncertain
3. uncertain هرگز auto resend نشود

## B3 — Welcome/Activation failure واقعی

1. retryable failure
2. definitive failure
3. uncertain
4. backoff واقعی
5. عدم resend برای uncertain

## B4 — Program terminal واقعی

1. running → completed
2. running → cancelled
3. occurrence باز هنگام terminal transition
4. late checkout پس از پایان
5. جلوگیری از ورود جدید
6. جلوگیری از reopen Attendance
7. Member Record نهایی

## B5 — Capacity/Waitlist واقعی

1. ظرفیت 1
2. پذیرش نفر اول
3. رد پذیرش نفر دوم
4. Waitlist نفر دوم
5. آزادشدن صندلی
6. ارتقا به Accepted
7. Enrollment بدون double count
8. Waitlist disabled
9. دو دانشگاه هم‌زمان روی آخرین صندلی

## B6 — Retention واقعی با داده مصنوعی قدیمی

1. terminal old data anonymize/purge
2. pending old data preserved
3. uncertain old data preserved
4. audit/campaign purge bounded
5. Health result/counters صحیح

---

# 12. نتیجه و Gate ورود به Phase C

از نظر **کد، artifact و Production health**، Phase B کامل است.

از نظر فرآیند ممیزی، Phase A و B زمانی Fully Closed خواهند شد که Live Edge Acceptance بالا نیز اجرا شود.

اگر دسترسی مستقیم/ChatGPT Desktop برای این سناریوها هنوز در دسترس نیست، می‌توان Phase C را با وضعیت:

**A+B Code Complete / Live Edge Acceptance Deferred**

شروع کرد، مشروط بر اینکه این لیست به‌عنوان Blocking Acceptance Debt در Roadmap باقی بماند.

اولین گام Phase C:

**C1 — Migration Ledger**

هدف: جایگزینی مدل تک `SCHEMA_VERSION` با Migrationهای مستقل، ترتیبی، idempotent و checkpointدار.
