# بسته canonical پروژه قرآن‌یار — RC54

این نسخه یک Maintenance محدود برای رفع **صفحه سفید پس از تغییر وضعیت متقاضی Program در پنل دانشگاه** است. Phase F / Design System همچنان **فریز و شروع‌نشده** باقی می‌ماند.

- نسخه افزونه: `1.3.1-rc54`
- Schema: `2026.10.11.2` — بدون تغییر نسبت به RC53
- Migration Ledger هدف: `42/42`
- Programs migrations هدف: `13`
- Physical diagnostics هدف: `13/13`

## علت اصلی RC54

فرم‌های Program در پنل دانشگاه روی همان صفحه POST می‌شدند، اما `handle_post()` تا زمان render شدن shortcode اجرا نمی‌شد. در template مستقل دانشگاه، Header و HTML پایه **پیش از** `do_shortcode('[qys_university_portal]')` خروجی می‌شوند؛ بنابراین `wp_safe_redirect()` بعد از تغییر وضعیت می‌توانست زمانی اجرا شود که خروجی پاسخ آغاز شده بود. نتیجه در بعضی محیط‌های PHP/Server این بود که تغییر DB انجام می‌شد ولی redirect کامل نمی‌شد و کاربر فقط Shell/Header پنل و محتوای سفید می‌دید.

## اصلاح RC54

- تمام POSTهای Program در Frontend Portal روی hook `template_redirect` و **قبل از render** پردازش می‌شوند.
- POSTهای صفحه مدیرکل در wp-admin روی `admin_init` پردازش می‌شوند.
- `page()` دیگر عملیات state-changing را در مرحله render اجرا نمی‌کند.
- Redirect پس از عملیات به URL canonical Programs انجام می‌شود و `program_id`، فیلترهای متقاضیان و anchor مربوط حفظ می‌شوند.
- اگر به‌علت خروجی زودهنگام افزونه/پوسته دیگری Header قابل redirect نباشد، fallback واضح «عملیات انجام شد / بازگشت به برنامه» نمایش داده می‌شود و صفحه خالی رها نمی‌شود.
- اطلاع‌رسانی تغییر وضعیت fail-soft شد: خطای غیرمنتظره Notification/Queue بعد از commit وضعیت، دیگر نباید UI مدیر دانشگاه را از کار بیندازد.

## ماتریس وضعیت بررسی‌شده

- `submitted → under_review` — «بررسی»
- `submitted/under_review → waitlisted` — «انتظار»
- `submitted/under_review/waitlisted → accepted` — «پذیرش»
- `submitted/under_review/waitlisted → rejected` — «رد»
- `accepted → enrolled` — «ثبت‌نام قطعی»
- همین مسیرها در Bulk Applicant Management نیز از routing و notification fail-soft مشترک استفاده می‌کنند.

## معیار پذیرش RC54

- Health Center: `Program action routing = portal: template-redirect | admin: admin-init | timing: pre-render | notify: fail-soft`.
- Schema=`2026.10.11.2` و Ledger=`42/42`.
- Programs migrations=`13` و Physical diagnostics=`13/13`.
- پس از هر تغییر وضعیت، صفحه جزئیات همان Program با notice نتیجه باز شود و Shell سفید ایجاد نشود.
- در صورت خطای Notification، خود تغییر وضعیت حفظ شود و مدیر پیام کنترل‌شده ببیند.

---

# بسته canonical پروژه قرآن‌یار — RC53

این نسخه یک Maintenance محدود برای رفع مسیر **ثبت‌نام مجدد پس از انصراف از برنامه** است. Phase F / Design System همچنان **فریز و شروع‌نشده** باقی می‌ماند.

- نسخه افزونه: `1.3.1-rc53`
- Schema: `2026.10.11.2` — بدون تغییر نسبت به RC52
- Migration Ledger هدف: `41/41`
- Programs migrations هدف: `12`
- Physical diagnostics هدف: `13/13`

## تصمیم محصول RC53

- اگر عضو از درخواست یا ثبت‌نام قطعی یک Program انصراف داده باشد و ثبت‌نام Program هنوز باز باشد، دکمه «ثبت‌نام مجدد» می‌بیند.
- ثبت‌نام مجدد **پذیرش قبلی را خودکار برنمی‌گرداند**؛ رابطه قبلی به وضعیت `submitted` برمی‌گردد و مسیر عادی بررسی/انتظار/پذیرش دوباره طی می‌شود.
- Eligibility، دانشگاه، بازه زمانی ثبت‌نام و سیاست ظرفیت دوباره Server-side بررسی می‌شوند.
- اگر ثبت‌نام قطعی قبلی `withdrawn` باشد، پس از پذیرش جدید همان Enrollment به‌صورت کنترل‌شده دوباره `enrolled` می‌شود؛ رکورد تازه موازی ساخته نمی‌شود.
- Audit و سابقه حضور حذف نمی‌شوند.
- انصراف قدیمی همچنان ظرفیت را آزاد نگه می‌دارد؛ فقط پذیرش جدید بعد از درخواست مجدد دوباره ظرفیت را رزرو می‌کند.
- درخواست مجددی که هنوز در بررسی است دوباره قابل پس‌گرفتن است، حتی اگر یک Enrollment قدیمیِ `withdrawn` وجود داشته باشد.
- Bulk enroll و عملیات تک‌نفره مدیر هر دو Enrollment قبلیِ `withdrawn` را به‌درستی دوباره فعال می‌کنند.

## معیار پذیرش RC53

- Health Center: `Program reapply = mode: self-service | source: withdrawn-only | target: submitted | approval: normal-flow | capacity: rechecked | enrollment: reactivate-after-accept`.
- Schema=`2026.10.11.2` و Ledger=`41/41`.
- Programs migrations=`12` و Physical diagnostics=`13/13`.
- عضو Withdrawn در Program با ثبت‌نام باز دکمه «ثبت‌نام مجدد» ببیند.
- پس از ثبت مجدد، وضعیت به «ثبت‌شده» برگردد و پیام قدیمی «انصراف شما ثبت شده است» دیگر تنها اقدام صفحه نباشد.
- پذیرش/ثبت‌نام قطعی مجدد ظرفیت را درست محاسبه کند و Attendance history قبلی را حذف نکند.

---

# بسته canonical پروژه قرآن‌یار — RC52

این نسخه یک Maintenance محدود برای **خوانایی، کنتراست و زبان انسانی برنامک عضو** است. Phase F / Design System همچنان **فریز و شروع‌نشده** باقی می‌ماند.

- نسخه افزونه: `1.3.1-rc52`
- Schema: `2026.10.11.2` — بدون تغییر نسبت به RC51
- Migration Ledger هدف: `40/40` — Migration جدید ندارد
- Members migrations هدف: `8`
- Physical diagnostics هدف: `13/13`

## تصمیم محصول RC52

- متن پایه برنامک عضو به `16px` ارتقا یافت و متن‌های توضیحی/فشرده نیز بزرگ‌تر شدند.
- رنگ متن ثانویه تیره‌تر شد و جفت‌رنگ‌های اصلی متن روی پس‌زمینه‌های خود با معیار WCAG AA خودکار بررسی شدند.
- Bottom Navigation، Badgeها، متن کارت‌های Home، Services، Programs و Attendance خواناتر شدند.
- متن‌های ماشینی و نیمه‌فنی مانند «همگام‌سازی»، «پاسخ سرور»، «ثبت قدیمی»، نمایش نسخه فنی برنامک و توضیح‌های داخلی حذف یا انسانی‌سازی شدند.
- صفحه «خدمات» از KPI غیرضروری «خدمات فعال / نیازمند توجه» خالی شد؛ وضعیت هر خدمت روی همان کارت دیده می‌شود.
- خطاهای خام مرورگر/شبکه مانند `Failed to fetch` دیگر مستقیماً به کاربر نشان داده نمی‌شوند و پیام فارسی قابل فهم جایگزین شده است.
- متن‌های توضیحی بلند در نمای عضو Justify می‌شوند؛ تیترها و کنترل‌ها همچنان تراز طبیعی خود را دارند.
- منطق Permission، Tenant، OTP، Queue، Program، Attendance و Schema تغییر نکرده است.

## معیار پذیرش RC52

- Health Center: `Member app readability = base: 16px | secondary: 13px typical | compact: 12.5px min | contrast: AA | copy: plain-fa | technical: hidden`.
- Schema=`2026.10.11.2` و Ledger=`40/40` باقی بماند.
- در موبایل، متن بدنه، توضیحات کارت‌ها، Bottom Navigation و فرم پرونده بدون نیاز به Zoom خوانا باشند.
- هیچ عبارت خام شبکه/سرور یا نسخه فنی برنامک در UI عضو دیده نشود.
- Home، Profile، Programs، Attendance، Services و More از نظر Regression عملیاتی سالم بمانند.

---

# QuranYar Messenger 1.3.1-rc51

## RC51 — Maintenance: تأیید شماره موبایل با OTP آی‌پی‌پنل

- Phase F همچنان **Frozen / Not Started** است؛ RC51 فقط مالکیت شماره موبایل را به جریان ثبت‌نام/ویرایش پرونده اضافه می‌کند.
- برای **ثبت‌نام جدید** و هر **تغییر شماره موبایل**، ذخیره پرونده فقط پس از تأیید کد ۶ رقمی OTP ممکن است. Backend این الزام را مستقل از JavaScript enforce می‌کند.
- ارسال OTP با IPPanel Edge و `Pattern SMS` انجام می‌شود؛ کلید API به‌صورت AES-256-GCM در TokenStore ذخیره می‌شود و شماره فرستنده، Pattern Code و نام پارامتر از مدیریت کل قابل تنظیم‌اند.
- OTP پنج دقیقه اعتبار دارد، حداکثر ۵ تلاش تأیید، فاصله حداقل ۶۰ ثانیه بین ارسال‌ها و سقف‌های ساعتی کاربر/شماره دارد. کد خام در DB/log ذخیره نمی‌شود و فقط HMAC کوتاه‌عمر در transient نگهداری می‌شود.
- ستون‌های `mobile_verified_at_utc` و `mobile_verification_required` به اعضا اضافه شده‌اند. شماره‌های Legacy به‌دروغ «تأییدشده» علامت نمی‌خورند؛ تا وقتی تغییر نکنند grandfathered هستند و با تغییر شماره OTP اجباری می‌شود.
- Profile Progress یک شرط پایه جدید `mobile_otp` دارد؛ برای ثبت‌نام‌های جدید ۱۰۰٪ فقط با شماره تأییدشده ممکن است.
- Health Center کارت `Mobile OTP` را با provider/channel/configuration نشان می‌دهد. تا تنظیم IPPanel این کارت Ready عملیاتی نیست.
- Migration فیزیکی `20261011_040_rc51_mobile_otp_verification` متعلق به `members` است؛ Schema=`2026.10.11.2`، Ledger هدف=`40/40` و Members migrations هدف=`8`.

# QuranYar Messenger 1.3.1-rc50

## RC50 — Maintenance: انتساب خانوادگی مستقل از نقش عضویت

- Phase F همچنان **Frozen / Not Started** است؛ RC50 فقط مدل انتساب خانواده پرسنل را تثبیت می‌کند.
- `member_role` نقش اصلی دانشگاهی می‌ماند و رابطه خانوادگی در یک graph مستقل ذخیره می‌شود؛ بنابراین یک فرد می‌تواند هم‌زمان «کارمند/استاد» و «فرزند/همسر یک پرسنل» باشد.
- برای `staff_family` حداقل یک انتساب معتبر الزامی است؛ برای `staff / faculty / student` انتساب خانوادگی اختیاری است.
- دو والد همکار با دو رابطه مستقل `child` پشتیبانی می‌شوند. فرزندی که خودش همکار است، نقش اصلی `staff/faculty` را حفظ و هم‌زمان رابطه `child` ثبت می‌کند.
- پرسنل مقصد باید در همان دانشگاه، با نقش `staff/faculty` و هویت تأییدشده وجود داشته باشد. برای حفظ حریم خصوصی فهرست عمومی پرسنل وجود ندارد؛ افزودن ارتباط جدید با کد ملی دقیق انجام می‌شود.
- جدول جدید `qys_family_links` مالک canonical رابطه‌هاست و scope دانشگاه را روی هر link نگه می‌دارد.
- انتقال دانشگاه scope جدا دارد؛ تأیید انتقال فقط وقتی ممکن است که انتساب لازم در دانشگاه مقصد معتبر باشد.
- فهرست اعضای مدیرکل، پنل دانشگاه، فهرست متقاضیان Program و نمای افراد حضور، انتساب را نمایش می‌دهند؛ خروجی CSV Program/Attendance نیز ستون «انتساب خانوادگی» دارد.
- Profile Progress برای `staff_family` یک شرط conditional اضافه دارد؛ سایر نقش‌ها همان ۸ شرط RC49 را حفظ می‌کنند. Program/Attendance/Birthday readiness از همین کامل‌بودن authoritative تبعیت می‌کنند.
- Migration فیزیکی `20261011_039_rc50_family_attribution` متعلق به `members` است؛ Schema به `2026.10.11.1` و Ledger هدف به `39/39` می‌رسد.
- داده‌های قدیمی `staff_family` به‌صورت حدسی به کسی منتسب نمی‌شوند؛ تا ثبت رابطه واقعی، پرونده آنان ناقص محسوب می‌شود.

