# چک‌پوینت پروژه قرآن‌یار — RC46 Maintenance Freeze

**تاریخ:** 2026-10-07  
**نسخه:** QuranYar Messenger `1.3.1-rc46`  
**Schema:** `2026.10.06.2` — بدون تغییر  
**مبنای Production تأییدشده:** RC44 / Ledger `33/33`  
**وضعیت:** Maintenance Implemented + Local/Artifact Tests PASS — Pending Production Health Verification

## تصمیم محصول

شروع Phase F فعلاً **فریز** است. RC46 هیچ Design Token یا بازطراحی عمومی Phase F را آغاز نمی‌کند و فقط سه مشکل جاری زیر را می‌بندد.

## 1. UX/UI صفحه ارسال پیام

Preview قدیمی تا ۲۰۰ ردیف گیرنده را همراه متن کامل نمایش می‌داد و باعث صفحه بسیار بلند می‌شد.

در RC46:

- Preview فقط **یک گیرنده نمونه** و متن نهایی او را نشان می‌دهد.
- Aggregateهای کل کارزار (`recipients / pending / sent / failed`) در همان کارت باقی مانده‌اند.
- دکمه تاریخچه از «پیش‌نمایش / جزئیات» به «پیش‌نمایش» تبدیل شده است.
- Report پیگیری شکست‌ها همچنان در جای خود باقی می‌ماند و حذف نشده است.

## 2. ویرایش پیام تأییدشده

پیام‌های `draft / scheduled / active` تا زمانی قابل ویرایش‌اند که **همه Queue rowها هنوز pending باشند**.

ایمنی:

- Campaign + Queue rows با `FOR UPDATE` قفل می‌شوند.
- Template جدید برای تمام گیرندگان دوباره render می‌شود.
- Campaign copy و payloadهای pending در همان Transaction به‌روزرسانی می‌شوند.
- با شروع اولین Delivery، Edit قفل می‌شود تا یک کارزار با دو نسخه متن ارسال نشود.
- Worker پس از Claim، payload را دوباره از Queue می‌خواند و از payload انتخاب‌شده پیش از Claim استفاده نمی‌کند.

قرارداد Health:

`preview: 1 | edit: draft-scheduled-active-before-delivery | payload: rebuild-transactional | worker: reload-after-claim`

Migration:

`20261007_035_rc46_targeted_message_edit` — owner: `targetedmessaging`

## 3. اعلان تغییر وضعیت متقاضی Program

پس از موفقیت هرکدام از عملیات مدیریتی زیر، اعلان در همان request به Queue امن افزوده می‌شود:

- `under_review`
- `waitlisted`
- `accepted`
- `rejected`
- `enrolled`

ویژگی‌ها:

- ارسال HTTP هم‌زمان با تغییر وضعیت انجام نمی‌شود؛ پیام **فوراً enqueue** می‌شود و Worker canonical آن را تحویل می‌دهد.
- Bulk فقط برای ردیف‌هایی که transition/enrollment آن‌ها موفق شده اعلان می‌سازد.
- اعلان‌ها از همان Campaign/Queue، Lease، Delivery State و Tenant authorization موجود استفاده می‌کنند.
- کارزار سیستمی با `audience=program_status` از صفحه ارسال هدفمند انسانی مخفی است.
- عضو فقط وقتی وارد Queue می‌شود که `verified=1`، `consent=1`، دانشگاه فعال و دانشگاه فعلی با snapshot درخواست منطبق باشد.
- شکست enqueue تصمیم پذیرش/رد/ثبت‌نام را rollback نمی‌کند و نتیجه امن به مدیر گزارش می‌شود.

### شخصی‌سازی متن

در صفحه هر Program یک بخش «پیام‌های تغییر وضعیت متقاضی» اضافه شده است.

- مدیرکل: Template عمومی همان Program را ویرایش می‌کند.
- نماینده دانشگاه: Override همان Program برای دانشگاه خودش را ویرایش می‌کند.
- اولویت: Campus override → Program default → Built-in default.
- متغیرها: `{نام}`، `{نام خانوادگی}`، `{برنامه}`، `{وضعیت}`، `{دانشگاه}`.

قرارداد Health:

`events: review/waitlist/accept/reject/enroll | queue: canonical | templates: program+campus | consent: required`

Migration:

`20261007_036_rc46_program_status_notifications` — owner: `programs`

## 4. Upgrade و Migration

RC46 شامل E10/RC45 نیز هست. اگر Production مستقیماً از RC44 (`33/33`) به RC46 ارتقا یابد، سه Migration زیر به‌ترتیب pending هستند:

1. `20261006_034_rc45_profile_progress`
2. `20261007_035_rc46_targeted_message_edit`
3. `20261007_036_rc46_program_status_notifications`

Runner bounded است و در هر request حداکثر یک Migration جدید را آغاز می‌کند؛ بنابراین برای رسیدن به `36/36` ممکن است چند request عادی لازم باشد.

هیچ Migration فیزیکی جدیدی وجود ندارد؛ Schema همان `2026.10.06.2` است.

## 5. تست‌های اجراشده

### Work tree

- PHP syntax: `55/55 PASS`
- JavaScript syntax: `12/12 PASS`
- CSS structural: `10/10 PASS`
- RC46 source/static contracts: `22/22 PASS`
- Targeted approved-edit runtime: `7/7 PASS`
- Program status notification runtime: `11/11 PASS`
- Hook inventory parity RC45→RC46: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — exact PASS
- Ledger definition count: `36` total expected migrations

### Extracted artifact

همین syntax/static/runtime تست‌ها روی ZIP استخراج‌شده RC46 نیز PASS شدند.

## 6. معیار Production Health

پس از نصب RC46 انتظار می‌رود:

- Schema = `2026.10.06.2`
- Migration Ledger = `36/36 completed`
- Upgrade gate = ready
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Members migration count = `5`
- Programs migration count = `10`
- TargetedMessaging migration count = `1`
- Profile progress = ready
- Message preview / edit = ready
- Program status notify = ready
- Runtime schema probes = `0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker / Cron / Retention healthy

## 7. پذیرش دستی بعد از نصب

برای این Maintenance دو بررسی کوتاه کافی است:

1. یک کارزار پیش‌نویس/تأییدشده را باز کنید؛ Preview باید فقط یک نمونه نشان دهد و تا قبل از شروع Delivery امکان Edit داشته باشد.
2. در یک Program آزمایشی، Template وضعیت را ویرایش و یک متقاضی واجد شرایط را مثلاً به `under_review` منتقل کنید؛ پیام باید در Queue قرار گیرد و پس از Worker ارسال شود.

## 8. Rollback

RC46 Schema فیزیکی یا داده دامنه‌ای را rewrite نمی‌کند. Rollback فایل به RC45/RC44 از نظر DB نیازمند rollback فیزیکی نیست؛ entryهای تکمیل‌شده `035/036` می‌توانند به‌عنوان history در Ledger باقی بمانند. با rollback فایل، قابلیت‌های RC46 دیگر در runtime فعال نخواهند بود.

## گام بعد

**Phase F همچنان Frozen است.** پس از Production Health و پذیرش این سه اصلاح، ادامه Roadmap فقط با دستور صریح کاربر انجام می‌شود.
