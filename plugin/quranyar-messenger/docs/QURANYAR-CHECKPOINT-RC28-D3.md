# چک‌پوینت پروژه قرآن‌یار — Phase D / D3 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc28`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC27  
**مبنای ارتقا:** RC27 / Migration Ledger `16/16` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D3 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D3

بازشدن Home نباید برای نمایش سه کارت «آخرین برنامه‌ها» payload کامل `programs/list` و Form/Fieldهای همه Programها را دریافت کند.

هدف این مرحله ایجاد یک read model کوچک و مستقل برای Home است؛ Featureهای کامل Programs و Attendance قراردادهای خود را حفظ می‌کنند.

## 2. endpoint جدید Home Summary

REST جدید:

`POST /qys/v1/home-summary`

ویژگی‌ها:

- احراز هویت همان Eitaa initData موجود
- بدون cache حاوی داده شخصی
- فقط برای عضو دارای پرونده کامل و هویت تأییدشده، Program summary برمی‌گرداند
- حداکثر `3` Program برای Home
- فقط ستون‌های لازم کارت Home
- بدون Form Version / Field / Answer / Counts / Attendance detail

## 3. Program read model فشرده

متد جدید Repository:

`QYS_Program_Repository::home_summaries_for_member()`

ویژگی‌ها:

- یک query محدود و bounded
- فقط Programهای جاری: `registration_open / registration_closed / confirmed / running`
- tenant و audience role همچنان رعایت می‌شوند
- application/enrollment state عضو برای برچسب Home حفظ می‌شود
- هیچ hydrate کردن فرم یا پاسخ انجام نمی‌شود

## 4. رفتار mini-app

`qys-programs-public.js` برای Home دیگر `QYSPrograms.listEndpoint` را صدا نمی‌زند.

Home از `homeSummaryEndpoint` استفاده می‌کند؛ endpoint کامل `programs/list` فقط برای نمای کامل Programs باقی مانده است.

بنابراین Home دیگر برای نمایش Programها به فرم کامل ثبت‌نام وابسته نیست.

## 5. Health Center

کارت جدید:

`Home summary`

انتظار:

`endpoint: lightweight | programs: compact | forms: deferred`

این کارت query جدید اجرا نمی‌کند و فقط قرارداد کد را گزارش می‌کند.

## 6. Migration D3

Migration جدید:

`20261006_017_rc28_home_summary`

ویژگی‌ها:

- Infrastructure / performance contract only
- متعلق به lifecycle module `members`
- بدون table/column جدید
- بدون rewrite داده دامنه
- Ledger مورد انتظار پس از نصب: `17/17 completed`
- Schema همچنان `2026.10.05.7`

پس از RC28، migration count ماژول `members` برابر `1` خواهد بود. Programs migration count همچنان `2` است.

## 7. تست‌های اجراشده

- PHP syntax: `51/51 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D3 source/static contracts: `23/23 PASS`
- D3 Home Summary runtime: `16 assertions PASS`
  - Home Program query = `1`
  - Home Programs = حداکثر `3`
  - Form/Field/Answer query در Home = `0`
- RC27 → RC28 Migration regression: `17 assertions PASS`
  - Ledger `16/17 → 17/17`
- Full plugin boot smoke: `23 assertions PASS`
  - modules `17/17`
  - Programs migrations `2`
  - Members migrations `1`
- D2 contracts: `20/20 PASS`
- D2 batch runtime: `24 assertions PASS`
- D1 contracts: `27/27 PASS`
- D1 runtime schema guard: `10 assertions PASS`؛ runtime SHOW queries=`0`
- C7 Registry/Lifecycle runtime: `36 assertions PASS`
- C6 source: `35/35 PASS`
- C6 Repository runtime: `15 assertions PASS`
- C5 regressions: `12 + 10 + 7 + 6 assertions PASS`
- Hook parity RC27 → RC28:
  - actions `53 = 53 PASS`
  - filters `4 = 4 PASS`
  - shortcodes `2 = 2 PASS`
  - activation hooks `1 = 1 PASS`

## 8. خارج از دامنه D3

عمداً انجام نشده‌اند:

- D4: Lazy load Programs/Attendance در برنامک
- D5: Queue index `(status,id)`
- D6: Queue throughput adaptive
- D7: Health snapshot سبک‌تر
- D8: Batch export
- D9: Birthday index
- D10: conditional asset loading
- UX/UI یا Feature محصولی جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC27 را با RC28 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 017 تغییر فیزیکی دیتابیس ندارد و automatic runner باید روی اولین درخواست عادی آن را اجرا کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `17/17 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `2`
- Members migration count = `1`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Home summary = `endpoint: lightweight | programs: compact | forms: deferred`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker/Cron/Retention سالم

## 10. Rollback

D3 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC27 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 017 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D4 — Lazy load Programs/Attendance»**
