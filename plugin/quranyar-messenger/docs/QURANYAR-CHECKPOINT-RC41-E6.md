# چک‌پوینت پروژه قرآن‌یار — Phase E / E6 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc41`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC40  
**مبنای ارتقا:** RC40 / Migration Ledger `29/29` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E6 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E6

بستن `QYS-P2-26 — Home Programs خطا را با Empty یکی می‌کند` و ایجاد قرارداد مشترک برای حالت‌های async اصلی Member App.

از RC41، **Loading / Empty / Error سه وضعیت مستقل** هستند و خطای قابل‌بازیابی CTA «تلاش دوباره» دارد.

## 2. قرارداد Async State

`QYS_Member_Experience::async_state_contract()`:

`loading: visible | empty: distinct | error: visible | retry: explicit`

Scope فعلی قرارداد:

- Home Programs summary
- Lazy feature loading برای Programs / Attendance
- Programs list و record fetch

این مرحله Domain state، Permission، Tenant scope یا REST authority را تغییر نمی‌دهد.

## 3. Home Programs

رفتار قبلی:

- پاسخ خالی و خطای شبکه هر دو باعث مخفی‌شدن کامل بخش Home Programs می‌شدند.
- بنابراین کاربر نمی‌توانست تشخیص دهد «برنامه‌ای وجود ندارد» یا «دریافت داده شکست خورده است».

رفتار RC41:

- Loading: بخش قابل مشاهده است و spinner + پیام انتظار دارد.
- Empty: بخش قابل مشاهده است و پیام صریح «فعلاً برنامه جاری برای شما وجود ندارد» دارد.
- Error: بخش قابل مشاهده است، متن خطا نمایش داده می‌شود و CTA «تلاش دوباره» دارد.
- Ready: کارت‌های Program نمایش داده می‌شوند.
- Ineligible: اگر پرونده/تأیید لازم آماده نباشد، همان Gateهای E2 مرجع هستند و Home Programs مخفی می‌ماند.

Error دیگر به Empty تبدیل نمی‌شود.

## 4. Lazy Feature Loading

بارگذاری bundleهای Programs و Attendance اکنون از primitive مشترک Async State استفاده می‌کند:

- قبل از load: Loading visible
- failure: Error visible + Retry explicit
- success: feature خودش view را render می‌کند

این تغییر D4 lazy loading را حفظ می‌کند و feature bundle غیرضروری eager نمی‌شود.

## 5. Programs member view

Programs list و record نیز از همان primitive پایه استفاده می‌کنند:

- List loading و Record loading یک الگوی مشترک دارند.
- List empty از error جداست.
- خطای network/server که Gate نیست، CTA «تلاش دوباره» دارد.
- خطاهای واقعی access gate همچنان CTA پرونده E2 را نشان می‌دهند.
- Query یا Domain transition جدیدی اضافه نشده است.

## 6. Accessibility / UX Safety

- Loading و Empty با `role=status` و `aria-live=polite`.
- Error با `role=alert`.
- `aria-busy` برای Home Programs و feature targetها مدیریت می‌شود.
- spinner در `prefers-reduced-motion` بدون animation است.
- forced-colors boundary حفظ شده است.
- متن خطای Server قبل از ورود به HTML escape می‌شود.

## 7. Health Center و Migration

کارت جدید:

`Async states`

انتظار:

`loading: visible | empty: distinct | error: visible | retry: explicit`

Migration جدید:

`20261006_030_rc41_async_member_states`

ویژگی‌ها:

- متعلق به lifecycle module `members`
- UX/workflow code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `30/30 completed`
- Members migration count پس از RC41 = `4`

## 8. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `11/11 PASS`
- CSS structural: `10/10 PASS`
- E6 source/static contracts: `35/35 PASS`
- Async state markup runtime: `10/10 PASS`
- Member async contract runtime: `6/6 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC40
- E5/E4/E3/E2/E1 و Performance contracts در source regression حفظ شده‌اند.

همین تست‌ها پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## 9. تست دستی

طبق تصمیم محصول، تست دستی E6 اکنون درخواست نمی‌شود و در پکیج پذیرش نهایی UX/E2E انجام خواهد شد.

سناریوهای آن بسته:

- Home Programs با data
- Home Programs empty
- Home Programs network/server error + Retry
- failure/retry بارگذاری lazy Programs/Attendance
- Programs list/record empty/error/retry

## 10. خارج از دامنه E6

عمداً انجام نشده‌اند:

- E7: Draft فرم کاربر
- E8: validation field-level
- E9: bulk applicant management
- E10: اصلاح progress پرونده
- تغییر Design System یا visual hierarchy عمومی (Phase F)
- تغییر Domain/Permission/Tenant rules
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 11. نصب روی Production

RC40 را با RC41 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 030 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `30/30 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Members migration count = `4`
- Programs migration count = `6`
- Async states = `loading: visible | empty: distinct | error: visible | retry: explicit`
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

E6 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC40 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 030 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از Production Health Verification

**«Phase E / E7 — Draft فرم کاربر»**
