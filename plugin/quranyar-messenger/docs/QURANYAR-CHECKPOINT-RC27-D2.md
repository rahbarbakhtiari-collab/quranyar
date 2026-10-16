# چک‌پوینت پروژه قرآن‌یار — Phase D / D2 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc27`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC26  
**مبنای ارتقا:** RC26 / Migration Ledger `15/15` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** D2 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف D2

بستن `QYS-P1-09 — N+1 سنگین در Programs` و بدهی `QYS-P2-07 — Export Program دارای N+1 روی Answers`، بدون تغییر business workflow یا Schema.

مسیرهای هدف:

- لیست Program در برنامک و hydrate کردن فرم‌ها
- شمارنده‌های Program در پنل
- پاسخ‌های متقاضیان در صفحه جزئیات
- پاسخ‌های CSV export
- Admission snapshot که چهار roundtrip مستقل داشت

## 2. Batch فرم‌های Program

متد جدید:

`QYS_Program_Repository::current_form_fields_for_programs()`

فرم منتشرشده و fieldهای فعال چند Program را در **یک query** hydrate می‌کند و خروجی را با `program_id` گروه‌بندی می‌کند.

`rest_list()` دیگر برای هر Program جداگانه `current_form()` و `form_fields()` اجرا نمی‌کند.

نتیجه: query count فرم‌ها با تعداد Program رشد خطی ندارد.

## 3. Grouped Counts

متد جدید:

`QYS_Program_Repository::counts_for_programs()`

برای حداکثر ۱۵۰ Program در Index:

- Applications در یک query grouped
- Enrollments در یک query grouped

محاسبه مجموع و کارت‌های هر Program از همین map انجام می‌شود.

مسیر قبلی تا سه count query برای هر Program ایجاد می‌کرد؛ مسیر جدید **۲ query ثابت** دارد.

## 4. Batch Answers

متد جدید:

`QYS_Program_Repository::answers_for_applications()`

کاربردها:

- صفحه جزئیات Program: پاسخ‌های حداکثر ۵۰۰ Application در یک query
- CSV export: پاسخ‌های حداکثر ۱۰۰۰ Application در یک query

`answers_for_application()` برای backward compatibility باقی مانده و روی batch API یک application delegate می‌کند.

## 5. Admission counts

`admission_counts()` از چهار roundtrip مستقل به **یک query aggregate** تبدیل شد، بدون تغییر semanticهای `used / waitlisted / accepted_pending / active_enrollments`.

## 6. Health Center

کارت readiness جدید:

`Programs query plan`

انتظار:

`forms: batch | counts: grouped | answers: batch`

این کارت هیچ query اضافه‌ای اجرا نمی‌کند و فقط contract کد D2 را گزارش می‌کند.

## 7. Migration D2

Migration جدید:

`20261006_016_rc27_program_batch_queries`

ویژگی‌ها:

- Performance/infrastructure-only
- متعلق به lifecycle module `programs`
- بدون table/column جدید
- بدون rewrite داده دامنه
- وجود batch/grouped APIها و performance contract را تأیید می‌کند
- Ledger مورد انتظار: `16/16 completed`
- Schema همچنان `2026.10.05.7`

پس از RC27، migration count ماژول `programs` برابر `2` خواهد بود (C6 + D2).

## 8. Query-bound تست‌شده

Runtime test با dataset بزرگ‌تر از UI واقعی:

- Form hydration: `1 query`
- Counts برای `150 Programs`: `2 queries`
- Answers برای `1000 Applications`: `1 query`
- Admission snapshot: `1 query`

بنابراین این مسیرها نسبت به تعداد row، query count ثابت دارند و N+1 حذف شده است.

## 9. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- D2 source/static contracts: `20/20 PASS`
- D2 Program batch runtime: `24 assertions PASS`
- RC26 → RC27 Migration regression: `17 assertions PASS`; Ledger `15/16 → 16/16`
- D1 source/static contracts: `27/27 PASS`
- D1 runtime schema guard: `10 assertions PASS`; runtime SHOW queries = `0`
- C7 Module Registry/Lifecycle runtime: `35 assertions PASS`; `17/17 modules`, `10 features`
- C6 source/static contracts: `35/35 PASS`
- C6 Repository runtime: `15 assertions PASS`
- C5 regressions: `12 + 10 + 7 + 6 assertions PASS`
- Full plugin boot smoke: `21 assertions PASS`; `17/17 modules booted`
- Hook parity RC26 → RC27: actions `53=53`, filters `4=4`, shortcodes `2=2`, activation hooks `1=1`

## 10. خارج از دامنه D2

عمداً انجام نشده‌اند:

- D3: Home Summary endpoint سبک
- D4: Lazy load Programs/Attendance در برنامک
- D5: Queue index `(status,id)`
- D6: Queue throughput adaptive
- D7: Health snapshot سبک
- D8: Batch export زیرساخت عمومی
- D9: Birthday index
- D10: conditional asset loading
- N+1 احتمالی cross-domain در member activity/Attendance؛ D2 فقط query explosion ثبت‌شده Programs/forms/counts/export را می‌بندد
- UX/UI یا Feature محصولی جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 11. نصب روی Production

RC26 را با RC27 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 016 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را اجرا کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `16/16 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `2`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed = `0`
- blocked = `0`
- degraded = `0`
- Worker/Cron/Retention سالم
- Queue بدون وضعیت غیرعادی

## 12. Rollback

D2 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC26 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 016 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase D / D3 — Home Summary Endpoint»**
