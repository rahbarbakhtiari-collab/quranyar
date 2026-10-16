# چک‌پوینت پروژه قرآن‌یار — Phase B / B4 آماده نصب

**تاریخ:** 2026-10-05  
**نسخه:** QuranYar Messenger `1.3.1-rc16`  
**Schema هدف:** `2026.10.05.7`  
**مبنای ارتقا:** RC15 / Schema `2026.10.05.6`  
**وضعیت:** B4 Implemented + Local/Artifact Tests PASS — Pending Production Verification

---

## 1. وضعیت مسیر پروژه

- Phase A: پیاده‌سازی و Health Production تأیید؛ Live Cross-Tenant Acceptance همچنان Deferred.
- Phase B / B1: Production Verified.
- Phase B / B2: Production Verified.
- Phase B / B3: Production Health Verified.
- Phase B / B4: در RC16 پیاده‌سازی شده و تست‌های قابل اجرای محلی پاس شده است.
- گام بعدی پس از نصب و تأیید Health: Phase B / B5.

---

## 2. هدف B4

یک Program پایانی نباید در زیرسیستم‌های دیگر همچنان «فعال»، «در حال بررسی» یا «قابل حضور» باقی بماند.

وضعیت‌های پایانی:

- `completed`
- `cancelled`
- `archived`

زیرسیستم‌های هماهنگ‌شده:

- Application
- Enrollment
- Attendance Program
- occurrenceهای باز
- Member Record
- عملیات مدیریتی پس از پایان

---

## 3. سیاست وضعیت پایانی

### Program completed

- `participating → completed`
- `enrolled → no_show`
- Applicationهای `submitted / under_review / waitlisted → closed`
- تصمیم‌های تاریخی `accepted / rejected / withdrawn` حفظ می‌شوند.
- Attendance Program بسته می‌شود.
- occurrenceهای dynamic باز بسته می‌شوند.
- خروج‌های باز Attendance حذف یا خودکار تکمیل نمی‌شوند.

### Program cancelled

- Enrollmentهای فعال → `cancelled`
- Applicationهای بلاتکلیف → `cancelled`
- تصمیم‌های تاریخی قبلی حفظ می‌شوند.
- Attendance Program و occurrenceهای باز بسته می‌شوند.
- وضعیت `cancelled` از `withdrawn` جداست تا لغو توسط برگزارکننده با انصراف عضو اشتباه نشود.

### Program archived

برای داده‌های قدیمی که معلوم نیست قبل از آرشیو «پایان عادی» بوده‌اند یا «لغو»، وضعیت خنثی استفاده می‌شود:

- Enrollment فعال → `closed`
- Application بلاتکلیف → `closed`

به این ترتیب Migration تاریخچه‌ای را که مدرک کافی برای آن وجود ندارد جعل نمی‌کند.

---

## 4. Attendance

در terminal شدن Program:

- Attendance Program متصل `closed` می‌شود.
- `current_session_id = 0`
- تمام occurrenceهای dynamic باز مرتبط بسته می‌شوند.
- رکورد Attendance بازی که خروج ندارد حفظ می‌شود.
- عضو می‌تواند خروج واقعی خود را بعداً ثبت کند.
- ثبت ورود جدید ممکن نیست.
- Attendance Program متصل به Program پایانی از پنل دوباره فعال نمی‌شود.
- QR حضور تازه برای Program پایانی ساخته نمی‌شود.

---

## 5. Migration

RC16 تغییر ساختاری جدول جدیدی ندارد.

Schema Version به `2026.10.05.7` افزایش یافته تا Data Reconciliation یک بار و به‌صورت کنترل‌شده اجرا شود.

Migration:

`QYS_Program_Service::reconcile_terminal_programs()`

ویژگی‌ها:

- idempotent
- فقط Programهای `completed / cancelled / archived`
- حفظ داده‌های تاریخی تصمیم‌گرفته‌شده
- هماهنگ‌سازی Enrollment/Application فعال
- بستن Attendance وابسته

---

## 6. تست‌های اجراشده

- PHP syntax: 34/34 PASS
- JavaScript syntax: 10/10 PASS
- CSS structural check: 10/10 PASS
- B4 source contracts: 24/24 PASS
- B4 domain/runtime-like scenarios: PASS
- B4 Attendance terminalization/re-open scenarios: PASS
- Phase A source regression: 26/26 PASS
- Phase A cross-tenant source regression: 14/14 PASS
- B1 Delivery State regression: PASS
- B2 Queue Lease/Recovery regression: PASS
- B3 Welcome/Activation regression: PASS
- Plugin boot smoke: PASS
- ZIP re-extraction + final lint/contracts: PASS

---

## 7. مواردی که عمداً در B4 انجام نشده‌اند

- B5: Capacity / Waitlist Policy
- B6: Retention Policy
- UX confirmation برای actionهای مخرب (Phase E)
- refactor وابستگی Programs ↔ Attendance (Phase C)

B4 فقط سازگاری State را اصلاح می‌کند.

---

## 8. پس از نصب روی Production

RC15 را با RC16 جایگزین کنید؛ افزونه حذف و از صفر نصب نشود.

یک بار وارد `wp-admin` شوید تا Migration اجرا شود.

در Health Center انتظار می‌رود:

- Schema = `2026.10.05.7`
- وضعیت کلی = سالم
- Worker و Cron سالم
- Queue/Lease وضعیت غیرعادی نداشته باشند

وجود Program پایانی قدیمی ممکن است در Migration باعث اصلاح stateهای وابسته آن شود؛ این رفتار مورد انتظار است و داده حذف نمی‌شود.

---

## 9. Live Acceptance Deferred

در تست جامع زنده بعدی بهتر است حداقل این سناریوها اجرا شوند:

1. Program running با Enrollment participating → completed
2. Program running → cancelled
3. Program دارای Attendance occurrence باز → completed/cancelled
4. ثبت خروج بعد از بسته‌شدن Program
5. تلاش برای ورود جدید بعد از terminal شدن Program
6. تلاش برای فعال‌کردن دوباره Attendance Program متصل
7. بررسی Member Record پس از completed/cancelled

---

## دستور اجرایی بعدی پس از تأیید Production

**«مرحله بعدی — Phase B / B5»**