# QuranYar Messenger 1.3.1-rc49

## RC49 — اصلاح محصول: مجوز دریافت پیام پیش‌نیاز اجباری پرونده

- Phase F همچنان **Frozen / Not Started** است؛ این نسخه فقط تصمیم محصول درباره الزام پیام‌رسانی را اصلاح می‌کند.
- برای عضو جدید، فرم پرونده تا پیش از موفقیت `requestWriteAccess` ایتا باز نمی‌شود و Backend نیز ثبت مستقیم بدون `consent=true` را رد می‌کند.
- برای عضو موجودی که دریافت پیام او متوقف شده است، `profile_complete=false` می‌شود و ویرایش پرونده/Programs/Attendance تا فعال‌سازی مجدد مجوز مسدود می‌ماند.
- گزینه توقف دریافت پیام از برنامک عضو حذف/مسدود شده است؛ توقف مدیریتی همچنان برای مدیرکل حفظ می‌شود و در صورت اعمال، پرونده تا فعال‌سازی مجدد کامل تلقی نمی‌شود.
- Progress پرونده از ۷ شرط به ۸ شرط canonical ارتقا یافت: مجوز پیام + دانشگاه فعال + نقش + نام + نام خانوادگی + کد ملی + موبایل + تاریخ تولد. بنابراین consent خاموش حتی با همه اطلاعات پرشده، هرگز ۱۰۰٪ نیست.
- Program access gate حالت صریح `consent_required` دارد و Attendance roster/export نیز فقط عضو دارای `consent=1` را واجد شرایط می‌داند.
- Status API همچنان `registered` را از وجود رکورد می‌گیرد، اما `membership_active` فقط با consent فعال `true` است.
- Migration جدید `20261007_038_rc49_required_messaging_consent` متعلق به `members` است؛ Schema فیزیکی بدون تغییر `2026.10.06.2` می‌ماند و Ledger هدف `38/38` است.
- Migrationهای تاریخی E1/E10 replay-safe شده‌اند: تاریخچه تصمیم قبلی حفظ می‌شود اما قرارداد جاری RC49 بر آن تقدم دارد.

# QuranYar Messenger 1.3.1-rc48

## RC48 — اصلاح محصول: پیام وضعیت Program سراسری

- Phase F همچنان **Frozen / Not Started** است.
- پیام‌های پنج‌گانه تغییر وضعیت متقاضی دیگر داخل هر Program تنظیم جدا ندارند.
- یک پیش‌فرض سراسری سامانه و یک override سراسری برای هر دانشگاه وجود دارد؛ هر مجموعه روی **همه Programهای همان scope** اعمال می‌شود.
- متغیر `{برنامه}` نام Program جاری را هنگام Queue شدن پیام جای‌گذاری می‌کند، بنابراین یک Template مشترک برای همه برنامه‌ها کافی است.
- تنظیمات از جزئیات Program حذف و به صفحه اصلی «برنامه‌ها» منتقل شده است.
- Optionهای Legacy مربوط به Program+University حذف نمی‌شوند اما دیگر خوانده/نوشته نمی‌شوند.
- Queue canonical، Consent، Eligibility، Tenant snapshot، Delivery state و ایمنی Worker RC46 بدون تغییر حفظ شده‌اند.
- Migration جدید `20261007_037_rc48_global_program_status_templates` فقط قرارداد code/config را ثبت می‌کند؛ Schema همچنان `2026.10.06.2` است و Ledger مورد انتظار `37/37` خواهد بود.

# QuranYar Messenger 1.3.1-rc47

## RC47 — Maintenance UX Follow-up: ویرایش جمع‌شونده پیام

- Phase F همچنان **Frozen / Not Started** است؛ این نسخه فقط ادامه Maintenance پیام‌رسانی RC46 است.
- فرم «ویرایش پیام تأییدشده» دیگر به‌صورت پیش‌فرض باز نیست. Preview همچنان یک نمونه نهایی را نشان می‌دهد و Editor فقط با اقدام صریح کاربر باز می‌شود.
- Summary ویرایش دو حالت روشن «باز کردن ویرایش / بستن ویرایش» دارد و توضیح می‌دهد که تا پیش از شروع Delivery امکان تغییر متن وجود دارد.
- منطق ایمنی RC46 بدون تغییر حفظ شده است: Edit فقط وقتی همه Queue rowها `pending` باشند، بازسازی payload تراکنشی است و Worker پس از Claim متن canonical را دوباره می‌خواند.
- بخش «پیام‌های تغییر وضعیت متقاضی» در Program همچنان یک Disclosure بسته است، اما CTA آن واضح‌تر شده: «باز کردن تنظیمات / بستن تنظیمات» و مشخص می‌کند ۵ متن وضعیت قابل ویرایش هستند. Quicknav «پیام‌های وضعیت» حفظ شده است.
- Health Center در کارت‌های `Message preview / edit` و `Program status notify` مقدار `editor: collapsed-disclosure` را نیز گزارش می‌کند تا رفتار UX روی Production قابل بررسی باشد.
- RC47 **Migration جدید ندارد**؛ Schema همان `2026.10.06.2` و Ledger مورد انتظار همان `36/36` باقی می‌ماند.

# QuranYar Messenger 1.3.1-rc46

## RC46 — Maintenance Freeze: پیام‌رسانی و اعلان وضعیت Program

- توسعه Roadmap در مرز Phase F موقتاً فریز است؛ RC46 فقط سه مشکل جاری محصول را اصلاح می‌کند و Design System را آغاز نمی‌کند.
- صفحه «ارسال پیام» در پیش‌نمایش دیگر ردیف همه گیرندگان را نمایش نمی‌دهد؛ فقط **یک نمونه متن نهایی** همراه با شمارنده‌های کل کارزار دیده می‌شود.
- پیام‌های تأییدشده (`scheduled` یا `active`) تا پیش از شروع اولین Delivery قابل ویرایش‌اند. ویرایش، عنوان/Template و payload تمام ردیف‌های `pending` را در یک Transaction بازسازی می‌کند. با شروع حتی یک Delivery، ویرایش برای جلوگیری از دریافت نسخه‌های متفاوت قفل می‌شود.
- Worker پس از Claim، payload را دوباره از Queue می‌خواند؛ بنابراین رقابت Edit/Worker نمی‌تواند متن قدیمی انتخاب‌شده پیش از ویرایش را به شبکه بفرستد.
- در Program، تغییر وضعیت مدیریتی متقاضی به «در حال بررسی / فهرست انتظار / پذیرفته‌شده / ردشده» و همچنین «ثبت‌نام قطعی» بلافاصله یک اعلان را در **صف canonical امن** قرار می‌دهد.
- متن اعلان‌های Program برای هر Program و University قابل ویرایش است و از متغیرهای `{نام}`، `{نام خانوادگی}`، `{برنامه}`، `{وضعیت}` و `{دانشگاه}` پشتیبانی می‌کند. Campus override بر متن عمومی Program اولویت دارد.
- فقط عضو تأییدشده با Messaging Consent فعال وارد صف اعلان وضعیت می‌شود؛ خود تغییر وضعیت مستقل از موفقیت پیام باقی می‌ماند.
- کارزارهای سیستمی `program_status` در فهرست انسانی ارسال هدفمند نمایش داده نمی‌شوند، ولی همان Queue/Lease/Delivery State و Tenant authorization را مصرف می‌کنند.
- Health Center دو قرارداد جدید `Message preview / edit` و `Program status notify` را نشان می‌دهد.
- Migrationهای کدی `20261007_035_rc46_targeted_message_edit` و `20261007_036_rc46_program_status_notifications` اضافه شده‌اند؛ Schema همان `2026.10.06.2` است.
- RC45/E10 نیز در این بسته حفظ شده است؛ ارتقای مستقیم RC44→RC46 باید در پایان Ledger را به `36/36` برساند.

# QuranYar Messenger 1.3.1-rc45

## RC45 — Phase E / E10: اصلاح Progress پرونده

- درصد تکمیل پرونده دیگر در Client با شمارش صرف فیلدهای غیرخالی محاسبه نمی‌شود؛ Status API مقدار authoritative را از Server برمی‌گرداند.
- مبنای Progress دقیقاً همان ۷ جزء لازم برای `profile_complete` است: دانشگاه فعال، عنوان عضویت، نام، نام خانوادگی، کد ملی، موبایل و تاریخ تولد.
- `100٪` فقط وقتی ممکن است که `profile_complete=true` باشد؛ بنابراین حالت گمراه‌کننده «۱۰۰٪ ولی پرونده ناقص» برای دانشگاه نامعتبر/غیرفعال یا فیلد canonical نامعتبر ایجاد نمی‌شود.
- پاسخ Status فیلد `profile_progress` با `percent/completed/total/complete/missing` دارد؛ `missing` فقط کلیدهای غیرحساس فیلد را نگه می‌دارد و هیچ PII تازه‌ای اضافه نمی‌کند.
- نوار Progress پس از کامل‌شدن پرونده همچنان پنهان می‌شود و برای نسخه قدیمی پاسخ API fallback محافظه‌کارانه `100/0` دارد، نه محاسبه مستقل Client.
- Health Center کارت `Profile progress = source: server | basis: canonical-required | fields: 7 | 100: complete-only | ui: incomplete-only` دارد.
- Migration `20261006_034_rc45_profile_progress` متعلق به Members است؛ Schema همان `2026.10.06.2` باقی می‌ماند.
- E9 روی Production Health تأیید شده و Manual UX Acceptance طبق سیاست پروژه در بسته نهایی UX/E2E انجام می‌شود.

# QuranYar Messenger 1.3.1-rc44

## RC44 — Phase E / E9: مدیریت گروهی متقاضیان

- بخش متقاضیان Program دارای جست‌وجوی نام و فیلترهای وضعیت درخواست، گروه عضو و داشتن/نداشتن ثبت‌نام قطعی است.
- فیلترها در Repository و با حفظ Tenant Scope اعمال می‌شوند؛ جست‌وجوی عمومی عمداً کد ملی و موبایل را وارد سطح جست‌وجو نمی‌کند.
- مدیر می‌تواند تا ۱۰۰ متقاضی انتخاب‌شده را در یک اجرا به «بررسی»، «فهرست انتظار»، «پذیرفته‌شده»، «ردشده» منتقل کند یا ثبت‌نام قطعی درخواست‌های پذیرفته‌شده را انجام دهد.
- پیش از Bulk، همه شناسه‌های انتخاب‌شده یکجا از نظر Program + Tenant Scope preflight می‌شوند؛ وجود حتی یک شناسه خارج از دامنه، درخواست را fail-closed می‌کند.
- هر ردیف همچنان از Serviceهای canonical transition/enrollment عبور می‌کند؛ قواعد ظرفیت، صلاحیت، Waitlist، تراکنش و Audit دور زده نمی‌شوند.
- عملیات «رد گروهی» هم در Client و هم Server تأیید صریح می‌خواهد؛ نتیجه Bulk به‌صورت موفق/نامرتبط با وضعیت/ناموفق گزارش می‌شود.
- Health Center کارت `Bulk applicants = mode: selected | filters: status+role+enrollment+search | actions: transition+enroll | scope: tenant | max: 100` دارد.
- Migration `20261006_033_rc44_bulk_applicant_management` متعلق به Programs است؛ Schema همان `2026.10.06.2` باقی می‌ماند.
- E8 روی Production Health تأیید شده و Manual UX Acceptance طبق سیاست پروژه در بسته نهایی UX/E2E انجام می‌شود.

# QuranYar Messenger 1.3.1-rc43

## RC43 — Phase E / E8: Validation فیلدبه‌فیلد

- فرم پیش‌ثبت‌نام Program پیش از ارسال، هر فیلد را در همان محل اعتبارسنجی می‌کند و اولین خطای نامعتبر را focus می‌کند.
- فیلد `multi_choice` الزامی اکنون سمت client حداقل یک انتخاب را enforce می‌کند؛ Backend همچنان مرجع نهایی است.
- required/text length/number/date/single-choice/yes-no با پیام inline و `aria-invalid` پوشش داده می‌شوند.
- خطای validation سمت سرور `field_key` امن برمی‌گرداند تا همان فیلد در UI علامت‌گذاری شود.
- ارسال شبکه برای فرم دارای خطای client انجام نمی‌شود و Draft E7 حفظ می‌شود.
- Health Center کارت `Field validation = mode: field-level | required: client+server | multi: one-or-more | error: inline` دارد.
- Migration `20261006_032_rc43_field_level_validation` متعلق به Programs است؛ Schema همان `2026.10.06.2` باقی می‌ماند.
- E7 روی Production Health تأیید شد و Manual UX Acceptance طبق سیاست پروژه در بسته نهایی انجام می‌شود.


## RC42 — Phase E / E7: Draft فرم کاربر

