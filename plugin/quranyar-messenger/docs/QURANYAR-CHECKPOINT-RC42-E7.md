# چک‌پوینت پروژه قرآن‌یار — Phase E / E7 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc42`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC41  
**مبنای ارتقا:** RC41 / Migration Ledger `30/30` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E7 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E7

بستن `QYS-P2-28 — Draft فرم کاربر وجود ندارد`؛ پاسخ‌های فرم پیش‌ثبت‌نام دیگر با refresh یا جابه‌جایی داخل برنامک از دست نمی‌روند.

## 2. قرارداد Draft

`QYS_Programs_Module::member_form_draft_contract()`:

`storage: local | scope: member+program+form | ttl: 24h | restore: automatic | clear: successful-submit`

اصول:

- Draft فقط در مرورگر/وب‌ویوی همان دستگاه ذخیره می‌شود و به سرور ارسال نمی‌شود تا زمانی که کاربر فرم را واقعاً Submit کند.
- کلید Draft به member + program + fingerprint فرم scoped است؛ شناسه خام عضو داخل کلید ذخیره نمی‌شود.
- تغییر ساختار فرم fingerprint را عوض می‌کند و Draft قدیمی روی فرم جدید restore نمی‌شود.
- Draft پس از ۲۴ ساعت منقضی و پاک می‌شود.
- Submit موفق Draft را پاک می‌کند؛ خطای شبکه/سرور Draft را حفظ می‌کند.

## 3. UX برنامک

- پاسخ‌ها هنگام `input/change` با debounce کوتاه ذخیره می‌شوند.
- هنگام خروج از view، `pagehide` یا hidden شدن WebView یک flush فوری انجام می‌شود.
- اگر Draft معتبر وجود داشته باشد CTA از «پیش ثبت‌نام» به «ادامه پیش ثبت‌نام» تغییر می‌کند.
- با بازکردن فرم، پاسخ‌های Draft خودکار بازیابی می‌شوند.
- داخل فرم پیام کوتاه retention نمایش داده می‌شود: پاسخ‌ها روی همین دستگاه تا ۲۴ ساعت موقت می‌مانند و پس از ثبت موفق پاک می‌شوند.
- دکمه «بازگشت» فرم را می‌بندد ولی Draft را نگه می‌دارد.

## 4. حریم خصوصی و ایمنی

- `initData`، نام، کدملی، موبایل یا شناسه خام Eitaa داخل payload Draft ذخیره نمی‌شود.
- فقط map پاسخ‌های field key → value همراه `savedAt` ذخیره می‌شود.
- اگر localStorage یا شناسه معتبر Eitaa در دسترس نباشد، Draft persistence fail-closed می‌شود و ثبت اصلی فرم همچنان کار می‌کند.
- هیچ endpoint، Permission، Tenant scope یا Domain transition تغییر نکرده است.
- E8 validation field-level عمداً وارد E7 نشده است.

## 5. Health Center و Migration

کارت جدید:

`Form draft`

انتظار:

`storage: local | scope: member+program+form | ttl: 24h | clear: successful-submit`

Migration جدید:

`20261006_031_rc42_member_form_draft`

ویژگی‌ها:

- متعلق به lifecycle module `programs`
- UX/workflow code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `31/31 completed`
- Programs migration count پس از RC42 = `7`

## 6. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `11/11 PASS`
- CSS structural: `10/10 PASS`
- E7 source/static contracts: `35/35 PASS`
- Draft runtime: `24/24 assertions PASS`
- PHP contract/migration runtime: `8/8 PASS`
- Programs migration registrations = `7`
- Hook inventory: باید نسبت به RC41 بدون Regression باقی بماند.

تست‌ها پس از ساخت ZIP نهایی نیز روی Artifact استخراج‌شده تکرار می‌شوند.

## 7. تست دستی

طبق تصمیم محصول، تست دستی E7 اکنون درخواست نمی‌شود و در پکیج پذیرش نهایی UX/E2E انجام خواهد شد؛ شامل refresh، navigation، بازگشت، خطای submit، submit موفق و expiry.

## 8. خارج از دامنه E7

- E8: validation field-level
- E9: bulk applicant management
- E10: اصلاح progress پرونده
- Draft سمت سرور یا sync بین دستگاه‌ها
- نگهداری بیش از ۲۴ ساعت
- تغییر Design System عمومی (Phase F)

## 9. نصب روی Production

RC41 را با RC42 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 031 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `31/31 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `7`
- Members migration count = `4`
- Form draft = `storage: local | scope: member+program+form | ttl: 24h | clear: successful-submit`
- Async states = `loading: visible | empty: distinct | error: visible | retry: explicit`
- E1 تا E6 و قراردادهای Phase D سالم
- Runtime schema probes = `0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention سالم

## 10. Rollback

E7 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد. Rollback فایل به RC41 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 031 می‌تواند در Ledger به‌عنوان history باقی بماند. Draftهای local با prefix نسخه‌دار در صورت rollback بی‌اثر می‌مانند.

## دستور بعدی پس از Production Health Verification

**Phase E / E8 — validation field-level**
