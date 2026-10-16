# چک‌پوینت قرآن‌یار — RC50 Maintenance / Family Attribution

**تاریخ:** 2026-10-11  
**نسخه:** QuranYar Messenger `1.3.1-rc50`  
**Schema:** `2026.10.11.1`  
**مبنای سورس:** RC49 / Ledger هدف قبلی `38/38`  
**وضعیت:** Implemented + Local Tests PASS — Pending Production Verification

## تصمیم محصول

انتساب «خانواده پرسنل» باید به شخص/اشخاص واقعی پرسنل متصل باشد و از نقش اصلی عضو جدا بماند. یک فیلد enum واحد برای این نیاز کافی نیست، چون ممکن است هر دو والد همکار باشند یا خودِ فرزند/همسر نیز کارمند یا استاد باشد.

مدل RC50 دو محور مستقل دارد:

- `member_role`: نقش اصلی دانشگاهی (`staff / faculty / student / staff_family`)
- `family_links`: رابطه‌های مستقل `spouse / child` با پرونده پرسنل

این مدل باعث می‌شود نقش سازمانی یک فرد برای ثبت رابطه خانوادگی از بین نرود.

## قواعد دامنه

- `staff_family`: حداقل یک link معتبر اجباری.
- `staff / faculty / student`: family link اختیاری.
- دو والد همکار: دو link از نوع `child`.
- فرزند همکار: `member_role=staff|faculty` + link نوع `child`.
- همسر همکار: نقش اصلی حفظ می‌شود + link نوع `spouse`.
- حداکثر ۴ link مستقیم برای یک پرونده؛ reverse summary پرسنل محدود به این سقف نیست و فرزندان متعدد می‌توانند هرکدام link خود را ثبت کنند.
- Target باید همان دانشگاه، `staff/faculty` و `verified=1` باشد.
- Self-link و relation type نامعتبر رد می‌شود؛ link تکراری یکسان dedupe و نسبت متناقض برای یک target رد می‌شود.

## حریم خصوصی

Directory عمومی پرسنل به عضو ارائه نمی‌شود. ایجاد link تازه فقط با **کد ملی دقیق** پرسنل انجام می‌شود. پس از ثبت، Client فقط شناسه داخلی و نام نمایش لازم را دریافت می‌کند.

## داده و Schema

جدول جدید `qys_family_links`:

- `member_id`
- `staff_member_id`
- `relation_type`
- `university`
- timestamps
- UNIQUE `(member_id, staff_member_id, university)`
- indexهای member/staff/relation scope

Migration: `20261011_039_rc50_family_attribution`، owner=`members`.

هدف پس از Upgrade:

- Schema=`2026.10.11.1`
- Ledger=`39/39`
- Members migrations=`7`
- Physical diagnostics=`13/13`

## انتقال دانشگاه

رابطه به scope دانشگاه متصل است. هنگام درخواست انتقال، رابطه مقصد جدا ذخیره می‌شود و تا تأیید انتقال scope فعلی نیز حفظ می‌شود. پس از تأیید، scopeهای قدیمی متعلق به عضو prune می‌شوند. برای `staff_family`، تأیید انتقال بدون relation معتبر در دانشگاه مقصد ممکن نیست.

## UX پرونده

بخش «انتساب خانوادگی به پرسنل» برای همه نقش‌ها نمایش داده می‌شود، چون رابطه از نقش مستقل است. برای `staff_family` نشان «الزامی» و برای سایر نقش‌ها «اختیاری» است. افزودن هر ردیف شامل نسبت و کد ملی دقیق پرسنل است؛ رابطه ذخیره‌شده در ویرایش بعدی با نام پرسنل نمایش داده می‌شود.

## نمایش مدیریتی

فهرست اعضای مدیرکل، پنل دانشگاه، متقاضیان Program و افراد حضور ستون/نمای «انتساب خانوادگی» دارند. خروجی CSV Program و Attendance نیز این مقدار را صادر می‌کند. Summary در هر دو جهت ساخته می‌شود؛ مثال:

- عضو: `فرزندِ علی رضایی؛ فرزندِ زهرا محمدی`
- پرسنل: `فرزند: مریم رضایی`

## Eligibility

- `profile_complete()` برای `staff_family` relation معتبر را نیز لازم می‌داند.
- Attendance REST، roster و export fail-closed هستند.
- Birthday aggregate و readiness complete count با همان شرط همسو شده‌اند.
- Programs از قبل `profile_complete()` authoritative را مصرف می‌کند.
- اگر target بعداً نقش staff/faculty یا وضعیت verified معتبر را از دست بدهد، link دیگر برای کامل‌بودن پرونده حساب نمی‌شود.

## Legacy

برای اعضای قدیمی `staff_family` هیچ auto-link حدسی انجام نمی‌شود. بعد از RC50، تا زمانی که relation واقعی را ثبت نکنند پرونده کامل محسوب نمی‌شوند. داده هویتی موجود حذف یا بازنویسی نمی‌شود.

## تست‌های Work Tree

- PHP syntax: `56/56 PASS`
- JavaScript syntax: `12/12 PASS`
- CSS structural: `10/10 PASS`
- RC50 static/product contracts: `54/54 PASS`
- Family model runtime contract: `14/14 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`

## تست دستی Production

پس از Health Verification این چهار سناریو بررسی شود: `staff_family` با یک پرسنل، فرزند با دو والد همکار، کارمند/استاد که خودش فرزند پرسنل است، و نمایش reverse relation در فهرست اعضا.

## Rollback

RC50 یک جدول افزایشی جدید و Schema version جدید دارد. برای rollback کامل به RC49، بازگردانی فایل به‌تنهایی کافی نیست؛ `qys_schema_version` باید به `2026.10.06.2` برگردد یا از backup پیش از RC50 restore شود. جدول `qys_family_links` را برای جلوگیری از از‌دست‌رفتن رابطه‌های ثبت‌شده حذف نکنید مگر در rollback داده‌ای برنامه‌ریزی‌شده.

## وضعیت Roadmap

Phase F همچنان **Frozen / Not Started** است.