- پاسخ‌های فرم پیش‌ثبت‌نام Program روی همان دستگاه به‌صورت Draft محلی نگهداری می‌شوند.
- scope Draft برابر member + program + form fingerprint است و شناسه خام عضو در storage key ذخیره نمی‌شود.
- TTL برابر ۲۴ ساعت است؛ Draft منقضی حذف می‌شود.
- input/change ذخیره خودکار دارند و navigation/pagehide flush فوری انجام می‌دهد.
- فرم دارای Draft با CTA «ادامه پیش ثبت‌نام» نمایش داده می‌شود و هنگام بازشدن پاسخ‌ها خودکار بازیابی می‌شوند.
- submit موفق Draft را پاک می‌کند؛ خطای submit Draft را حفظ می‌کند.
- هیچ endpoint یا Schema جدیدی اضافه نشده و Schema همان `2026.10.06.2` است.
- Migration `20261006_031_rc42_member_form_draft` متعلق به Programs است.
- E6 روی Production Health تأیید شد و تست دستی Draft طبق سیاست پروژه در پذیرش نهایی UX/E2E انجام می‌شود.

## RC40 — Phase E / E5: Member Withdraw

- عضو تأییدشده اکنون می‌تواند Application یا Enrollment فعال خودش را از داخل برنامک قرآن‌یار Withdraw کند.
- Applicationهای `submitted/under_review/waitlisted/accepted` در نبود Enrollment به `withdrawn` می‌روند.
- Enrollmentهای `enrolled/participating` به `withdrawn` می‌روند و `withdrawn_at_utc` ثبت می‌شود.
- هیچ پاسخ فرم یا Attendance history حذف نمی‌شود و رویداد `member_withdrawal` در Audit ثبت می‌شود.
- REST endpoint جدید `/qys/v1/programs/withdraw` member id را فقط از هویت احرازشده سرور می‌گیرد و confirmation صریح می‌خواهد.
- Mini-app دکمه‌های «پس‌گرفتن درخواست» / «انصراف از برنامه» را فقط برای state قابل انصراف نشان می‌دهد.
- Health Center کارت `Member withdraw = mode: self-service | application: withdrawable | enrollment: withdrawable | confirm: explicit | history: preserved` دارد.
- Migration `20261006_029_rc40_member_withdraw` متعلق به Programs است؛ Schema همان `2026.10.06.2` باقی می‌ماند.
- E4 روی Production Health تأیید شد؛ Manual UX Acceptance طبق سیاست پروژه در پایان انجام می‌شود.

# QuranYar Messenger 1.3.1-rc41

## RC39 — Phase E / E4: ویرایش state-aware مشخصات برنامه

- مشخصات اصلی Program اکنون بر اساس state به‌صورت کنترل‌شده قابل ویرایش‌اند.
- Draft: عنوان، توضیح، ظرفیت، Audience و Attendance configuration قابل ویرایش است.
- Registration open/closed و Confirmed: عنوان/توضیح/ظرفیت و Attendance تا پیش از link قابل ویرایش؛ Audience قفل است.
- Running: فقط عنوان و توضیح؛ Terminalها فقط خواندنی.
- ظرفیت محدود نمی‌تواند از تعهد جاری accepted/enrolled/participating کمتر شود.
- target universities فقط در Draft و برای مدیرکل قابل تغییر است و manager university باید در scope باقی بماند.
- Attendance پس از ایجاد link واقعی immutable است.
- Health Center کارت `Program edit = mode: state-aware | capacity: reserved-floor | audience: draft-only | attendance: pre-link` دارد.
- Migration `20261006_028_rc39_program_edit_workflow` متعلق به Programs است؛ Schema همان `2026.10.06.2` باقی مانده است.
- E3 روی Production Health تأیید شد؛ Manual UX destructive confirmations طبق سیاست پروژه در پذیرش نهایی انجام می‌شود.

# QuranYar Messenger 1.3.1-rc39

## RC38 — Phase E / E3: تأیید صریح عملیات برگشت‌ناپذیر

- پایان برنامه، لغو برنامه، بایگانی و رد متقاضی بدون تأیید صریح کاربر اجرا نمی‌شوند.
- فرم‌های این عملیات دارای `data-qys-confirm` و marker سروری `qys_destructive_confirmed` هستند.
- اسکریپت مشترک `qys-confirm.js` فقط در بخش Programs پنل دانشگاه/مدیرکل بارگذاری می‌شود و پیش از submit پیام اثر عملیات را نمایش می‌دهد.
- لغو تأیید، submit را متوقف می‌کند؛ تأیید موفق marker را `1` می‌کند و سپس مسیر عادی nonce/permission/domain transition ادامه پیدا می‌کند.
- Backend نیز marker را اجباری می‌کند؛ POST مستقیم یا JavaScript خاموش نمی‌تواند عملیات برگشت‌ناپذیر را بدون تأیید نهایی اجرا کند.
- سایر transitionهای غیرپایانی مانند باز/بسته‌کردن ثبت‌نام، قطعی‌کردن، شروع، بررسی، انتظار و پذیرش رفتار قبلی را حفظ کرده‌اند.
- Health Center کارت `Destructive confirmation = program: complete/cancel/archive | applicant: reject | server: required` دارد.
- Migration زیرساختی `20261006_027_rc38_destructive_confirmations` متعلق به module `programs` است؛ Schema همچنان `2026.10.06.2` است.
- E2 روی Production تأیید شد: Ledger=`26/26` و Programs access gate=`explain/explicit/none`.

# QuranYar Messenger 1.3.1-rc38

## RC36 — Phase E / E1: جداسازی Membership از Messaging Consent

- تشکیل پرونده و ویرایش مشخصات دیگر به رضایت دریافت پیام خصوصی وابسته نیست.
- عضو جدید اگر opt-in پیام را صریحاً فعال نکرده باشد با `consent=0` ثبت می‌شود؛ Existing member در ویرایش پرونده consent قبلی خود را حفظ می‌کند.
- `requestWriteAccess` در mini-app اختیاری است و ردشدن آن دکمه ثبت پرونده را غیرفعال نمی‌کند.
- Status API فیلدهای صریح `membership_active` و `messaging_consent` را در کنار `registered/enabled` سازگار ارائه می‌کند.
- Welcome فقط وقتی برای عضو جدید attempt می‌شود که messaging consent فعال باشد.
- حساب/پرونده به‌دلیل messaging-off ناقص تلقی نمی‌شود؛ تبریک تولد نیز فقط با birthday opt-in + messaging consent واقعاً فعال نمایش داده می‌شود.
- Health Center کارت `Membership / messaging = membership: independent | messaging: optional | default: off` دارد.
- Migration زیرساختی `20261006_025_rc36_membership_messaging_consent` متعلق به module `members` است؛ Schema همچنان `2026.10.06.2` است.
- D10 روی Production تأیید شد و با آن Phase D کامل شد: Ledger=`24/24` و Portal assets=`base/conditional/section+permission`.

# QuranYar Messenger 1.3.1-rc37

## RC35 — Phase D / D10: conditional asset loading

- `/qys/` اکنون فقط shell مشترک پنل دانشگاه را eager بارگذاری می‌کند؛ bundleهای Feature به section فعال محدود شده‌اند.
- Attendance فقط در `section=attendance`، Targeted فقط در `section=targeted`، Programs فقط در `section=programs` و Excel فقط در `section=excel` asset اختصاصی می‌گیرند.
- Feature asset فقط وقتی enqueue می‌شود که module مربوط booted و permission همان Feature برای دانشگاه جاری مجاز باشد؛ section نامعتبر/بدون مجوز asset اختصاصی دریافت نمی‌کند.
- مالکیت assetها از `QYS_Multitenant` خارج شد: Attendance و Targeted bundle خود را enqueue می‌کنند؛ Programs/Excel نیز از policy مرکزی استفاده می‌کنند.
- قرارداد جدید `QYS_Portal_Asset_Policy` برابر `shell: base | feature: conditional | scope: section+permission` است و Health Center کارت `Portal assets` دارد.
- روی Home/Members/Messages/More حدود 79.8KB asset اختصاصی خام (پیش از gzip/brotli) دیگر eager بارگذاری نمی‌شود.
- Migration زیرساختی `20261006_024_rc35_conditional_portal_assets` متعلق به module `universities` است؛ Schema همچنان `2026.10.06.2` است.
- D9 روی Production تأیید شد: Ledger=`23/23` و Birthday lookup=`birth_md/exact/index ready`.

# QuranYar Messenger 1.3.1-rc35

## RC34 — Phase D / D9: Birthday lookup index

- جست‌وجوی تولد دیگر از `birth_jalali LIKE '%MM/DD'` استفاده نمی‌کند؛ کلید materialized جدید `birth_md` با مقدار `MM/DD` نگه‌داری می‌شود.
- سه مسیر اصلی تولد (شمارش دانشگاه‌های فعال، scan اعضای امروز و شمارش واجد شرایط دانشگاه) به exact match روی `birth_md` منتقل شدند.
- index فیزیکی `birthday_lookup (birth_md,birthday_opt_in,verified,consent,id)` برای fresh install و migration موجود اضافه شد.
- `QYS_Member_Repository::insert/update` هنگام تغییر `birth_jalali`، مقدار `birth_md` را همگام می‌کند؛ migration داده‌های موجود را یک‌بار backfill می‌کند.
- Health Center کارت `Birthday lookup = column: birth_md | query: exact | index: ready` دارد.
- Migration فیزیکی `20261006_023_rc34_birthday_lookup_index` متعلق به module `birthday` است؛ Schema به `2026.10.06.2` ارتقا یافته است.
- D8 روی Production تأیید شد: Ledger=`22/22` و Batch export=`streaming/chunk250/keyset/bounded`.

# QuranYar Messenger 1.3.1-rc34

## RC33 — Phase D / D8: Batch export

- `QYS_Batch_Export` مرز مشترک خروجی CSV با قرارداد `streaming | chunk: 250 | cursor: keyset | memory: bounded` است.
- Program CSV دیگر به ۱۰۰۰ Application محدود نیست؛ Applicationها keyset/chunked و Answers همان chunk به‌صورت batch خوانده می‌شوند.
- Excel follow-up export دیگر کل گزارش را پیش از دانلود در حافظه جمع نمی‌کند و import exclusions / queue non-sent را chunked صادر می‌کند.
- Attendance و Birthday archive نیز در مسیر export از OFFSET به keyset منتقل شدند؛ permission، tenant scope، masking و audit قبلی حفظ شده‌اند.
- `fputcsv` و `php://output` در runtime فقط داخل `QYS_Batch_Export` قرار دارند.
- Health Center کارت `Batch export = mode: streaming | chunk: 250 | cursor: keyset | memory: bounded` دارد.
- Migration زیرساختی `20261006_022_rc33_batch_export` متعلق به module `core` است؛ Schema همچنان `2026.10.06.1` است.
- D7 روی Production تأیید شد: Ledger=`21/21` و Health snapshot=`heartbeat: lightweight | full: 15m/on-demand`.

# QuranYar Messenger 1.3.1-rc33

## RC32 — Phase D / D7: Health snapshot سبک‌تر

- heartbeat یک‌دقیقه‌ای Worker دیگر snapshot کامل سلامت را اجرا نمی‌کند و فقط metadata سبک شروع/پایان را ثبت می‌کند.
- snapshot کامل با hook مستقل `qys_health_snapshot` هر ۱۵ دقیقه اجرا می‌شود و همچنان از صفحه Health و Ability به‌صورت on-demand قابل محاسبه است.
- قرارداد D7: `heartbeat: lightweight | full: 15m/on-demand`؛ Health Center کارت مستقل آن را نمایش می‌دهد.
- آخرین اجرای snapshot دوره‌ای فقط زمان، مدت و status aggregate بدون PII را در option non-autoload نگه می‌دارد.
- Migration زیرساختی `20261006_021_rc32_lightweight_health_snapshot` متعلق به module `health` است؛ Schema همچنان `2026.10.06.1` است.
- D6 روی Production تأیید شد: Ledger=`20/20`، throughput=`adaptive/20s/max30/rate-limit stop` و Queue index=`ready/indexed`.

# QuranYar Messenger 1.3.1-rc32

## RC31 — Phase D / D6: Queue throughput adaptive

- Worker صف دیگر به سقف ثابت ۵ پیام در هر cron محدود نیست و cap هر run با EMA هزینه واقعی outboundها تنظیم می‌شود.
- قرارداد D6: `mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`؛ اولین run بدون history با cap=12 شروع می‌شود.
- Provider throttle (`retryable_failed/429`) یا outcome نامشخص (`uncertain`) همان run را متوقف می‌کند تا خطا روی ادامه صف پخش نشود.
- lease، outbound-start marker، permission/eligibility checks و delivery-state semantics Phase B بدون تغییر حفظ شده‌اند.
- Telemetry جدید `qys_queue_throughput` فقط aggregateهای بدون PII را به‌صورت non-autoload نگه می‌دارد.
- Migration زیرساختی `20261006_020_rc31_adaptive_queue_throughput` متعلق به module `messaging` است؛ Schema همچنان `2026.10.06.1` است.
- D5 روی Production تأیید شد: Ledger=`19/19`، Queue dispatch index=`(status,id) ready` و runtime probes=`0`.

# QuranYar Messenger 1.3.1-rc31

## RC30 — Phase D / D5: Queue index `(status,id)`

