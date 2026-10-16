# چک‌پوینت قرآن‌یار — RC49 Maintenance Product Correction

**تاریخ:** 2026-10-07  
**نسخه:** QuranYar Messenger `1.3.1-rc49`  
**Schema:** `2026.10.06.2`  
**مبنای سورس:** RC48 / Ledger هدف قبلی `37/37`  
**آخرین Production Health تأییدشده:** RC46 / `36/36`  
**وضعیت:** Implemented + Local/Artifact Tests PASS — Pending Production Verification

## تصمیم محصول

مجوز دریافت پیام برای پرونده فعال قرآن‌یار **اجباری** است. کاربر باید ابتدا مجوز پیام ایتا را فعال کند و فقط سپس می‌تواند پرونده را تشکیل یا تکمیل کند. تصمیم قدیمی RC36 مبنی بر optional بودن consent، تاریخچه باقی می‌ماند ولی از RC49 supersede شده است.

## رفتار عضو جدید

1. Status بدون پرونده، فرم را باز نمی‌کند.
2. CTA «فعال‌سازی مجوز دریافت پیام» پنجره native ایتا را باز می‌کند.
3. در صورت رد مجوز، فرم همچنان بسته و Submit غیرفعال می‌ماند.
4. در صورت موفقیت، فرم باز می‌شود و Submit جدید `consent=true` می‌فرستد.
5. Backend نیز مستقل از Client، ثبت بدون consent را با `consent_required` رد می‌کند.

## رفتار عضو موجود

- consent=1: پرونده طبق روال قابل ویرایش است.
- consent=0: `membership_active=false` و `profile_complete=false`؛ Edit غیرفعال و CTA «فعال‌سازی مجدد دریافت پیام» نمایش داده می‌شود.
- پس از reactivation، ویرایش/Programs/Attendance دوباره بر اساس سایر شروط فعال می‌شوند.
- self-disable از برنامک عضو مسدود است. Admin suspension قبلی حذف نشده و برای کنترل مدیریتی باقی می‌ماند.

## Progress canonical

`profile_progress` اکنون ۸ شرط دارد:

1. messaging consent فعال
2. دانشگاه فعال
3. نقش معتبر
4. نام
5. نام خانوادگی
6. کد ملی
7. موبایل
8. تاریخ تولد

اگر هفت فیلد اطلاعاتی کامل باشند ولی consent خاموش باشد، Progress برابر ۷/۸ (حدود ۸۸٪) و `complete=false` است؛ ۱۰۰٪ فقط با هر هشت شرط ممکن است.

## Scopeهای وابسته

- Programs: Gate صریح `consent_required`.
- Attendance mini-app: consent فعال جزء eligibility است.
- Attendance roster/export: queryها `consent=1` را enforce می‌کنند.
- Readiness complete count: consent=1 جزء شرط کامل بودن است.
- Birthday/Program policyهایی که از `profile_complete()` استفاده می‌کنند به‌صورت خودکار با قرارداد جدید همسو می‌شوند.

## Health Center

انتظار:

- `Membership / messaging = membership: requires-messaging | messaging: required | gate: before-profile | disable: blocked-for-member`
- `Profile progress = source: server | basis: canonical-required-plus-consent | fields: 8 | consent: required | 100: complete-only | ui: incomplete-only`

## Migration

`20261007_038_rc49_required_messaging_consent`

- owner: `members`
- بدون schema/data rewrite
- Schema unchanged: `2026.10.06.2`
- Ledger هدف: `38/38`
- Members migrations هدف: `6`

اگر Production هنوز RC46 باشد، نصب مستقیم RC49 باید ابتدا Migration 037 مربوط به RC48 و سپس 038 را طی requestهای bounded کامل کند. اگر RC48 نصب شده باشد، فقط 038 pending است.

## تست‌ها

- PHP syntax: `55/55 PASS`
- JavaScript syntax: `12/12 PASS`
- CSS structural: `10/10 PASS`
- RC49 static/product contracts: `37/37 PASS`
- Profile/consent runtime contract: `22/22 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`

## تست دستی Production

پس از Health Verification، این Maintenance نیازمند یک UX check کوتاه است:

- کاربر بدون پرونده: فرم قبل از مجوز دیده/فعال نشود.
- رد مجوز: تشکیل پرونده ممکن نباشد.
- قبول مجوز: فرم باز و ثبت موفق شود.
- عضو consent-off: CTA reactivation ببیند و تا فعال‌سازی مجدد profile_complete نباشد.

## Rollback

RC49 تغییر فیزیکی Schema یا rewrite داده ندارد. rollback فایل به RC48 نیازمند rollback DB نیست؛ entry 038 می‌تواند به‌عنوان history در Ledger بماند.

## وضعیت Roadmap

Phase F همچنان **Frozen / Not Started** است.
