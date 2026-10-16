# چک‌پوینت پروژه قرآن‌یار — Phase E / E10 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc45`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC44  
**مبنای ارتقا:** RC44 / Migration Ledger `33/33` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E10 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E10

اصلاح Progress پرونده در mini-app تا درصد تکمیل از یک منبع حقیقت Server ساخته شود و دیگر شمارش ساده فیلدهای non-empty در Client نتواند با `profile_complete` تناقض ایجاد کند.

## 2. قرارداد Profile Progress

قرارداد جدید:

`source: server | basis: canonical-required | fields: 7 | 100: complete-only | ui: incomplete-only`

هفت جزء لازم:

- دانشگاه فعال
- عنوان عضویت معتبر
- نام
- نام خانوادگی
- کد ملی موجود
- موبایل canonical معتبر
- تاریخ تولد canonical معتبر

`profile_complete()` از همین محاسبه استفاده می‌کند؛ بنابراین درصد و Gateهای دامنه دو منبع حقیقت جدا ندارند.

## 3. Status API

Status اکنون `profile_progress` برمی‌گرداند:

- `percent`
- `completed`
- `total`
- `complete`
- `missing`

`missing` فقط نام کلیدهای فیلد است و هیچ مقدار هویتی/تماسی در آن قرار نمی‌گیرد.

## 4. رفتار Client

- محاسبه قدیمی با `completeFields.filter(...)` حذف شد.
- Client فقط `profile_progress.percent` Server را render می‌کند.
- مقدار به بازه `0..100` clamp می‌شود.
- fallback پاسخ قدیمی API محافظه‌کارانه است: `100` فقط وقتی `profile_complete=true` باشد، در غیر این صورت `0`.
- Progress پرونده کامل همچنان پنهان می‌شود.

## 5. حالت بحرانی اصلاح‌شده

پیش از E10 ممکن بود همه فیلدهای ظاهری پر باشند اما دانشگاه عضو غیرفعال/نامعتبر باشد؛ Client `100٪` محاسبه می‌کرد در حالی که Server پرونده را ناقص می‌دانست.

در E10 دانشگاه فعال یکی از ۷ جزء canonical است؛ بنابراین این حالت `6/7 = 86٪` و `complete=false` می‌شود و `100٪` فقط برای پرونده واقعاً کامل تولید می‌شود.

## 6. Health Center و Migration

کارت جدید:

`Profile progress`

انتظار:

`source: server | basis: canonical-required | fields: 7 | 100: complete-only | ui: incomplete-only`

Migration جدید:

`20261006_034_rc45_profile_progress`

ویژگی‌ها:

- متعلق به lifecycle module `members`
- UX/state code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `34/34 completed`
- Members migration count پس از RC45 = `5`

## 7. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `12/12 PASS`
- CSS structural: `10/10 PASS`
- E10 source/static contracts: `22/22 PASS`
- E10 profile progress runtime: `24/24 PASS`
- E10 client progress contract: `8/8 PASS`
- E9 regression:
  - source/static `25/25 PASS`
  - contract runtime `9/9 PASS`
  - repository runtime `17/17 PASS`
  - client runtime `13/13 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC44

همین تست‌ها پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## 8. خارج از دامنه E10

عمداً انجام نشده‌اند:

- تغییر قواعد Tenant/Permission
- تغییر Messaging consent
- تغییر Program/Attendance workflow
- تغییر Schema فیزیکی
- Phase F UI System
- Manual UX Acceptance تجمیع‌شده E2–E10
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC44 را با RC45 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 034 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `34/34 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Members migration count = `5`
- Programs migration count = `9`
- Profile progress = `source: server | basis: canonical-required | fields: 7 | 100: complete-only | ui: incomplete-only`
- Bulk applicants آماده
- Field validation / Form draft / Async states آماده
- Runtime schema probes = `0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention سالم

## 10. Rollback

E10 هیچ Schema فیزیکی یا rewrite داده‌ای ندارد.

Rollback فایل به RC44 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 034 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از Production Health Verification

**Phase F / F1 — Design Tokens**