- جدول صف برای مسیر داغ Worker یک index مرکب جدید با prefix دقیق `(status,id)` دارد.
- query انتخاب پیام‌های pending همچنان `WHERE status='pending' ... ORDER BY id` است و اکنون می‌تواند filter + order را از index مناسب بگیرد.
- نصب جدید index را از ابتدا با `KEY status_id (status,id)` می‌سازد؛ ارتقای RC29→RC30 آن را idempotent به جدول موجود اضافه می‌کند.
- Health Center کارت `Queue dispatch index` با انتظار `(status,id): ready | pending scan: indexed` دارد.
- Migration فیزیکی `20261006_019_rc30_queue_status_id_index` متعلق به module `messaging` است.
- Schema به `2026.10.06.1` ارتقا یافته است؛ هیچ داده دامنه‌ای rewrite نمی‌شود.
- D4 روی Production تأیید شد: Ledger=`18/18`، Mini-app feature load=`lazy/lazy/on_view` و runtime schema probes=`0`.

# QuranYar Messenger 1.3.1-rc30

## RC29 — Phase D / D4: Lazy load Programs/Attendance در mini-app

- JS/CSS کامل Programs و Attendance دیگر در بازشدن اولیه mini-app enqueue نمی‌شوند و فقط در اولین ورود به view مربوط بارگذاری می‌شوند.
- Home Program cards مستقل از Feature کامل Programs و همچنان مبتنی بر endpoint سبک `home-summary` هستند.
- Full data برنامه‌ها فقط در view `programs` و Full data حضور فقط در view `attendance` دریافت می‌شود.
- Health Center کارت `Mini-app feature load` با انتظار `programs: lazy | attendance: lazy | data: on_view` دارد.
- Migration زیرساختی `20261006_018_rc29_lazy_member_features` متعلق به module `members` است؛ Schema همچنان `2026.10.05.7` است.
- D3 روی Production تأیید شد: Ledger=`17/17`، Home summary=`lightweight/compact/deferred` و runtime schema probes=`0`.

# QuranYar Messenger 1.3.1-rc29

## RC28 — Phase D / D3: Home Summary Endpoint سبک

- endpoint جدید `qys/v1/home-summary` فقط read model فشرده صفحه خانه را برمی‌گرداند.
- کارت‌های Program در Home دیگر `programs/list` و Form/Fieldهای کامل را دریافت نمی‌کنند؛ حداکثر ۳ Program با یک query محدود خوانده می‌شود.
- endpoint کامل `programs/list` برای خود نمای Programs حفظ شده و قرارداد محصول تغییر نکرده است.
- Health Center کارت `Home summary` با انتظار `endpoint: lightweight | programs: compact | forms: deferred` دارد.
- Migration زیرساختی `20261006_017_rc28_home_summary` متعلق به module `members` است؛ Schema همچنان `2026.10.05.7` است.
- D2 روی Production تأیید شد: Ledger=`16/16` و Programs query plan=`batch/grouped/batch`.

# QuranYar Messenger 1.3.1-rc28

## RC27 — Phase D / D2: حذف N+1های Programs

- فرم‌های Program در mini-app به‌صورت batch hydrate می‌شوند؛ per-Program `current_form/form_fields` حذف شده است.
- شمارنده‌های Program index با دو grouped query ثابت ساخته می‌شوند و دیگر با تعداد Program رشد خطی ندارند.
- پاسخ‌های متقاضیان در detail و CSV export با یک batch query بارگیری می‌شوند.
- `admission_counts()` از چهار DB roundtrip به یک aggregate query کاهش یافته است.
- Health Center کارت `Programs query plan` با انتظار `forms: batch | counts: grouped | answers: batch` دارد.
- Migration زیرساختی `20261006_016_rc27_program_batch_queries` متعلق به module `programs` است؛ Schema همچنان `2026.10.05.7` است.
- D1 روی Production تأیید شد: runtime schema probes=`0` و Physical diagnostics=`12/12`.

# QuranYar Messenger 1.3.1-rc27

## RC26 — Phase D / D1: حذف Runtime Schema Introspection

- `QYS_Schema_Runtime` readiness مسیر عادی را از introspection فیزیکی جدا می‌کند؛ runtime بر `install_state + schema_version + Migration Ledger` متکی است.
- `SHOW TABLES / SHOW COLUMNS / SHOW INDEX` از مسیر عادی حذف و فقط در Diagnosticهای صریح `QYS_Table_Names` متمرکز شده‌اند.
- Repository/Domain readinessها به runtime fast-check و diagnostic physical-check تفکیک شده‌اند؛ Installer/Migration همچنان ساختار واقعی را بررسی می‌کنند.
- Health Center دو شاخص جدید دارد: `Runtime schema gate` با انتظار `probes: 0` و `Schema diagnostics` با انتظار `Physical: 12 / 12`.
- Migration زیرساختی `20261006_015_rc26_runtime_schema_guard` به module `core` اضافه شده؛ Schema همچنان `2026.10.05.7` و بدون تغییر فیزیکی دیتابیس است.
- Phase C با Production Verification RC25 کامل شده و RC26 اولین گام Performance Hardening در Phase D است.

# QuranYar Messenger 1.3.1-rc26

## RC25 — Phase C / C7: Canonical Module Registry

- `QYS_Module_Registry` اکنون منبع canonical ماژول‌های Lifecycle و metadata قابلیت‌های پنل دانشگاه است.
- ۱۰ Feature پنل توسط ماژول واقعی خود ثبت می‌شوند؛ Registry جداگانه Navigation دیگر منبع حقیقت نیست.
- `QYS_Portal_Feature_Service` permission، availability و dispatch را از Registry pure جدا می‌کند و cycle Registry ↔ Multitenant را می‌بندد.
- `QYS_Feature_Registry` فقط به‌عنوان compatibility facade باقی مانده و runtime داخلی هیچ referenceای به آن ندارد.
- `feature-navigation` با ماژول حیاتی `module-registry` جایگزین شده؛ تعداد ماژول‌ها همچنان ۱۷ است.
- Migration زیرساختی `20261006_014_rc25_module_registry` اضافه شده؛ Schema همچنان `2026.10.05.7` و بدون تغییر فیزیکی دیتابیس است.
- Health Center آمادگی Module Registry (`17 modules / 10 features`) و Feature count هر ماژول را نمایش می‌دهد.
- با Production Verification این نسخه، Phase C کامل و Phase D Performance آغاز می‌شود.

# QuranYar Messenger 1.3.1-rc25

## RC24 — Phase C / C6: Repository Ownership

- Persistence در دامنه‌های repository-backed اکنون مالک canonical دارد: Programs، Members، Campaign/Targeted و Universities.
- `QYS_Program_Service` و `QYS_Programs_Module` دیگر SQL یا `$wpdb` مستقیم ندارند؛ همه Program/Application/Enrollment/Form persistence از `QYS_Program_Repository` عبور می‌کند.
- `QYS_Members_Module` عملیات Birthday pending مرتبط با عضو را به `QYS_Member_Repository` واگذار می‌کند و SQL مستقیم ندارد.
- `QYS_Targeted` برای metadata write/launch/cancel/due-selection به `QYS_Campaign_Repository` delegate می‌کند؛ فقط DDL متعلق به schema feature در خود ماژول باقی مانده است.
- تراکنش‌ها و Audit runtime چنددانشگاهی از `QYS_University_Repository` عبور می‌کنند؛ Multitenant فقط schema ownership/diagnostics خود را نگه می‌دارد.
- Migration زیرساختی `20261006_013_rc24_repository_ownership` اضافه شده است؛ هیچ جدول/ستون یا داده دامنه‌ای تغییر نمی‌کند و Schema همچنان `2026.10.05.7` است.
- C6 رفتار business را تغییر نمی‌دهد و تبدیل نهایی `FeatureRegistry → Module Registry` را به C7 واگذار می‌کند.

# QuranYar Messenger 1.3.1-rc24

## RC23 — Phase C / C5: Dependency Cycle Break

- cycle `Programs ↔ Attendance` به dependency یک‌طرفه تبدیل شد؛ Programs فقط contract هسته را می‌شناسد و Adapter در Attendance قرار دارد.
- Module Lifecycle اکنون وابستگی `attendance → programs` را صریح گزارش می‌کند؛ خرابی Attendance نباید Programs را از Boot خارج کند.
- `Permission ↔ Targeted` شکسته شد؛ Permission برای metadata/visibility کارزار به `QYS_Campaign_Repository` متکی است و دیگر `QYS_Targeted` را نمی‌شناسد.
- `Security ↔ Multitenant` شکسته شد؛ owner/page optionها و entry slugهای canonical در `QYS_Config` هستند و Security دیگر به implementation Multitenant وابسته نیست.
- Migration زیرساختی `20261006_012_rc23_dependency_cycle_break` اضافه شده است؛ Schema دامنه همچنان `2026.10.05.7` است.
- C5 عمداً Repository ownership کامل را به C6 و تبدیل نهایی FeatureRegistry به Module Registry را به C7 واگذار می‌کند.

# QuranYar Messenger 1.3.1-rc23

## RC22 — Phase C / C4: Core Service Extraction

- شش مرز پایه `QYS_Config`، `QYS_Token_Store`، `QYS_Table_Names`، `QYS_Text_Normalizer`، `QYS_Calendar_Service` و `QYS_Bootstrap_Facade` از God Class تاریخی استخراج شدند.
- APIهای قدیمی `QYS_Messenger` برای سازگاری باقی مانده‌اند اما helperهای استخراج‌شده را delegate می‌کنند.
- call siteهای مشترک جدول/متن/تقویم/توکن/پیکربندی در Domain و Repository تا حد امن مستقیماً به سرویس‌های جدید منتقل شده‌اند.
- Migration زیرساختی `20261006_011_rc22_core_services` اضافه شده است؛ Schema دامنه همچنان `2026.10.05.7` است.
- تعداد ماژول‌ها `17` باقی مانده و Core health اکنون آماده‌بودن service boundaryها را نیز کنترل می‌کند.
- C4 عمداً شکستن dependency cycleها را به C5 موکول می‌کند.

# QuranYar Messenger 1.3.1-rc22

## RC21 — Phase C / C3: Module Lifecycle Contract

- Boot مستقیم و پراکنده ماژول‌ها با `QYS_Module_Manager` و قرارداد `QYS_Module_Contract` جایگزین شده است.
- ۱۷ ماژول runtime دارای dependency، criticality، Migration و Health صریح هستند.
- خطای ماژول غیرحیاتی ایزوله می‌شود و Feature وابسته بدون ازکارانداختن سایر قابلیت‌ها موقتاً unavailable می‌شود.
- Migration جدید `20261006_010_rc21_module_lifecycle` فقط زیرساختی است و Schema دامنه همچنان `2026.10.05.7` باقی می‌ماند.
- Health Center جدول Module Lifecycle و شاخص‌های booted/failed/blocked/degraded را نمایش می‌دهد.
- C3 عمداً استخراج سرویس‌های پایه، شکستن dependency cycleها و تبدیل نهایی FeatureRegistry را به C4 تا C7 واگذار می‌کند.

# QuranYar Messenger 1.3.1-rc21

## RC20 — Phase C / C2: Automatic Upgrade Coordinator

- اجرای Migrationهای post-baseline دیگر وابسته به بازدید مدیر از `wp-admin` نیست؛ runner محدود و lock‌شده روی درخواست عادی WordPress نیز کار می‌کند.
- Fresh install همچنان برای تعیین مالک اولیه به مدیر نیاز دارد؛ C2 فقط Upgradeهای پس از Ledger را خودکار می‌کند.
- RESTهای `qys/v1`، برنامک عضو، پنل دانشگاه، admin-post/AJAX و workerهای قرآن‌یار تا آماده‌شدن Migration به‌صورت fail-closed متوقف می‌شوند.
- Health Center وضعیت Upgrade gate و آخرین اجرای runner خودکار را بدون PII نشان می‌دهد.
- هر درخواست حداکثر یک Migration جدید را آغاز می‌کند؛ Ledger lock مانع اجرای هم‌زمان می‌شود.
- Schema دامنه در RC20 تغییر نکرده و همچنان `2026.10.05.7` است.


## 1.3.1-rc17 — Phase B / B5: سیاست مرکزی ظرفیت و فهرست انتظار

