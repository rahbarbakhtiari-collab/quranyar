# چک‌پوینت قرآن‌یار — RC47 Maintenance UX Follow-up

**تاریخ:** 2026-10-07  
**نسخه:** QuranYar Messenger `1.3.1-rc47`  
**Schema:** `2026.10.06.2`  
**مبنای ارتقا:** RC46 / Ledger `36/36`  
**وضعیت:** Implemented + Local/Artifact Tests PASS — Pending Production Verification

## تصمیم محصول

Phase F همچنان فریز است. RC47 فقط اصطکاک UX باقی‌مانده از Maintenance RC46 را جمع می‌کند و هیچ Design System عمومی آغاز نمی‌کند.

## 1. صفحه ارسال پیام

مشکل مشاهده‌شده روی Production RC46: Preview تک‌نمونه درست بود، اما فرم «ویرایش پیام تأییدشده» برای کارزار قابل ویرایش به‌صورت خودکار باز می‌شد و دوباره ارتفاع صفحه را زیاد می‌کرد.

در RC47:

- `details.qys-targeted-edit` **هیچ `open` پیش‌فرضی ندارد**.
- Preview یک نمونه بدون تغییر حفظ شده است.
- Editor فقط با کلیک «باز کردن ویرایش» نمایش داده می‌شود.
- هنگام باز بودن CTA به «بستن ویرایش» تغییر می‌کند.
- توضیح Summary روشن می‌کند که ویرایش فقط پیش از شروع Delivery ممکن است.
- منطق Transaction، pending-only، payload rebuild و worker reload RC46 بدون تغییر است.

قرارداد Health تکمیل شد:

`preview: 1 | editor: collapsed-disclosure | edit: draft-scheduled-active-before-delivery | payload: rebuild-transactional | worker: reload-after-claim`

## 2. تنظیم پیام‌های وضعیت Program

بازبینی سورس RC46 نشان داد قابلیت شخصی‌سازی از قبل در صفحه هر Program وجود دارد و Quicknav نیز «پیام‌های وضعیت» را دارد. در RC47 برای خوانایی بیشتر:

- Editor همچنان به‌صورت بسته شروع می‌شود.
- Summary صریحاً «۵ متن: بررسی، انتظار، پذیرش، رد و ثبت‌نام قطعی» را نشان می‌دهد.
- CTA روشن «باز کردن تنظیمات / بستن تنظیمات» اضافه شده است.
- scope عمومی Program یا University override و تمام منطق Queue/Consent بدون تغییر باقی مانده است.

قرارداد Health:

`events: review/waitlist/accept/reject/enroll | queue: canonical | templates: program+campus | editor: collapsed-disclosure | consent: required`

## 3. Migration / Schema

RC47 Migration جدید ندارد؛ تغییر فقط Presentation/UX و telemetry contract است.

- Schema = `2026.10.06.2`
- Ledger = `36/36`
- Programs migrations = `10`
- TargetedMessaging migrations = `1`

## 4. تست‌ها

### Work tree

- PHP syntax: `55/55 PASS`
- JavaScript syntax: `12/12 PASS`
- CSS structural: `10/10 PASS`
- RC47 static/UX contracts: `20/20 PASS`
- Targeted approved-edit runtime: `8/8 PASS`
- Program notification runtime: `12/12 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC46

همین تست‌ها باید روی Artifact استخراج‌شده نیز تکرار و PASS شوند.

## 5. معیار Production

- Health Center سالم، Schema=`2026.10.06.2` و Ledger=`36/36`.
- `Message preview / edit` شامل `editor: collapsed-disclosure`.
- `Program status notify` شامل `editor: collapsed-disclosure`.
- صفحه ارسال پیام: فرم Edit در بارگذاری اولیه بسته باشد.
- صفحه Program: «پیام‌های وضعیت» در Quicknav و Editor ۵ پیام به‌صورت بسته قابل مشاهده باشد.

## Rollback

RC47 هیچ Migration یا تغییر DB ندارد؛ rollback فایل به RC46 نیازمند rollback دیتابیس نیست.

## گام بعد

Phase F همچنان Frozen است. پس از تأیید RC47، مشکل جاری بعدی فقط با دستور کاربر انتخاب می‌شود.
