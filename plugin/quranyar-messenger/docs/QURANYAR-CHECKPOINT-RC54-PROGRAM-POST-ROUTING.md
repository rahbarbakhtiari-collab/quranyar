# چک‌پوینت پروژه قرآن‌یار — RC54 / Program POST Routing & Redirect Safety

**تاریخ:** 2026-10-11  
**نسخه:** QuranYar Messenger `1.3.1-rc54`  
**Schema:** `2026.10.11.2` — بدون تغییر نسبت به RC53  
**مبنای ارتقا:** RC53 / Ledger `41/41`  
**وضعیت:** Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. مسئله مشاهده‌شده روی Production

نماینده دانشگاه پس از تغییر وضعیت درخواست متقاضی، مثلاً «بررسی»، به URL دارای `section=programs&program_id=...` برمی‌گشت اما محتوای اصلی صفحه سفید می‌شد و فقط Shell/Header پنل دیده می‌شد. تغییر وضعیت در DB انجام شده بود.

## 2. علت اصلی

در RC53 فرم‌های Programs روی همان صفحه POST می‌شدند و `QYS_Programs_Module::handle_post()` فقط از داخل `page()` صدا زده می‌شد. در پنل standalone، template پیش از اجرای shortcode بخش بزرگی از HTML/Header را خروجی می‌کند و سپس `do_shortcode('[qys_university_portal]')` به `page()` می‌رسد.

در نتیجه الگوی POST/Redirect/Get در مرحله render انجام می‌شد. روی محیطی که خروجی پاسخ زودتر commit شود، `wp_safe_redirect()` دیگر redirect قابل اتکا نداشت. `exit` پس از آن باعث می‌شد فقط پوسته بالای صفحه دیده شود و Main خالی بماند.

## 3. اصلاح RC54

- `route_portal_post()` روی `template_redirect` با priority 8 ثبت شد.
- `route_admin_post()` روی `admin_init` با priority 60 ثبت شد.
- هر دو فقط POST واقعی دارای `qys_program_action` و context صحیح Programs را می‌پذیرند.
- `page()` دیگر `handle_post()` را هنگام render اجرا نمی‌کند.
- `notice_redirect()` URL مقصد canonical را پیش از redirect می‌سازد و `program_id`، filterهای applicant و anchor را حفظ می‌کند.
- fallback صریح برای حالت نادر `headers_sent()` اضافه شد تا صفحه خالی تولید نشود.
- `queue_for_applications_safe()` اضافه شد؛ خطای غیرمنتظره Notification بعد از commit transition به aggregate failure تبدیل می‌شود و UI/redirect را نمی‌شکند.

## 4. وضعیت‌های بررسی‌شده

مسیرهای تک‌نفره:

- `submitted → under_review`
- `submitted/under_review → waitlisted`
- `submitted/under_review/waitlisted → accepted`
- `submitted/under_review/waitlisted → rejected`
- `accepted → enrolled`

مسیر Bulk نیز برای `under_review / waitlisted / accepted / rejected / enroll` از همان fail-soft notification و redirect مشترک استفاده می‌کند.

## 5. قرارداد Health

کارت جدید:

`Program action routing`

انتظار:

`portal: template-redirect | admin: admin-init | timing: pre-render | notify: fail-soft`

## 6. Migration

`20261011_042_rc54_program_pre_render_post_redirect`

- owner: `programs`
- code-contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema=`2026.10.11.2`
- Ledger هدف=`42/42`
- Programs migrations هدف=`13`

## 7. تست‌ها

- PHP syntax: `57/57 PASS`
- JavaScript syntax: `12/12 PASS`
- RC54 hook/runtime contract: `7/7 PASS`
- Notification fail-soft runtime: `1/1 PASS`
- Status/routing static matrix: `10/10 PASS`
- Migration M8 contract: `1/1 PASS`
- Programs migration definitions: `13`

همین تست‌ها پس از ساخت ZIP روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## 8. معیار Production

پس از نصب:

- Schema=`2026.10.11.2`
- Ledger=`42/42 completed`
- Programs migrations=`13`
- Physical diagnostics=`13/13`
- Program action routing=`template-redirect / admin-init / pre-render / fail-soft`
- failed / blocked / degraded = `0`

تست دستی کوتاه روی یک Program آزمایشی:

1. Submitted → بررسی
2. بررسی → انتظار یا پذیرش
3. مسیر دیگری → رد
4. Accepted → ثبت‌نام قطعی

پس از هر عملیات باید همان صفحه Program با Notice نتیجه نمایش داده شود؛ صفحه سفید پذیرفته نیست.

## 9. Rollback

RC54 تغییر فیزیکی دیتابیس ندارد. Rollback فایل به RC53 نیازمند rollback دیتابیس نیست؛ entry Migration 042 می‌تواند به‌عنوان history در Ledger باقی بماند.

Phase F همچنان **Frozen / Not Started** است.