- ظرفیت اکنون در لحظه **پذیرش Application** رزرو می‌شود؛ بنابراین تعداد `accepted`ها دیگر نمی‌تواند از ظرفیت Program عبور کند.
- ظرفیت Program در برنامه‌های مشترک، **سراسری برای کل Program** است و پذیرش هم‌زمان از دانشگاه‌های مختلف با قفل `FOR UPDATE` روی همان Program سریال می‌شود.
- شمارش ظرفیت، Application پذیرفته‌شده بدون Enrollment و Enrollment فعال (`enrolled/participating`) را دقیقاً یک صندلی حساب می‌کند؛ تبدیل پذیرش به ثبت‌نام قطعی صندلی دوم مصرف نمی‌کند.
- Enrollmentهای پایانی مانند `withdrawn/no_show/cancelled/closed` ظرفیت را آزاد می‌کنند.
- اگر ظرفیت تکمیل باشد و Waitlist فعال باشد، پذیرش جدید با پیام کنترل‌شده متوقف می‌شود و مدیر می‌تواند متقاضی را در `waitlisted` قرار دهد.
- اگر Waitlist غیرفعال باشد، انتقال به `waitlisted` از سمت Server نیز رد می‌شود؛ UI فقط ابزار کمکی است و مرز اصلی در Service اعمال می‌شود.
- وقتی ظرفیت پر و Waitlist غیرفعال است، درخواست جدید عضو نیز پذیرفته نمی‌شود؛ اگر Waitlist فعال باشد درخواست اولیه همچنان قابل ثبت است تا مدیر آن را بررسی/منتظر کند.
- ارتقای متقاضی `waitlisted → accepted` فقط در صورت وجود ظرفیت آزاد ممکن است و خودکار نیست؛ این کار عمداً کنترل‌شده و مدیریتی باقی مانده است.
- داده‌های Legacy که از قبل بیش از ظرفیت `accepted` بوده‌اند خودکار تنزل داده نمی‌شوند؛ تعهد تاریخی حفظ و فقط پذیرش جدید مسدود می‌شود.
- صفحه مدیریت Program اکنون ظرفیت رزروشده کل، ظرفیت باقی‌مانده و تعداد کل Waitlist را نشان می‌دهد.
- B5 تغییر فیزیکی Schema ندارد؛ Schema همچنان `2026.10.05.7` است.


## 1.3.1-rc16 — Phase B / B4: هماهنگ‌سازی وضعیت پایانی Program

- تغییر Program به `completed`، `cancelled` یا `archived` اکنون یک Orchestration اتمیک برای وضعیت‌های وابسته اجرا می‌کند.
- در پایان عادی برنامه، Enrollmentهای `participating` به `completed` و `enrolled`های بدون مشارکت به `no_show` تبدیل می‌شوند.
- در لغو برنامه، Enrollment فعال به وضعیت صریح `cancelled` می‌رود؛ این وضعیت با «انصراف عضو» (`withdrawn`) اشتباه گرفته نمی‌شود.
- برای داده‌های قدیمیِ Programهای `archived` که منشأ پایان/لغو قابل تشخیص نیست، Enrollment فعال به وضعیت خنثی `closed` منتقل می‌شود تا تاریخچه جعل نشود.
- Applicationهای بلاتکلیف (`submitted` / `under_review` / `waitlisted`) در برنامه لغوشده به `cancelled` و در برنامه پایان‌یافته/بایگانی‌شده به `closed` تبدیل می‌شوند؛ تصمیم‌های تاریخی `accepted` و `rejected` حفظ می‌شوند.
- Attendance Program متصل هنگام terminal شدن Program بسته می‌شود، `current_session_id` پاک می‌شود و همه occurrenceهای dynamic باز بسته می‌شوند؛ رکوردهای خروج باز حذف یا خودکار بسته نمی‌شوند تا عضو بتواند خروج واقعی خود را بعداً ثبت کند.
- Attendance متصل به Program پایانی دیگر از پنل قابل فعال‌سازی مجدد نیست و QR جدید نیز برای Program پایان‌یافته/لغوشده/بایگانی‌شده ساخته نمی‌شود.
- Application/Enrollment پس از terminal شدن Program دیگر از مسیر مدیریتی قابل تغییر نیستند.
- Migration `reconcile_terminal_programs()` وضعیت Programهای پایانی موجود قبل از RC16 را به‌صورت idempotent با مدل جدید هماهنگ می‌کند.
- Schema به `2026.10.05.7` ارتقا یافته است؛ تغییر Schema ساختاری جدیدی ندارد و bump نسخه برای اجرای data reconciliation است.


## 1.3.1-rc15 — Phase B / B3: پایداری پیام خوش‌آمد و فعال‌سازی

- پیام‌های خوش‌آمد و فعال‌سازی از مدل canonical وضعیت تحویل استفاده می‌کنند: `pending`، `sending`، `sent`، `retryable_failed`، `definitive_failed`، `uncertain` و `cancelled`.
- هر تلاش onboarding دارای Lease ۱۸۰ ثانیه‌ای و `outbound_started_at_utc` است؛ Crash پیش از شبکه به `pending` و Crash پس از شروع شبکه به `uncertain` بازیابی می‌شود.
- `uncertain` و `definitive_failed` هرگز خودکار resend نمی‌شوند.
- خطای موقت فقط حداکثر سه تلاش در پنجره ۲۴ساعته، با backoff ۶۰ و ۳۰۰ ثانیه، retry می‌شود.
- اگر توکن برنامک تنظیم نباشد، پیام بدون مصرف attempt در `pending` می‌ماند و بعد از آماده‌شدن درگاه قابل ارسال است.
- قبل از retry، consent، verified بودن پیام فعال‌سازی و ثابت‌بودن دانشگاه عضو دوباره بررسی می‌شود.
- وضعیت‌های قدیمی `accepted/failed/sending` به‌صورت idempotent و محافظه‌کارانه migrate می‌شوند.
- Health Center شمارنده‌های aggregate خوش‌آمد/فعال‌سازی و stale sending را بدون PII نمایش می‌دهد.
- Schema به `2026.10.05.6` ارتقا یافته است.


## 1.3.1-rc14 — Phase B / B2: Lease و بازیابی امن `sending`

- برای هر Claim جدید صف سه زمان UTC ثبت می‌شود: `claimed_at_utc`، `lease_expires_at_utc` و `outbound_started_at_utc`.
- Claim دارای Lease ۱۸۰ ثانیه است. اگر Worker پس از Claim ولی **پیش از عبور از مرز شبکه** متوقف شود، پس از انقضای Lease رکورد به‌صورت امن به `pending` بازمی‌گردد.
- دقیقاً پیش از `wp_remote_post`، `outbound_started_at_utc` ثبت می‌شود. اگر Worker پس از این نقطه متوقف شود، رکورد پس از انقضای Lease به `uncertain` منتقل می‌شود و **هرگز خودکار دوباره ارسال نمی‌شود**.
- اگر Worker دیگری Claim را بازیابی کرده باشد، Worker قدیمی فقط در صورت موفقیت `mark_outbound_started()` اجازه عبور به شبکه دارد؛ بنابراین Resume شدن Process قدیمی پس از Recovery باعث ارسال تکراری نمی‌شود.
- رکوردهای `sending` قدیمیِ پیش از B2 که Lease ندارند در Migration به‌صورت محافظه‌کارانه «outbound-started» فرض می‌شوند تا در صورت انقضا فقط به `uncertain` بروند و هرگز خودکار resend نشوند.
- Health Center تعداد `Lease فعال`، `Lease منقضی` و `sending بدون Lease` را نشان می‌دهد و Recovery یک claim رهاشده را بدون PII در Incident log ثبت می‌کند.
- `attempts` در هر Claim به‌صورت افزایشی ثبت می‌شود؛ B2 همچنان هیچ `retryable_failed` یا `uncertain` را به‌صورت خودکار retry نمی‌کند.
- B2 عمداً Welcome/Activation recovery، Program terminal orchestration، Capacity/Waitlist و Retention را تغییر نمی‌دهد؛ آن‌ها B3 تا B6 هستند.
- Schema به `2026.10.05.5` ارتقا یافته است.

## 1.3.1-rc13 — Phase B / B1: مدل صریح وضعیت تحویل پیام

- یک قرارداد مرکزی `QYS_Delivery_State` اضافه شد تا نتیجه هر تلاش ارسال خصوصی به یکی از وضعیت‌های `sent`، `retryable_failed`، `definitive_failed` یا `uncertain` تبدیل شود؛ وضعیت‌های `pending`، `sending` و `cancelled` نیز در همان واژگان مرکزی تعریف شده‌اند.
- خطای شبکه دیگر به‌صورت «ارسال‌نشده قطعی» ذخیره نمی‌شود؛ چون ممکن است درخواست از مرز شبکه عبور کرده باشد، با وضعیت `uncertain` ثبت می‌شود تا در مراحل بعدی بدون بررسی کورکورانه retry نشود.
- خطای نرخ ارسال (`429 / rate_limited`) به `retryable_failed` و خطاهای قطعی مانند شناسه/مجوز نامعتبر به `definitive_failed` طبقه‌بندی می‌شوند. پاسخ‌های 5xx، timeout و پاسخ موفق HTTP با بدنه غیرقابل‌اعتماد به‌صورت محافظه‌کارانه `uncertain` هستند.
- ستون `queue.status` از 16 به 24 نویسه افزایش یافت و migration سازگار، رکوردهای قدیمی `failed` را بدون تغییر زمان/متن خطا به یکی از وضعیت‌های جدید تبدیل می‌کند؛ خطاهای قدیمی ناشناخته به‌صورت fail-safe در `uncertain` قرار می‌گیرند.
- شمارنده قدیمی `failed` برای سازگاری با گزارش‌های قبلی حفظ شده، اما اکنون جمع `retryable_failed + definitive_failed + uncertain + legacy_failed` است. Health Center و Readiness شمارنده‌های تفکیکی جدید را نیز گزارش می‌کنند.
- گزارش کارزار و گزارش پیگیری Excel وضعیت «نامشخص» را از خطای قطعی جدا می‌کنند تا مدیر برای جلوگیری از پیام تکراری، رکورد `uncertain` را فوراً از روش دیگری دوباره ارسال نکند.
- در بازه کوتاه ارتقا، Worker تا پایان migration Schema متوقف می‌شود تا مقدار 17 نویسه‌ای `definitive_failed` در Schema قدیمی `varchar(16)` ناقص ذخیره نشود.
- این مرحله عمداً **retry، lease/recovery رکوردهای `sending` و اصلاح Welcome/Activation** را انجام نمی‌دهد؛ آن‌ها B2 و B3 هستند.
- Schema به `2026.10.05.4` ارتقا یافته است.

## 1.3.1-rc7 — هسته عمومی «برنامه‌ها و ثبت‌نام»

- هسته عمومی Program اضافه شد تا کلاس، دوره، کارگاه، اردو، مسابقه یا جلسه به‌صورت یک موجودیت مستقل مدیریت شود؛ فرم و حضور و غیاب دیگر خودِ «برنامه» نیستند.
- مسیر داده به‌صورت صریح تفکیک شده است: `Application → Enrollment → Attendance`؛ پیش‌ثبت‌نام به‌تنهایی سابقه فعالیت محسوب نمی‌شود.
- Schema فرم نسخه‌دار اضافه شده است؛ انتشار فرم قبلی را immutable نگه می‌دارد و نسخه پیش‌نویس بعدی از نسخه منتشرشده کپی می‌شود.
- وضعیت‌های مستقل Program/Application/Enrollment و transitionهای مجاز در Service مرکزی تعریف شده‌اند.
- ظرفیت، لیست انتظار، دامنه یک یا چند دانشگاه، گروه‌های مخاطب، بازه ثبت‌نام و اتصال اختیاری حضور و غیاب از ابتدا در مدل داده لحاظ شده‌اند.
- حضور واقعی می‌تواند Enrollment را به `participating` تبدیل کند؛ برای برنامه‌های بدون ثبت‌نام، اولین حضور واقعی سابقه شرکت را می‌سازد.
- در برنامه‌های دارای ثبت‌نام، حضور فقط برای عضو دارای Enrollment معتبر مجاز است.
- پایان Program، شرکت‌کنندگان واقعی را `completed` و ثبت‌نام‌شده‌های بدون حضور را `no_show` می‌کند.
- برنامه‌های حضور ثابت فعلی با `core_program_id` به هسته عمومی متصل می‌شوند؛ backfill امن برای داده‌های موجود وجود دارد.
- دو مجوز مستقل `programs` و `programs_export` به مدل نمایندگی دانشگاه افزوده شده‌اند.
- Readiness مرکزی وضعیت Schema و شمارنده‌های Aggregate برنامه/درخواست/ثبت‌نام/اتصال حضور را بدون PII گزارش می‌کند.
- این RC عمداً رابط نهایی «برنامه‌ها» را نمایش نمی‌دهد؛ هدف آن تثبیت Schema و منطق دامنه پیش از ساخت UI/فرم ثبت‌نام در RC بعدی است.


## RC3 — ارتقای تجربه کاربری پنل دانشگاه

- صفحه «اعضا» در دسکتاپ و موبایل بازآرایی شد: جدول تمام‌عرض، چهار شاخص کاربردی، جست‌وجوی فشرده‌تر و نمایش واضح‌تر وضعیت پرونده‌ها.
- صفحه «ارسال پیام» در پنل دانشگاه به جریان سه‌مرحله‌ای «مخاطبان ← متن پیام ← زمان ارسال» تبدیل شد.
- ارسال فوری دیگر با کنترل‌های تاریخ و ساعت مزاحمت بصری ایجاد نمی‌کند؛ با انتخاب زمان‌بندی، کنترل‌ها فعال و برجسته می‌شوند.
- شمارنده نویسه پیام، توضیح روشن درباره پیش‌نویس و تأیید قبل از ارسال، اکشن سریع ارسال از Excel/CSV و آرشیو خواناتر اضافه شد.
- جدول‌های پیام و اعضا در دسکتاپ تمام‌عرض و در موبایل به کارت/نمای فشرده سازگار تبدیل می‌شوند.
- هیچ منطق دسترسی، دامنه دانشگاه، Queue، ارسال ایتا یا Schema در RC3 تغییر نکرده است.

