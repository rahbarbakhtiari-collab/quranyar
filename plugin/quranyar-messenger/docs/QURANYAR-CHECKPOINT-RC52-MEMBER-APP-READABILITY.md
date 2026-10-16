# چک‌پوینت پروژه قرآن‌یار — RC52 / Member Mini-app Readability

**تاریخ:** 2026-10-11  
**نسخه:** QuranYar Messenger `1.3.1-rc52`  
**Schema:** `2026.10.11.2` — بدون تغییر  
**Ledger هدف:** `40/40` — Migration جدید ندارد  
**وضعیت:** Implemented + Local/Artifact Tests PASS — Pending Production UX/Health Verification

## هدف

رفع سه مشکل مشاهده‌شده در برنامک ایتا: کنتراست ضعیف متن، ریزبودن نوشته‌ها، و لحن ماشینی/نیمه‌فنی. این کار Maintenance محدود است و Phase F / Design System را از Freeze خارج نمی‌کند.

## تغییرات UI و خوانایی

- متن پایه برنامک عضو `16px` شد.
- متن توضیحی عادی حدود `13–14px` و برچسب‌های فشرده حداقل `12.5px` تنظیم شدند.
- Bottom Navigation به `13px` (و حداقل `12.5px` روی عرض بسیار کم) ارتقا یافت.
- ورودی‌های فرم `16px` و حداقل ارتفاع `50px` دارند.
- رنگ متن اصلی `#142f49`، متن ثانویه `#405a72` و خط مرزی `#cbd9e4` شد.
- متن‌های بلند توضیحی در کارت‌ها و فرم‌ها Justify شدند؛ تیترها Justify نمی‌شوند.
- وزن‌های مصنوعی 650/750/850/900 در CSSهای Member حذف و به وزن‌های واقعی فونت 600/700/800 محدود شدند.

## تغییرات UX / Microcopy

- عبارت‌های ماشینی مانند «همگام‌سازی»، «پاسخ سرور»، «در سرور»، «ثبت قدیمی» و «بدون تغییر مسیر اضافه» از UI عضو حذف شدند.
- نمایش «نسخه برنامک / QuranYar x.x» از صفحه More حذف شد.
- صفحه Services دیگر KPI «خدمات فعال / نیازمند توجه» ندارد؛ وضعیت هر خدمت روی همان کارت دیده می‌شود.
- توضیح خدمات پیام، حضور، برنامه‌ها و تبریک تولد کوتاه و کاربرمحور شد.
- OTP در متن کاربر با «کد تأیید پیامکی» بیان می‌شود.
- انتساب خانوادگی با واژه‌های «نسبت خانوادگی / همکار» توضیح داده می‌شود و متن‌های اداری کوتاه‌تر شدند.
- خطاهای خام مرورگر/Fetch به پیام فارسی عمومی تبدیل می‌شوند.
- متن Programs و Attendance نیز بازنویسی شد؛ عبارت «زمان از سرور ثبت می‌شود» و اصطلاحات مشابه حذف شد.

## Safety / Scope

- TenantContext، PermissionService، OTP verification، Queue، Program state، Attendance state و REST authorization تغییر نکرده‌اند.
- Schema و Migration Ledger تغییر نکرده‌اند.
- Lazy load Programs/Attendance حفظ شده است.
- تغییرات فقط Presentation/Microcopy و fallback خطای شبکه در Client هستند.

## Health Center

کارت جدید:

`Member app readability = base: 16px | secondary: 13px typical | compact: 12.5px min | contrast: AA | copy: plain-fa | technical: hidden`

## معیار Production

- Schema=`2026.10.11.2`.
- Ledger=`40/40`.
- Members migrations=`8`.
- Physical diagnostics=`13/13`.
- Member app readability Ready.
- هیچ failed/blocked/degraded جدیدی ایجاد نشود.
- بررسی دستی حداقل Home، Profile، Programs، Attendance، Services و More روی موبایل انجام شود.

## Rollback

RC52 تغییر دیتابیس ندارد. Rollback فایل به RC51 نیازمند rollback دیتابیس نیست.

## تست‌های Work Tree

- PHP syntax: `57/57 PASS`.
- JavaScript syntax: `12/12 PASS`.
- CSS structural: `10/10 PASS`.
- RC52 static/readability contracts: `47/47 PASS`.
- targeted text/background contrast checks: `9/9 PASS` با حداقل نسبت `4.5:1`.
- عبارت‌های نیمه‌فنی هدف در UI عضو (`همگام‌سازی`، `پاسخ سرور`، `در سرور`، `نسخه برنامک`، `ثبت قدیمی` و موارد مشابه): `0` مورد.

این تست‌ها صحت syntax و قراردادهای ایستا را بررسی می‌کنند؛ تأیید نهایی حس خوانایی و سلسله‌مراتب بصری همچنان نیازمند مشاهده روی موبایل Production است.

همین مجموعه پس از ساخت Release Candidate و استخراج ZIP نیز دوباره PASS شد.
