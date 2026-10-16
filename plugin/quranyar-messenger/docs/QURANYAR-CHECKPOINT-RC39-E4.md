# چک‌پوینت پروژه قرآن‌یار — Phase E / E4 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc39`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC38  
**مبنای ارتقا:** RC38 / Migration Ledger `27/27` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E4 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E4

بستن `QYS-P2-24 — Program مشخصات اصلی قابل ویرایش نیست` بدون شکستن Tenant boundary، Eligibility، Capacity reservation یا سابقه Attendance.

مشخصات اصلی برنامه اکنون بر اساس state و مالکیت به‌صورت کنترل‌شده قابل ویرایش‌اند.

## 2. قرارداد ویرایش

`QYS_Programs_Module::program_edit_contract()`:

`mode: state-aware | capacity: reserved-floor | audience: draft-only | attendance: pre-link | terminal: read-only`

Policy سروری `QYS_Program_Service::edit_policy()` مرجع نهایی است و UI فقط آن را نمایش می‌دهد.

### Draft

- Title: قابل ویرایش
- Description: قابل ویرایش
- Capacity: قابل ویرایش
- Audience roles: قابل ویرایش
- دانشگاه‌های مخاطب: فقط برای مدیرکل قابل ویرایش؛ دانشگاه مدیر باید در دامنه باقی بماند
- Attendance enabled: تا قبل از ایجاد link قابل ویرایش

### Registration open / closed

- Title / Description: قابل ویرایش
- Capacity: قابل ویرایش با کف تعهد جاری
- Audience: قفل
- Attendance enabled: تا قبل از ایجاد link قابل ویرایش

### Confirmed

- Title / Description: قابل ویرایش
- Capacity: قابل ویرایش با کف تعهد جاری
- Audience: قفل
- Attendance enabled: تا قبل از ایجاد link قابل ویرایش

### Running

- فقط Title / Description قابل اصلاح است.
- Capacity / Audience / Attendance configuration قفل هستند.

### Completed / Cancelled / Archived

- مشخصات اصلی فقط خواندنی هستند.

## 3. Capacity safety

ویرایش ظرفیت با `Admission Policy` فعلی هماهنگ است.

- ظرفیت `0` همچنان یعنی بدون سقف.
- ظرفیت محدود جدید نمی‌تواند از تعداد صندلی‌های `accepted/enrolled/participating` رزروشده یا فعال کمتر باشد.
- check داخل transaction و بعد از lock برنامه انجام می‌شود تا هم‌زمانی پذیرش، کف ظرفیت را دور نزند.

## 4. Audience و Tenant safety

- فقط owner برنامه یا مدیرکل می‌تواند Edit workflow را اجرا کند.
- برنامه مشترک برای نماینده دانشگاه غیرمالک همچنان فقط read/applicant-scope است.
- `audience_roles` فقط در Draft تغییر می‌کند تا Eligibility درخواست‌های موجود بازتعریف نشود.
- target universities فقط در Draft و فقط توسط مدیرکل تغییر می‌کنند.
- `manager_university` در E4 قابل تغییر نیست و باید در target set باقی بماند.

## 5. Attendance safety

- قبل از ساخت Attendance link، `attendance_enabled` در stateهای مجاز قابل روشن/خاموش کردن است.
- پس از ایجاد link واقعی حضور، تنظیم حضور immutable می‌شود و disable سروری رد می‌شود.
- ایجاد QR و lifecycle حضور همچنان از `QYS_Program_Attendance_Boundary` عبور می‌کند.

## 6. UI

در جزئیات برنامه، بخش جدید «ویرایش برنامه» اضافه شده است.

- quick-nav لینک «مشخصات» دارد.
- فقط فیلدهای مجاز state فعلی editable هستند.
- فیلدهای قفل‌شده دلیل کوتاه دارند.
- برنامه terminal یک summary خواندنی نشان می‌دهد و submit ندارد.
- هیچ تغییر خودکاری در state، application یا enrollment رخ نمی‌دهد.

## 7. Persistence و Audit

Repository مالک write باقی مانده است:

- `update_program_details()`
- `replace_program_universities()`

هر ویرایش موفق `program_updated` را در Program audit ثبت می‌کند.

## 8. Health Center و Migration

کارت جدید:

`Program edit`

انتظار:

`mode: state-aware | capacity: reserved-floor | audience: draft-only | attendance: pre-link`

Migration جدید:

`20261006_028_rc39_program_edit_workflow`

ویژگی‌ها:

- متعلق به lifecycle module `programs`
- UX/workflow code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `28/28 completed`
- Programs migration count پس از RC39 = `5`

## 9. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `11/11 PASS`
- CSS structural: `10/10 PASS`
- E4 source/static contracts: `31/31 PASS`
- E4 runtime state/capacity/tenant tests: `21/21 PASS`
- E4 module contract: `8/8 PASS`
- E4 migration contract: `PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC38

تست‌های بالا پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## 10. خارج از دامنه E4

عمداً انجام نشده‌اند:

- تغییر Program Type
- تغییر manager university / delegation
- تغییر Registration Mode
- unlink یا جابه‌جایی Attendance link موجود
- E5: Member Withdraw
- E6: Error/Empty/Loading استاندارد
- E7: Draft فرم کاربر
- E8: validation field-level
- E9: bulk applicant management
- E10: اصلاح progress پرونده
- تست دستی روتین UX/E2E؛ طبق تصمیم محصول در پایان پروژه تجمیع می‌شود

## 11. نصب روی Production

RC38 را با RC39 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 028 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `28/28 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `5`
- Program edit = `mode: state-aware | capacity: reserved-floor | audience: draft-only | attendance: pre-link`
- Destructive confirmation = `program: complete/cancel/archive | applicant: reject | server: required`
- Programs access gate = `mode: explain | cta: explicit | redirect: none`
- Membership / messaging = `membership: independent | messaging: optional | default: off`
- قراردادهای Phase D سالم
- Runtime schema probes = `0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention سالم

## 12. Rollback

E4 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC38 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 028 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«Phase E / E5 — Member Withdraw»**