## Phase 4A — Production Reliability & Health Center

- Heartbeat واقعی برای `qys_send_batch` با ثبت شروع، پایان، مدت، منبع اجرا و نتیجه عملیاتی بدون PII.
- Health Center فقط برای مدیر کل در `wp-admin` با وضعیت Worker، Cron، Queue، Batch Lock، Schema، Token و Birthday readiness.
- نمایش سن قدیمی‌ترین Pending قابل ارسال و Sending، شمارنده‌های Pending/Sending/Failed/Sent و تشخیص lock یا sending رهاشده.
- Incident log فقط برای تغییر وضعیت‌های عملیاتی و کدهای تشخیصی؛ بدون نام، موبایل، شناسه ایتا، متن پیام، توکن یا خطای خام شبکه.
- Ability خواندنی `qys-messenger/health` برای Runtime QA و پایش امن.
- گزارش پایدار «افراد ارسال‌نشده / نیازمند پیگیری» برای کارزارهای Excel اضافه شده است؛ ردیف‌های خارج‌شده از صف و شکست‌های واقعی ارسال با دلیل مشخص نمایش داده می‌شوند.
- برای کاربران دارای مجوز اطلاعات هویتی حساس، خروجی CSV شامل نام، کد ملی و موبایل جهت پیگیری از روش جایگزین در دسترس است؛ بدون این مجوز شناسه‌ها ماسک می‌شوند.
- فقط فیلدهای هویتی/تماس ضروری برای پیگیری ذخیره می‌شوند و سایر ستون‌های خام فایل Excel همچنان ذخیره نمی‌شوند.
- Schema به `2026.10.03.1` ارتقا یافته و جدول `excel_followup` با migration خودکار افزوده می‌شود.
- این بسته RC برای تست زنده Phase 4A و گزارش پیگیری Excel است؛ انتشار پایدار 1.3.1 بعد از عبور پایش 24 ساعته Cron و Runtime QA انجام می‌شود.

## نسخه پایدار 1.3.0

- این نسخه پایدار از نظر کد اجرایی معادل `1.3.0-rc.4` است؛ پس از عبور RC4 از Readiness زنده، Cron، ارسال واقعی کنترل‌شده، ورود/خروج واقعی حضور و رفع Release Blockerهای Routing و UX، فقط metadata نسخه و مستندات انتشار تغییر کرده‌اند.
- هیچ Feature، Endpoint، Permission، Schema یا مسیر مهاجرت جدیدی در تبدیل RC4 به نسخه پایدار اضافه نشده است.
- مرزهای `TenantContext`، `PermissionService`، `QueueService`، `MessageGateway` و احراز `initData` بدون تغییر باقی مانده‌اند.
- بسته انتشار فقط فایل‌های runtime را شامل می‌شود و `tests/` و `docs/` داخل ZIP نیستند.
- از این نسخه به بعد تغییرات باید با versioning عادی و migration صریح/کنترل‌شده انجام شوند؛ مسیرهای آزمایشی pre-release نباید به production برگردند.

## Final Attendance UX Polish در rc.4

- هدر جزئیات جلسه، کارت جلسه و تب‌های داخلی فشرده‌تر شده‌اند تا اطلاعات عملیاتی بالاتر دیده شوند.
- در صفحه «افراد»، شمارنده «واجد شرایط» با KPIهای عملیاتی «حاضر / خروج ثبت‌شده / بدون خروج» جایگزین شده است.
- ردیف هر عضو فشرده‌تر و خواناتر شده و فرم اصلاح مستند ارتفاع کمتری دارد.
- عملیات «لغو حضور با حفظ سابقه» ظاهر هشداردهنده دارد و به تأیید دومرحله‌ای نیاز دارد.
- این نسخه Feature جدید یا تغییر Backend ندارد و فقط Release Blocker تجربه کاربری حضور را رفع می‌کند.


## اولین Release Candidate — Feature Freeze

## رفع Release Blocker تجربه کاربری جزئیات حضور در rc.3

- نمای «افراد جلسه» از جدول سنگین و فرم فشرده داخل سلول به فهرست کارت/ردیف واکنش‌گرا تبدیل شد.
- اطلاعات هر عضو در یک ردیف روشن با نام، گروه، ورود، خروج و مدت حضور دیده می‌شود؛ اصلاح مستند فقط هنگام نیاز باز می‌شود.
- فرم اصلاح هنگام بازشدن تمام عرض همان کارت را می‌گیرد و دیگر در یک ستون باریک فشرده نمی‌شود.
- وضعیت حاضر/بدون خروج/ثبت‌نشده با نشانه‌های بصری روشن‌تر شده است.
- Header جزئیات جلسه، کارت جلسه و تب‌ها فشرده‌تر و هم‌تراز شدند تا فضای سفید غیرضروری دسکتاپ کاهش یابد.
- این نسخه هیچ Feature جدید، Endpoint جدید یا تغییر امنیتی ندارد؛ فقط UX/UI صفحه جزئیات حضور اصلاح شده است.


## رفع Release Blocker در rc.2

- تداخل پارامتر `section` بین Router اصلی پنل دانشگاه و تب‌های جزئیات حضور و غیاب رفع شد.
- Router اصلی همیشه `section=attendance` را حفظ می‌کند و تب داخلی جلسه با پارامتر مستقل `att_tab` (`summary / people / codes / report`) مشخص می‌شود.
- `campus_url()` دیگر اجازه نمی‌دهد آرگومان‌های داخلی مقدار رزروشده `section` را بازنویسی کنند.
- این اصلاح فقط Routing را تغییر می‌دهد و هیچ تغییری در TenantContext، PermissionService، nonce، Queue یا Gateway ندارد.


- این بسته از نظر کد اجرایی معادل `1.3.0-alpha.17` است؛ فقط metadata نسخه و مستندات انتشار تغییر کرده‌اند.
- از این نقطه Feature Freeze برقرار است: تا انتشار نهایی فقط رفع باگ، امنیت، سازگاری و اشکال‌های Release Blocking مجاز است.
- Phaseهای امنیت Runtime، Cron/Background Jobs و UI/UX آلفا قبلاً بسته شده‌اند.
- بسته قابل نصب همچنان فقط runtime را شامل می‌شود و `tests/` و `docs/` داخل ZIP انتشار قرار ندارند.

## تغییرات 1.3.0-alpha.17 — Phase 3H یکدست‌سازی و pre-RC

- Empty / Error / Offline state یکدست در پنل دانشگاه.
- جلوگیری از ارسال دوباره فرم‌های پنل با حالت «در حال پردازش».
- بازگردانی صحیح دکمه‌ها پس از Back/Forward cache مرورگر.
- اسکرول خودکار آیتم فعال ناوبری موبایل به محدوده دید.
- Empty state استاندارد برای اعضا، پیام‌ها و دسترسی‌های خالی.
- اعلان زیرساختی آفلاین/آنلاین بدون تغییر در امنیت Tenant و Permission.
- بسته runtime همچنان بدون tests/ و docs/ منتشر می‌شود.
- این نسخه Alpha نهایی UI است؛ پس از تست زنده، نامزد بعدی Release Candidate خواهد بود.


## تغییرات 1.3.0-alpha.16 — Phase 3G پنل دانشگاه

- خانه پنل دانشگاه از «دسترسی سریع» تکراری به داشبورد عملیاتی تبدیل شد: کل اعضا، تأییدشده، در انتظار و آماده پیام.
- بخش «کارهای امروز» فقط موارد نیازمند اقدام را نشان می‌دهد؛ تأیید پرونده، پیام‌رسانی و حضور از همان‌جا قابل دسترسی‌اند.
- صفحه اعضا دارای KPI، جست‌وجوی فشرده، وضعیت‌های واضح و جدول واکنش‌گراست؛ در موبایل هر عضو به کارت خوانا تبدیل می‌شود.
- اطلاعات موبایل/کد ملی همچنان بر اساس مجوز `sensitive_identity` ماسک می‌شوند و جست‌وجوی حساس بدون همان مجوز غیرفعال می‌ماند.
- صفحه پیام‌ها تعداد ابزارهای فعال، گیرندگان آماده و پرونده‌های منتظر تأیید را به‌صورت خلاصه نشان می‌دهد و ابزارها کارت‌بندی شدند.
- صفحه «بیشتر» وضعیت فعال نمایندگی، تعداد مجوزها و خروج امن را فشرده و واضح نمایش می‌دهد.
- لایه نمایشی جدید `QYS_Portal_Experience` اضافه شد و هیچ تصمیم Storage/Tenant/Permission داخل آن نیست.
- `university_counts()` در Repository به‌صورت backward-compatible آمار pending/eligible/messaging_off را اضافه می‌کند.
- مرزهای TenantContext، PermissionService، nonce، audit، Queue و Gateway بدون تغییر امنیتی باقی مانده‌اند.

## تغییرات 1.3.0-alpha.15 — Phase 3F خدمات و بیشتر

- بخش «خدمات» وضعیت پیام خصوصی، حضور و تبریک تولد را با شمارنده خدمات فعال/نیازمند توجه نمایش می‌دهد.
- بخش «بیشتر» خلاصه حساب، دانشگاه، نوع عضویت، رضایت پیام، وضعیت اتصال و نسخه برنامک را یکجا نشان می‌دهد.
- نشان توجه روی تب‌های خدمات/بیشتر فقط از payload امن status عضو ساخته می‌شود.
- هیچ endpoint، permission یا مسیر جدیدی به سطح امنیتی برنامه اضافه نشد.

## تغییرات 1.3.0-alpha.14 — Phase 3E داشبورد خانه

- صفحه خانه از تکرار منوی پایین خارج شد و به داشبورد خلاصه تبدیل شد.
- کارت‌های بزرگ «پرونده/حضور/خدمات» از Home حذف شدند؛ Bottom Navigation مرجع اصلی جابه‌جایی است.
- وضعیت پرونده فشرده شد و نوار پیشرفت پس از کامل‌شدن پرونده دیگر فضای صفحه را اشغال نمی‌کند.
- کارت اصلی Home وضعیت حضور را نشان می‌دهد: جلسه قابل اقدام، جلسه بعدی، آخرین سابقه یا نبود جلسه.
- برای کاربر ناقص/تأییدنشد‌ه، اقدام اصلی Home به‌صورت زمینه‌ای به «تکمیل پرونده» یا «مشاهده وضعیت پرونده» تغییر می‌کند.
- وضعیت دریافت پیام و تبریک تولد به دو نشانگر کوچک تبدیل شد.
- جلسات و سابقه حضور پس از احراز پرونده در پس‌زمینه بارگذاری می‌شوند تا داشبورد Home اطلاعات زنده داشته باشد؛ REST و مجوزهای سمت سرور تغییر نکرده‌اند.
- ارتفاع Hero، فاصله‌های عمودی و وزن بصری Bottom Navigation کاهش یافت تا صفحه روی موبایل کوتاه‌تر و کاربردی‌تر شود.

# QuranYar Messenger 1.3.0-alpha.11

## تغییرات 1.3.0-alpha.11 — Phase 3C برنامک اعضا

- برنامک اعضا به App Shell پنج‌بخشی تبدیل شد: «خانه، پرونده، حضور، خدمات، بیشتر».
- Bottom Navigation چسبان با رعایت safe-area موبایل اضافه شد و مسیرها به پنج مقصد ثابت محدود شدند.
- صفحه خانه فقط خلاصه وضعیت و سه اقدام اصلی را نشان می‌دهد؛ فرم پرونده و مدیریت عضویت به تب «پرونده» منتقل شدند.
- حضور و غیاب دیگر به‌عنوان کارت/بخش تزریقی در صفحه اصلی ظاهر نمی‌شود و داخل مقصد مستقل «حضور» mount می‌شود.
- بارگذاری جلسات حضور lazy شد و فقط هنگام بازشدن بخش حضور انجام می‌شود.
- کاربر فاقد پرونده کامل یا تأیید هویت، در بخش حضور پیام راهنما می‌بیند؛ کنترل امنیتی REST سمت سرور بدون تغییر باقی مانده است.
- بخش «خدمات» فقط قابلیت‌های واقعی موجود را نمایش می‌دهد: پیام خصوصی، حضور و غیاب و تبریک تولد؛ وضعیت هرکدام از پاسخ امن status به‌روزرسانی می‌شود.
- بخش «بیشتر» دانشگاه، نوع عضویت، وضعیت دریافت پیام و لینک حریم خصوصی را یکجا نمایش می‌دهد.
- در WebView رسمی ایتا، عناصر عمومی قالب وردپرس برای تجربه app-like پنهان می‌شوند؛ در مرورگر عادی همان پیام محدودیت امنیتی قبلی نمایش داده می‌شود.
- منطق ذخیره پرونده، Tenant، احراز initData، Queue، Gateway و APIهای حضور تغییری نکرده است.

# QuranYar Messenger 1.3.0-alpha.10

## تغییرات 1.3.0-alpha.10 — Phase 3B حضور و غیاب

