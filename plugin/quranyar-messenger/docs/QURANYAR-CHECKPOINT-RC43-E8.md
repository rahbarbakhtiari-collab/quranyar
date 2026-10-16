# چک‌پوینت پروژه قرآن‌یار — Phase E / E8 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc43`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC42  
**مبنای ارتقا:** RC42 / Migration Ledger `31/31` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E8 Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## 1. هدف E8

بستن `QYS-P2-29 — Validation checkbox ناقص است` و استانداردکردن validation فیلدبه‌فیلد فرم پیش‌ثبت‌نام Program.

Backend از قبل validation دامنه‌ای داشت، اما required multi-select در Client به‌صورت قابل اتکا enforce نمی‌شد و خطای Server به فیلد مشخص برنمی‌گشت. در RC43 Client و Server یک قرارداد دو‌لایه دارند و **Server همچنان مرجع نهایی** است.

## 2. قرارداد Field Validation

`QYS_Programs_Module::field_validation_contract()`:

`mode: field-level | required: client+server | multi: one-or-more | error: inline | focus: first-invalid | server: authoritative`

اصول:

- فیلدهای required قبل از ارسال شبکه در Client بررسی می‌شوند.
- `multi_choice` الزامی باید حداقل یک گزینه انتخاب‌شده داشته باشد.
- خطا در همان فیلد و به‌صورت inline نمایش داده می‌شود.
- اولین فیلد نامعتبر focus و در viewport آورده می‌شود.
- بعد از اصلاح input/change خطای همان فیلد پاک یا دوباره ارزیابی می‌شود.
- Client validation جای Backend validation را نمی‌گیرد.

## 3. انواع فیلد پوشش‌داده‌شده

- `short_text`: required و سقف 500 کاراکتر
- `long_text`: required و سقف 5000 کاراکتر
- `number`: required و عددی بودن؛ ارقام فارسی/عربی normalize می‌شوند
- `date`: required و ساختار `YYYY/MM/DD` یا `YYYY-MM-DD`
- `yes_no`: required و انتخاب صریح
- `single_choice`: required و گزینه معتبر
- `multi_choice`: required + حداقل یک گزینه؛ گزینه‌های نامعتبر در Server رد می‌شوند
- `notice`: ورودی کاربر نیست و validation ندارد

## 4. Server-authoritative validation

`QYS_Program_Service::validate_answer()` همچنان مرجع نهایی است.

در RC43 خطاهای validation Server علاوه بر `status=400`، metadata امن زیر را برمی‌گردانند:

- `field_key`
- `field_type`

Client در صورت دریافت `field_key` همان فیلد را inline علامت‌گذاری و focus می‌کند. بنابراین bypass کردن JavaScript باعث پذیرش پاسخ نامعتبر نمی‌شود.

برای `multi_choice`:

- required + پاسخ خالی ⇒ `required`
- گزینه خارج از مجموعه مجاز ⇒ `choice`
- گزینه تکراری ⇒ normalize و deduplicate
- optional + پاسخ خالی ⇒ `null`

## 5. تعامل با Draft E7

- قبل از validation، Draft جاری flush می‌شود.
- اگر Client validation شکست بخورد، هیچ درخواست submit به شبکه ارسال نمی‌شود و Draft باقی می‌ماند.
- اگر Server validation شکست بخورد نیز Draft حفظ می‌شود.
- فقط submit موفق Draft را پاک می‌کند.

## 6. Accessibility / UX

- کنترل‌های نامعتبر `aria-invalid=true` می‌گیرند.
- هر کنترل با `aria-describedby` به پیام خطای همان فیلد متصل است.
- پیام inline با `role=alert` نمایش داده می‌شود.
- اولین فیلد نامعتبر focus می‌شود.
- CSS حالت invalid را بدون وابستگی به animation مشخص می‌کند.

## 7. Health Center و Migration

کارت جدید:

`Field validation`

انتظار:

`mode: field-level | required: client+server | multi: one-or-more | error: inline`

Migration جدید:

`20261006_032_rc43_field_level_validation`

ویژگی‌ها:

- متعلق به lifecycle module `programs`
- UX/validation code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `32/32 completed`
- Programs migration count پس از RC43 = `8`

## 8. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `11/11 PASS`
- CSS structural: `10/10 PASS`
- E8 source/static contracts: `34/34 PASS`
- E8 PHP validation runtime: `13/13 PASS`
- E8 Client validation runtime: `15/15 PASS`
- E8 contract runtime: `7/7 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC42

همین تست‌ها پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## 9. تست دستی

طبق تصمیم محصول، تست دستی E8 اکنون درخواست نمی‌شود و در پکیج پذیرش نهایی UX/E2E انجام خواهد شد؛ شامل required text، required multi-choice، خطای Server روی یک field و حفظ Draft هنگام validation failure.

## 10. خارج از دامنه E8

عمداً انجام نشده‌اند:

- E9: bulk applicant management
- E10: اصلاح progress پرونده
- Design System و visual hierarchy عمومی (Phase F)
- تغییر Domain/Permission/Tenant rules
- تغییر schema فیزیکی
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 11. نصب روی Production

RC42 را با RC43 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 032 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `32/32 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Programs migration count = `8`
- Members migration count = `4`
- Field validation = `mode: field-level | required: client+server | multi: one-or-more | error: inline`
- Form draft = `storage: local | scope: member+program+form | ttl: 24h | clear: successful-submit`
- Async states = `loading: visible | empty: distinct | error: visible | retry: explicit`
- E1–E6 و تمام قراردادهای Phase D سالم
- Runtime schema probes = `0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention سالم

## 12. Rollback

E8 هیچ Schema فیزیکی یا rewrite داده‌ای ندارد.

Rollback فایل به RC42 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 032 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از Production Health Verification

**Phase E / E9 — bulk applicant management**
