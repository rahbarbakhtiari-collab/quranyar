# چک‌پوینت پروژه قرآن‌یار — Phase E / E5 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc40`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC39  
**مبنای ارتقا:** RC39 / Migration Ledger `28/28` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E5 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E5

بستن `QYS-P2-25 — عضو امکان Withdraw ندارد` بدون حذف سوابق تاریخی و بدون تغییر Domain transitionهای موجود.

Stateهای `withdrawn` از قبل در Application و Enrollment وجود داشتند؛ E5 مسیر واقعی self-service عضو را روی همین state machine موجود اضافه می‌کند.

## 2. قرارداد Member Withdraw

`QYS_Programs_Module::member_withdraw_contract()`:

`mode: self-service | application: withdrawable | enrollment: withdrawable | confirm: explicit | history: preserved`

اصول:

- فقط عضو تأییدشده و فقط برای رکورد خودش می‌تواند انصراف ثبت کند.
- Applicationهای `submitted / under_review / waitlisted / accepted` قابل Withdraw هستند، مشروط به نبود Enrollment.
- Enrollmentهای `enrolled / participating` قابل Withdraw هستند.
- Programهای `completed / cancelled / archived` انصراف جدید نمی‌پذیرند.
- وضعیت‌های terminal قبلی دوباره Withdraw نمی‌شوند.
- هیچ Application/Enrollment/Attendance history حذف نمی‌شود.

## 3. REST و Ownership

Endpoint جدید:

`POST /qys/v1/programs/withdraw`

ویژگی‌ها:

- همان احراز معتبر Eitaa/initData و `request_member()` را مصرف می‌کند.
- `program_id` دریافت می‌کند و member id را فقط از هویت احرازشده سرور می‌گیرد؛ member id دلخواه از client پذیرفته نمی‌شود.
- marker صریح `confirmed=1` برای جلوگیری از انصراف ناخواسته لازم است.
- rate limit مستقل `program_withdraw = 12/min` دارد.
- Backend دوباره state و ownership را داخل transaction بررسی می‌کند.

## 4. Application Withdrawal

اگر Enrollment وجود نداشته باشد و Application فعال باشد:

- status → `withdrawn`
- `updated_at_utc` به‌روز می‌شود.
- پاسخ‌های فرم و Application row حذف نمی‌شوند.
- `member_withdrawal` در Program Audit ثبت می‌شود.
- اگر Application در `accepted` بوده، seat reservation آزاد می‌شود چون admission snapshot فقط accepted بدون Enrollment را فعال حساب می‌کند.

## 5. Enrollment Withdrawal

اگر Enrollment فعال وجود داشته باشد، Enrollment بر Application اولویت دارد:

- `enrolled / participating` → `withdrawn`
- `withdrawn_at_utc` ثبت می‌شود.
- Application پذیرفته‌شده و Attendance قبلی حذف/بازنویسی نمی‌شوند.
- History واقعی شرکت، در صورت وجود participation/attendance، باقی می‌ماند.
- capacity commitment فعال آزاد می‌شود.

## 6. Mini-app UX

در Programs member view:

- برای Application فعال دکمه «پس‌گرفتن درخواست» نمایش داده می‌شود.
- برای Enrollment فعال دکمه «انصراف از برنامه» نمایش داده می‌شود.
- قبل از درخواست شبکه، confirmation صریح با توضیح اثر نمایش داده می‌شود.
- بعد از موفقیت، List و Record دوباره بارگذاری می‌شوند.
- پس از Withdraw، کارت وضعیت «انصراف» و متن «انصراف شما ثبت شده است» دارد.
- re-apply خودکار یا حذف history اضافه نشده است.

برای جلوگیری از Regression Performance، قابلیت نمایش دکمه از همان statusهای موجود در read model محاسبه می‌شود و در `rest_list/rest_record` query per-row جدید ایجاد نمی‌کند.

## 7. Health Center و Migration

کارت جدید:

`Member withdraw`

انتظار:

`mode: self-service | application: withdrawable | enrollment: withdrawable | confirm: explicit | history: preserved`

Migration جدید:

`20261006_029_rc40_member_withdraw`

ویژگی‌ها:

- متعلق به lifecycle module `programs`
- UX/workflow code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `29/29 completed`
- Programs migration count پس از RC40 = `6`

## 8. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `11/11 PASS`
- CSS structural: `10/10 PASS`
- E5 source/static contracts: `36/36 PASS`
- E5 domain runtime: `19/19 PASS`
  - state policy برای Application/Enrollment/terminalها
  - Withdraw Application و حفظ row/history
  - Withdraw Enrollment و ثبت `withdrawn_at_utc`
  - حفظ Application هنگام Withdraw Enrollment
  - Audit `member_withdrawal`
  - terminal program block
  - repeated withdrawal block
  - ownership-bound lookup با member id احرازشده
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC39

همین تست‌ها پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا شده‌اند.

## 9. تست دستی

طبق تصمیم محصول، تست دستی journey انصراف در این مرحله درخواست نمی‌شود و در پکیج پذیرش نهایی UX/E2E انجام خواهد شد؛ شامل Application withdraw، Enrollment withdraw، Cancel confirmation و حفظ History.

## 10. خارج از دامنه E5

عمداً انجام نشده‌اند:

- امکان re-apply پس از Withdraw
- حذف یا purge پاسخ‌های فرم/Attendance history
- E6: Error/Empty/Loading استاندارد
- E7: Draft فرم کاربر
- E8: validation field-level
- E9: bulk applicant management
- E10: اصلاح progress پرونده
- تغییر visual hierarchy دکمه‌های danger (Phase F)
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 11. نصب روی Production

RC39 را با RC40 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 029 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `29/29 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `6`
- Member withdraw = `mode: self-service | application: withdrawable | enrollment: withdrawable | confirm: explicit | history: preserved`
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

E5 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC39 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 029 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از Production Health Verification

**«Phase E / E6 — Error / Empty / Loading استاندارد»**