- صفحه حضور دانشگاه task-first شد: جلسه‌های در حال برگزاری و جلسه بعدی در بالای صفحه دیده می‌شوند.
- فهرست جلسه‌ها به کارت‌های مستقل با وضعیت، زمان، ورود و خروج تبدیل شد و بایگانی از جلسات جاری جداست.
- جزئیات هر جلسه به چهار بخش «خلاصه، افراد، QR و کد، گزارش» شکسته شد تا جدول‌ها و ابزارها هم‌زمان روی یک صفحه شلوغ نباشند.
- قانون «فقط یک کد عددی» صریح شد: هر جلسه دقیقاً یک کد شش‌رقمی مشترک برای ورود و خروج دارد و نوع رویداد داخل برنامک انتخاب می‌شود.
- QRهای ورود و خروج همچنان ثابت و چاپی هستند؛ تعویض کد فقط از بخش «QR و کد» انجام می‌شود.
- در برنامک عضو، جلسه‌ای که همین حالا امکان ثبت ورود یا خروج دارد به ابتدای فهرست می‌آید و برجسته می‌شود.
- خروج زودهنگام همچنان با زمان واقعی سرور ثبت می‌شود و مدت حضور از اختلاف زمان ورود و خروج محاسبه می‌شود.
- مرزهای TenantContext، PermissionService، nonce، CSV masking و audit log بدون تغییر امنیتی حفظ شده‌اند.

# QuranYar Messenger 1.3.0-alpha.9

## تغییرات 1.3.0-alpha.9 — Phase 3A UI/UX

- ناوبری پنل دانشگاه به پنج مقصد اصلی «خانه، اعضا، پیام‌ها، حضور، بیشتر» محدود شد.
- در موبایل Bottom Navigation چسبان با رعایت safe-area اضافه شد.
- ابزارهای ارسال هدفمند، فایل، تولد و خوش‌آمدگویی زیر هاب «پیام‌ها» دسته‌بندی شدند.
- صفحه «بیشتر» برای حساب نماینده و دسترسی‌های فعال اضافه شد.
- صفحه خانه به دسترسی‌های سریع اصلی تبدیل شد و از شلوغی ابزارهای فرعی کاسته شد.
- امنیت TenantContext و PermissionService بدون تغییر حفظ شده است.


## اصلاح بحرانی Fresh Install / Admin Menu
- خطای Fatal پس از اولین راه‌اندازی مدیر کل برطرف شد.
- علت: دو فراخوانی باقی‌مانده به API حذف‌شده `QYS_Multitenant::context()` در `AdminModule`.
- مرجع قطعی context اکنون فقط `QYS_Tenant_Context` است.
- تست `deprecated-api-contract` و `admin-menu-runtime-smoke` برای جلوگیری از بازگشت این خطا اضافه شد.

# قرآن‌یار — 1.3.0-alpha.7

## تغییر مهم alpha.7 — نصب صفر تا صد روی وردپرس تازه

این نسخه مسیر نصب اولیه را بازطراحی می‌کند:

- فعال‌سازی افزونه سبک است و دیگر `dbDelta` و ساخت همه جدول‌ها را داخل activation request اجرا نمی‌کند.
- ساخت Schema در اولین درخواست احراز‌شده مدیر و با `try/catch` انجام می‌شود؛ شکست نصب به‌جای از کار انداختن wp-admin به‌صورت کد تشخیصی ثبت می‌شود.
- در نصب تازه، همان مدیر وردپرس که افزونه را فعال کرده است به‌عنوان مدیر کل قرآن‌یار تثبیت می‌شود و نمایندگی ساوه با همه مجوزهای فعلی برای او ایجاد می‌شود.
- صفحات `/qys/`، `/university-login/` و `/miniapp/` فقط بعد از آماده‌شدن کامل Schema ساخته می‌شوند.
- Schema همان baseline `2026.09.30.2` است و هیچ migration سازگاری با نسخه‌های آزمایشی قبلی اضافه نشده است.


این بسته هنوز نسخه انتشار رسمی نیست. در بازبینی پیش از اجرای **Phase 2B-B — Runtime Negative Tests on Staging** یک ابهام امنیتی مهم در حساب دو‌نقشی «مدیر کل + نماینده دانشگاه» پیدا شد؛ alpha.6 آن را قبل از نصب staging می‌بندد. این نسخه برای سایت اصلی نیست و فقط مبنای تست runtime خواهد بود.

## مرزهای معماری قطعی

- `QYS_Tenant_Context`: تنها مرجع context مدیر کل یا دانشگاه جاری.
- `QYS_Permission_Service`: تنها مرجع scope مجوز و کنترل دانشگاهی.
- `QYS_Security_Service`: مرجع احراز درخواست‌های Eitaa WebApp، تشخیص مدیر کل معتبر، rate limit و هدرهای حریم خصوصی سطح اپ.
- `QYS_Feature_Registry`: رجیستری واحد قابلیت‌های پنل دانشگاه.
- `QYS_Queue_Service`: تنها مسیر کاربردی ارسال پیام.
- `QYS_Message_Gateway`: تنها محل درخواست HTTP ارسال خصوصی به ایتا.
- `QYS_Member_Repository`، `QYS_Campaign_Repository` و `QYS_University_Repository`: مرزهای Storage مشترک.

## سخت‌سازی انجام‌شده در Phase 2A

1. REST برنامک دیگر `permission_callback` باز ندارد؛ امضای `initData` ایتا پیش از اجرای callback بررسی می‌شود.
2. parser احراز هویت، کلید تکراری، bracket key، داده بیش‌ازحد بزرگ، امضای دست‌کاری‌شده، زمان قدیمی و بیش از ۱۲۰ ثانیه آینده را رد می‌کند.
3. دسترسی مدیر کل فقط با **هم‌زمانی شناسه مالک قرآن‌یار و capability `manage_options`** معتبر است. افت capability باعث حفظ قدرت مدیر کل نمی‌شود.
4. `TenantContext` از `$_REQUEST` استفاده نمی‌کند؛ منبع scope فقط POST یا GET همان درخواست است.
5. جست‌وجوی کد ملی/موبایل بدون مجوز `sensitive_identity` غیرفعال است؛ ماسک‌کردن خروجی به‌تنهایی مبنای مجوز نیست.
6. `national_id` در baseline جدید UNIQUE است تا ثبت هم‌زمان دو پرونده با یک کد ملی در سطح DB نیز رد شود.
7. تأیید نماینده برای یک کاربر با `SELECT ... FOR UPDATE` سریالی شده تا دو درخواست هم‌زمان نتوانند دو دانشگاه را تأیید کنند.
8. فایل CSV/XLSX محدودیت حجم، تعداد ردیف/ستون، طول header/cell، امضای ZIP، NUL در CSV و XML `DOCTYPE/ENTITY` دارد؛ XML با `LIBXML_NONET` خوانده می‌شود.
9. پاسخ HTTP درگاه‌ها حداکثر ۶۴KB است و درخواست خصوصی پیام `reject_unsafe_urls` دارد.
10. ورود پنل دانشگاه هم‌زمان با bucket کاربر+IP و bucket سراسری IP محدود می‌شود.
11. حضور و غیاب برای کد عددی، محدودیت تلاش هم در سطح جلسه+عضو و هم در سطح عضو دارد؛ dereference جلسه ناموجود نیز اصلاح شد.
12. صفحات اپ/پنل `no-cache`، `noindex`، `no-referrer` و `nosniff` دریافت می‌کنند.

## Schema baseline

Schema baseline این alpha به `2026.09.30.2` تغییر کرده است، چون `national_id` اکنون UNIQUE است. هیچ migration سازگاری با alphaهای منتشرنشده اضافه نشده و هیچ پاک‌سازی خودکار داده وجود ندارد. برای آزمون این baseline از نصب تازه/staging تمیز استفاده شود.

## آزمون‌های این بسته

- PHP lint: ۳۳ فایل production/test.
- JavaScript syntax check: ۸ فایل.
- `tests/bootstrap-smoke.php`
- `tests/architecture-contract.php`
- `tests/storage-boundary-contract.php`
- `tests/security-contract.php`
- `tests/eitaa-initdata-test.php`
- `tests/tenant-context-negative.php`
- `tests/dispatch-authorization-negative.php`
- `tests/spreadsheet-negative.php`
- `tests/authorization-surface-contract.php`

همه آزمون‌های بالا در زمان ساخت artifact موفق بوده‌اند. این نتیجه **جایگزین تست یکپارچه/نفوذ روی staging نیست**.


## Phase 2B-A — آزمون منفی افزوده‌شده

- `tenant-context-negative.php`: دست‌کاری `qys_portal_university`، scope نماینده، مجوز حساس و درخواست دانشگاه دیگر.
- `dispatch-authorization-negative.php`: انتقال عضو به دانشگاه دیگر، revoke مجوز نماینده، غیرفعال‌شدن دانشگاه، downgrade مدیر کل و metadata مفقود.
- `spreadsheet-negative.php`: پسوند جعلی، NUL در CSV، حجم بیش از حد، XLSX جعلی و متغیر کد ملی در متن.
- `authorization-surface-contract.php`: nonce + permission + tenant binding روی سطوح state-changing.
- یک fail-open واقعی در worker بسته شد: queue بدون targeted tenant metadata دیگر ارسال نمی‌شود و fail-closed است.

## اصلاح امنیتی alpha.6 — تفکیک نقش دوگانه مدیر کل

مدیر کل می‌تواند هم‌زمان نماینده ساوه نیز باشد، اما درخواست‌هایی که از پنل دانشگاه می‌آیند نباید با حذف یا جعل فیلدهای scope به اختیار سراسری برگردند. در alpha.6:

- `TenantContext` در صورت وجود intent دانشگاهی نامعتبر **fail-closed** می‌شود.
- جست‌وجوی AJAX مخاطب برای wp-admin و `/qys/` nonceهای جداگانه دارد.
- خروجی CSV حضور و غیاب برای محیط global و campus nonceهای جداگانه دارد و پس از reset context دوباره scope را بررسی می‌کند.
- تست regression مخصوص «global owner + forged portal campus» اضافه شد.

## گام بعدی

**Phase 2B-B — Runtime Negative Tests on Staging**: نصب alpha.6 روی staging واقعی، ساخت حساب‌های نماینده ساوه/اراک با ترکیب مجوزهای متفاوت و تلاش عمدی برای مشاهده/تغییر/ارسال بین‌دانشگاهی، همراه با آزمون REST، AJAX، admin-post، فایل، revoke حین صف و replay. تا پایان این مرحله alpha.6 نباید روی سایت اصلی منتشر شود.


## 1.3.0-alpha.13 — Phase 3D
- ناوبری BackButton ایتا برای نمای درونی برنامک و مراحل فرم.
- وضعیت‌های صریح بارگذاری، خطا، قطع اینترنت و تلاش مجدد.
- بهبود دسترسی‌پذیری: skip link، focus management، forced-colors، reduced-motion و touch target.
- یکدست‌سازی Bottom Navigation برنامک عضو و پنل دانشگاه.
- حالت‌های loading/error/empty حضور و غیاب با retry امن.

## 1.3.1-rc4 — پیام پس از فعال‌شدن حساب

- پس از تأیید هویت و تغییر وضعیت عضو از «در انتظار» به «تأییدشده»، یک پیام خصوصی فعال‌سازی برای او ارسال می‌شود.
- متن پیام فعال‌سازی از پنل دانشگاه در بخش «پیام‌های عضویت» قابل ویرایش است.
- متن خوش‌آمدگویی اولیه و متن فعال‌سازی مستقل‌اند و متغیرهای `{نام}`، `{نام_خانوادگی}` و `{دانشگاه}` را پشتیبانی می‌کنند.
- پیام فعال‌سازی برای هر عضو فقط یک بار تلاش می‌شود و با تأیید تکراری دوباره ارسال نمی‌شود.
- اگر دریافت پیام عضو غیرفعال باشد، تأیید حساب انجام می‌شود اما پیام فعال‌سازی ارسال نمی‌شود.
- ارسال پیام پس از COMMIT تأیید هویت انجام می‌شود؛ خطای درگاه باعث برگشت تأیید هویت نمی‌شود.


## برنامه حضور ثابت (RC5)

برای جلسه‌های تکرارشونده‌ای که تاریخ و ساعت ثابتی ندارند، در بخش «حضور» می‌توان «برنامه ثابت» ساخت. این مدل از جلسه تاریخ‌دار جداست:

- برای هر برنامه فقط یک QR و یک کد شش‌رقمی ساخته می‌شود و همان پوستر در همه نوبت‌ها استفاده می‌شود.
- مدیر لازم نیست تاریخ‌های آینده را از قبل وارد کند.
- اولین ثبت «ورود» در هر نوبت، یک جلسه/نوبت واقعی را با تاریخ و ساعت سرور می‌سازد.
- ورودهای بعدی همان بازه به همان نوبت متصل می‌شوند؛ خروج نیز به رکورد ورود عضو متصل می‌شود. اگر خروج قبلی عضو باز مانده باشد، ورود به نوبت تازه تا ثبت خروج قبلی پذیرفته نمی‌شود.
- یک نوبت شناور حداکثر ۱۸ ساعت «جاری» در نظر گرفته می‌شود؛ برای خروج فراموش‌شده تا ۳۶ ساعت امکان ثبت خروج باقی می‌ماند.
- مدیر می‌تواند «نوبت جاری» را دستی پایان دهد تا حتی در همان روز، ثبت ورود بعدی یک نوبت تازه بسازد؛ برنامه اصلی همچنان فعال می‌ماند.
- مدیر می‌تواند برنامه را متوقف/فعال یا در صورت انتشار ناخواسته، QR و کد ثابت آن را تعویض کند.
- جلسه‌های تاریخ‌دار قبلی و QRهای آن‌ها بدون تغییر باقی می‌مانند.

