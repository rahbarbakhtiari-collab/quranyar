# چک‌پوینت پروژه قرآن‌یار — Phase E / E2 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc37`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC36  
**مبنای ارتقا:** RC36 / Migration Ledger `25/25` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E2 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E2

بستن `QYS-P2-22 — Gate برنامه‌ها بدون توضیح Redirect می‌کند`.

در RC36، انتخاب «برنامه‌ها» برای عضو فاقد شرایط، پیش از ورود به خود بخش Programs به نمای Profile منتقل می‌شد. علت محدودیت و اقدام بعدی فقط به‌صورت ضمنی قابل استنباط بود.

از RC37، **کاربر همیشه وارد خود بخش «برنامه‌ها» می‌شود** و اگر شرایط لازم را ندارد، همان‌جا علت دقیق + CTA متناسب را می‌بیند. هیچ redirect خاموشی از Programs به Profile وجود ندارد.

## 2. سه وضعیت صریح Gate

Gate در shell پایه mini-app و پیش از lazy-load Programs تصمیم می‌گیرد:

- `membership_required` — پرونده وجود ندارد  
  عنوان: «ابتدا پرونده قرآن‌یار را تشکیل دهید»  
  CTA: «تشکیل پرونده»

- `profile_incomplete` — پرونده ناقص است  
  عنوان: «پرونده شما کامل نیست»  
  CTA: «تکمیل پرونده»

- `verification_pending` — پرونده کامل ولی هویت تأیید نشده است  
  عنوان: «تأیید هویت هنوز انجام نشده است»  
  CTA: «مشاهده وضعیت پرونده»

وقتی شرایط کامل است Gate کنار می‌رود و Programs طبق مسیر عادی lazy-load می‌شود.

## 3. عدم Redirect و حفظ Lazy Loading

تابع تاریخی client-side که Programs را به Profile تبدیل می‌کرد حذف شد.

قرارداد جدید:

`mode: explain | cta: explicit | redirect: none`

وقتی Gate بسته است:

- view Programs نمایش داده می‌شود؛
- علت و CTA قابل مشاهده‌اند؛
- bundle اختصاصی Programs بارگذاری نمی‌شود؛
- endpointهای `programs/list` و `programs/record` بی‌دلیل فراخوانی نمی‌شوند.

پس از آماده‌شدن پرونده/تأیید، Gate در همان lifecycle status باز می‌شود و Feature lazy-load می‌شود.

## 4. Backend همچنان مرجع نهایی است

`QYS_Programs_Module::request_member()` همچنان authorization نهایی REST را enforce می‌کند و اکنون metadata سازگار نیز برمی‌گرداند:

- `gate`
- `cta_label`
- `cta_view=profile`

بنابراین اگر وضعیت client stale باشد یا endpoint مستقیم فراخوانی شود، پاسخ Backend نیز علت و CTA روشن دارد.

`QYS_Programs_Module::access_gate_contract()`:

- `mode=explain`
- `cta=explicit`
- `redirect=none`
- `states=membership_profile_verification`
- `backend_authoritative=true`

## 5. UI و دسترسی‌پذیری

Gate در CSS پایه mini-app است تا برای نمایش آن نیازی به asset Feature Programs نباشد.

- `role=status` و `aria-live=polite`
- CTA واقعی و focusable
- سازگار با mobile، forced-colors و touch target
- Bottom-nav Programs در وضعیت blocked نشان attention/pending دارد اما همچنان قابل ورود است.

## 6. Health Center و Migration

کارت جدید:

`Programs access gate`

انتظار:

`mode: explain | cta: explicit | redirect: none`

Migration جدید:

`20261006_026_rc37_programs_access_gate`

ویژگی‌ها:

- متعلق به lifecycle module `programs`
- UX/workflow code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `26/26 completed`
- Programs migration count پس از RC37 = `3`

## 7. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- E2 source/static contracts: `12/12 PASS`
- Backend Gate runtime: `18/18 assertions PASS`
- Client Gate runtime/static: `13/13 assertions PASS`
  - membership/profile/verification/ready states
  - silent redirect helper حذف شده
  - Programs blocked ⇒ no lazy feature load
  - CTA صریح در shell
- E1/D10 و قراردادهای Performance قبلی در regression حفظ شده‌اند.
- Hook inventory مورد انتظار: بدون Hook جدید نسبت به RC36 (`57 actions / 6 filters / 2 shortcodes / 1 activation hook`).

همه تست‌های syntax/static/runtime پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## 8. تست دستی

طبق تصمیم محصول، تست دستی E2 اکنون اجباری نیست و به **پکیج پذیرش نهایی پروژه** منتقل می‌شود. در پایان پروژه، سه حالت Gate بالا همراه CTA و بازشدن خودکار Gate پس از رفع شرط، یکجا تست خواهند شد.

## 9. خارج از دامنه E2

عمداً انجام نشده‌اند:

- E3: Confirmation برای destructive actions
- E4: Program Edit workflow
- E5: Member Withdraw
- E6: Error/Empty/Loading استاندارد
- E7: Draft فرم
- E8: validation field-level
- E9: bulk applicant management
- E10: اصلاح progress پرونده
- تغییر eligibility backend Programs
- تغییر Program visibility/tenant scope
- تغییر Messaging Consent E1

## 10. نصب روی Production

RC36 را با RC37 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 026 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `26/26 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `3`
- Members migration count = `3`
- Programs access gate = `mode: explain | cta: explicit | redirect: none`
- Membership / messaging = `membership: independent | messaging: optional | default: off`
- Portal assets = `shell: base | feature: conditional | scope: section+permission`
- Birthday lookup = `column: birth_md | query: exact | index: ready`
- Batch export = `mode: streaming | chunk: 250 | cursor: keyset | memory: bounded`
- Health snapshot = `heartbeat: lightweight | full: 15m/on-demand`
- Queue dispatch index = `(status,id): ready | pending scan: indexed`
- Queue throughput = `mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention سالم

## 11. Rollback

E2 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC36 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 026 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از Production Health Verification

**«Phase E / E3 — Confirmation برای destructive actions»**