در پرونده شخصی عضو، تعداد جلسات، تعداد حضورهای تکمیل‌شده، جمع مدت حضور تکمیل‌شده و جزئیات ورود/خروج نمایش داده می‌شود. رکوردهایی که خروج ندارند جداگانه مشخص می‌شوند و تا زمان تکمیل خروج در جمع ساعات محاسبه نمی‌شوند.

نکته امنیتی: QR ثابت برای کاهش هزینه و دشواری چاپ طراحی شده و مانند هر QR چاپی، تصویر آن قابل اشتراک است؛ بنابراین به‌تنهایی اثبات فیزیکی حضور نیست. در صورت نشت تصویر، مدیر باید QR/کد را تعویض کند.

## 1.3.1-rc6 — اصلاح منطق مدیریت کل و بازگردانی دریافت پیام

- منوی داخلی صفحه مدیریت کل به سه بخش واقعی «نمای کلی»، «اعضا و پرونده‌ها» و «تنظیمات و اتصال» تبدیل شد.
- وابستگی منو به تشخیص متن تیترها در JavaScript حذف شد؛ بنابراین اعضا دیگر به اشتباه در تنظیمات نمایش داده نمی‌شوند و تب خالی ایجاد نمی‌شود.
- منوی اصلی وردپرس با عنوان کوتاه «قرآن‌یار» و زیرمنوی «نمای کلی» مرتب شد.
- در فهرست اعضای مدیر کل، وضعیت «هویت» و «دریافت پیام» جداست و فیلتر وضعیت/دانشگاه/جست‌وجو اضافه شد.
- برای عضوی که دریافت پیام او از مدیریت کل متوقف شده، دکمه «فعال‌سازی مجدد» اضافه شد. پیام‌های لغوشده قبلی پس از فعال‌سازی مجدد بازگردانی نمی‌شوند.
- در پنل دانشگاه نیز وضعیت فعال/متوقف بودن دریافت پیام کنار وضعیت تأیید هویت دیده می‌شود.


## RC8 — رابط برنامه‌ها و پیش‌ثبت‌نام

- منوی «برنامه‌ها» در پنل دانشگاه و مدیریت کل.
- ایجاد برنامه عمومی (کلاس/دوره/کارگاه/مسابقه/اردو/سایر).
- فرم نسخه‌دار پیش‌ثبت‌نام با فیلدهای کنترل‌شده.
- پیش‌ثبت‌نام از داخل برنامک ایتا، پیگیری وضعیت و مشاهده سوابق.
- پذیرش، لیست انتظار، رد و ثبت‌نام قطعی.
- خروجی CSV با رعایت مجوز اطلاعات حساس.
- اتصال اختیاری برنامه قطعی به QR ثابت حضور و غیاب.
- پیش‌ثبت‌نام به‌تنهایی سابقه فعالیت محسوب نمی‌شود؛ حضور واقعی مرز سابقه است.

## تغییر RC9 — برنامه‌های جاری در خانه

در صفحه اصلی برنامک اعضا، حداکثر سه برنامه جاری و مرتبط با همان عضو نمایش داده می‌شود. برنامه‌های قابل نمایش شامل ثبت‌نام باز و همچنین برنامه‌های قطعی/درحال‌اجرا یا ثبت‌نام‌بسته‌ای است که عضو قبلاً با آن‌ها ارتباط ثبت‌نامی دارد. ترتیب بر اساس جدیدترین برنامه است و دکمه «مشاهده همه» کاربر را به بخش «برنامه‌ها و ثبت‌نام» می‌برد. این تغییر Schema جدیدی ایجاد نمی‌کند.


## 1.3.1-rc10 — ارتقای تجربه مدیریت برنامه و ویرایش سؤال‌ها

- صفحه مدیریت هر برنامه به چهار بخش روشن «وضعیت، فرم ثبت‌نام، حضور و غیاب، متقاضیان» تقسیم شد و ناوبری سریع دارد.
- فرم‌ساز از حالت همیشه‌باز خارج شد؛ افزودن سؤال فقط هنگام نیاز باز می‌شود.
- سؤال‌های فرم در کارت‌های مستقل با شماره، نوع، اجباری/اختیاری، ترتیب و راهنما نمایش داده می‌شوند.
- امکان ویرایش عنوان، نوع، اجباری بودن، ترتیب، راهنما و گزینه‌های هر سؤال اضافه شد.
- نسخه منتشرشده فرم هرگز مستقیم تغییر نمی‌کند؛ با اولین ویرایش یک Draft کپی می‌شود و پاسخ‌های قبلی روی نسخه قبلی سالم می‌مانند.
- انتشار تغییرات فرم صریح و جدا از ذخیره ویرایش سؤال است.
- نمایش عبارت «پیش ثبت‌نام» در رابط کاربری اصلاح شد و متن دکمه ثبت نیز از خطای «ثبت پیش‌نام» پاک شد.
- Schema پایگاه داده تغییر نکرده است.


## 1.3.1-rc11 — الزام تکمیل پرونده پیش از ثبت‌نام برنامه

- استفاده از «برنامه‌ها و ثبت‌نام» فقط برای عضو دارای پرونده کامل و هویت تأییدشده مجاز است.
- این محدودیت هم در REST و هم در لایه سرویس ثبت درخواست اعمال می‌شود و صرفاً محدودیت رابط کاربری نیست.
- عضو با پرونده ناقص به صفحه تکمیل پرونده هدایت می‌شود و عضو در انتظار تأیید، وضعیت پرونده را می‌بیند.
- صفحه اصلی همچنان برنامه‌ها را فقط برای اعضای واجد شرایط نمایش می‌دهد.
- Schema پایگاه داده تغییر نکرده است.


## 1.3.1-rc12 — Phase A: مرز دانشگاه و تمامیت داده

- تغییر دانشگاه عضو دیگر بلافاصله دانشگاه عملیاتی او را عوض نمی‌کند؛ به‌صورت «درخواست انتقال» ثبت می‌شود و تا تأیید دانشگاه مقصد، دانشگاه فعلی پابرجا می‌ماند.
- دانشگاه مقصد با مجوز «تأیید اعضا» می‌تواند انتقال را تأیید کند؛ تأیید انتقال، دانشگاه فعال عضو را تغییر می‌دهد و هویت او را در مقصد تأیید می‌کند.
- مدیر کل نیز امکان تأیید انتقال را دارد.
- برنامه‌های چنددانشگاهی سه مرز مستقل دارند: مشاهده برنامه، مالکیت/مدیریت تعریف برنامه و دامنه متقاضیان. نماینده دانشگاه غیرمالک فقط متقاضیان دانشگاه خودش را می‌بیند و مدیریت می‌کند.
- درخواست و ثبت‌نام قطعی Program یک snapshot از دانشگاه عضو نگه می‌دارند تا انتقال بعدی باعث نشت یا تغییر دامنه داده تاریخی نشود.
- صلاحیت عضو در Submit، پذیرش، ثبت‌نام قطعی و حضور دوباره بررسی می‌شود؛ تغییر دانشگاه یا نقش نمی‌تواند درخواست قدیمی را به‌صورت ضمنی معتبر نگه دارد.
- خروجی CSV برنامه در پنل دانشگاه فقط متقاضیان همان دانشگاه را شامل می‌شود و محافظ Formula/CSV Injection روی تمام سلول‌ها اعمال شده است.
- داده‌های قدیمی Application/Enrollment در مهاجرت به دامنه دانشگاهی snapshot می‌شوند؛ موارد مشترک نامشخص با `legacy_unknown` به‌صورت fail-closed نگهداری می‌شوند.
- Schema به `2026.10.05.3` ارتقا یافته است. افزونه باید روی نسخه قبلی جایگزین شود و یک‌بار پیشخوان WordPress باز شود تا migration اجرا شود.


## 1.3.1-rc18 — Phase B / B6: Retention Policy و پاک‌سازی امن

- پاک‌سازی دوره‌ای داده‌های قدیمی با رویداد مستقل `qys_retention_cleanup` و اجرای روزانه اضافه شد.
- سیاست نگهداری صریح است: متن payload پیام‌های نهایی پس از ۹۰ روز پاک می‌شود؛ پیگیری اکسل و snapshot مستقیم آرشیو تولد پس از ۱۸۰ روز ناشناس می‌شوند؛ تاریخچه کاملاً نهایی Campaign/Queue و Auditهای قدیمی پس از ۷۳۰ روز قابل حذف هستند.
- رکوردهای `pending`، `sending`، `retryable_failed` و `uncertain` هرگز توسط Retention حذف یا به صف دیگری منتقل نمی‌شوند.
- Campaign قدیمی فقط وقتی به‌صورت کامل حذف می‌شود که هیچ رکورد باز/قابل‌بازیابی/نامشخص نداشته باشد؛ متادیتا، Queue، گزارش Import و Follow-up آن در یک تراکنش حذف می‌شوند تا گزارش نیمه‌کاره باقی نماند.
- `excel_followup` پس از ۱۸۰ روز نام، کد ملی، موبایل و member_id را حذف می‌کند اما دلیل و شماره ردیف برای آمار باقی می‌ماند.
- `birthday_archive` فقط برای نتیجه‌های نهایی `accepted/failed` ناشناس/حذف می‌شود؛ `sending/uncertain` محافظت می‌شوند.
- Health Center آخرین اجرای Retention، نتیجه، تعداد تغییرات و زمان‌بندی بعدی را بدون PII نشان می‌دهد و Retention Matrix را نیز نمایش می‌دهد.
- Incident log از قبل حداکثر ۵۰ رویداد و بدون PII بود و نیاز به cleanup جدا ندارد.
- Schema دیتابیس در RC18 تغییر نکرده و همچنان `2026.10.05.7` است.

## 1.3.1-rc19 — Phase C / C1: Migration Ledger

- زیرساخت Migration از اتکای صرف به یک `SCHEMA_VERSION` سراسری به یک Ledger مستقل و ترتیبی منتقل شد.
- تاریخچه RC12 تا RC18 فقط به‌عنوان baseline ثبت می‌شود و روی نصب موجود دوباره اجرا نمی‌شود.
- هر Migration جدید شناسه یکتا، وضعیت `pending / running / completed / failed`، شمارنده تلاش، نسخه افزونه، زمان‌ها، checkpoint و کد خطای امن دارد.
- Migrationهای تکمیل‌شده idempotent هستند و در اجراهای بعدی دوباره اجرا نمی‌شوند.
- Migration شکست‌خورده قابل retry است و checkpoint امن آن حفظ می‌شود.
- یک Lock مستقل ۵ دقیقه‌ای مانع اجرای هم‌زمان دو runner می‌شود و Lock رهاشده پس از timeout قابل بازیابی است.
- نصب تازه همچنان یک‌بار bootstrap اثبات‌شده فعلی را اجرا می‌کند و سپس همان وضعیت را به baseline Ledger تبدیل می‌کند.
- روی نصب RC18 موجود، RC19 فقط baseline را می‌سازد و backfill/reconciliationهای RC12 تا RC18 را replay نمی‌کند.
- Health Center وضعیت Migration Ledger و آخرین ۲۰ migration را بدون PII نمایش می‌دهد.
- `SCHEMA_VERSION` در RC19 تغییر نکرده و همچنان `2026.10.05.7` است؛ C1 هیچ جدول/ستون برنامه‌ای را تغییر نمی‌دهد.
- وابستگی اجرای Upgrade به `admin_init` هنوز عمداً باقی است و در Phase C / C2 اصلاح خواهد شد.


## 1.3.1-rc37 — Phase E / E2: Gate واضح Programs

- ورود به بخش «برنامه‌ها» دیگر کاربر فاقد شرایط را بی‌توضیح به پرونده منتقل نمی‌کند.
- خود بخش برنامه‌ها علت دقیق مسدودبودن را برای سه وضعیت «بدون پرونده»، «پرونده ناقص» و «در انتظار تأیید» نمایش می‌دهد.
- CTA صریح متناسب با وضعیت شامل «تشکیل پرونده»، «تکمیل پرونده» یا «مشاهده وضعیت پرونده» است.
- تا زمانی که Gate بسته است، bundle و API کامل Programs بارگذاری/فراخوانی نمی‌شود؛ پس از رفع وضعیت، Feature در همان مسیر عادی lazy load می‌شود.
- Backend همچنان مرجع نهایی مجوز است و خطاهای REST نیز metadata سازگار Gate و CTA را برمی‌گردانند.
- Schema بدون تغییر `2026.10.06.2` باقی می‌ماند.
