# چک‌پوینت پروژه قرآن‌یار — RC53 / Program Re-application After Withdrawal

**تاریخ:** 2026-10-11  
**نسخه:** QuranYar Messenger `1.3.1-rc53`  
**Schema:** `2026.10.11.2` — بدون تغییر  
**مبنای ارتقا:** RC52 / Ledger `40/40`  
**Ledger هدف:** `41/41`  
**Programs migrations هدف:** `12`  
**وضعیت:** Implemented + Local/Artifact Tests PASS — Pending Production Health/UX Verification

## هدف

رفع باگ UX/Domain که عضو پس از انصراف از Program دیگر مسیر ثبت‌نام مجدد نداشت و فقط پیام «انصراف شما ثبت شده است» می‌دید.

## رفتار جدید

- اگر Program در وضعیت `registration_open` باشد و Application یا Enrollment عضو واقعاً `withdrawn` باشد، CTA «ثبت‌نام مجدد» نمایش داده می‌شود.
- کلیک روی CTA با confirmation صریح، Application موجود را به `submitted` برمی‌گرداند.
- پذیرش یا ثبت‌نام قطعی قبلی خودکار برنمی‌گردد؛ مسیر عادی `submitted → review/waitlist/accepted → enrolled` دوباره طی می‌شود.
- Registration window، Eligibility، Tenant snapshot و Capacity در Server دوباره بررسی می‌شوند.
- اگر Enrollment قبلی withdrawn باشد، پس از پذیرش جدید همان row به `enrolled` بازفعال می‌شود و `withdrawn_at_utc` پاک می‌شود؛ رکورد دوم ساخته نمی‌شود.
- Auditهای `member_reapplied` و `enrollment_reactivated` افزوده شدند و Attendance history قبلی محفوظ است.

## ظرفیت

Enrollment withdrawn همچنان ظرفیت را آزاد می‌کند. تنها اگر Application بعد از Withdrawal دوباره پذیرفته شود (`decided_at_utc > withdrawn_at_utc`) آن صندلی پیش از reactivation دوباره رزرو می‌شود. بنابراین نه Overbooking رخ می‌دهد و نه انصراف قدیمی به‌اشتباه ظرفیت را اشغال می‌کند.

## UX

- در کارت Program پس از Withdrawal: متن روشن + دکمه «ثبت‌نام مجدد».
- بعد از Reapply، وضعیت Application جدید (مثلاً «ثبت‌شده») بر وضعیت Enrollment قدیمی withdrawn اولویت نمایشی دارد.
- Reapply فعال دوباره قابل «پس‌گرفتن درخواست» است.
- در پنل مدیر، Application پذیرفته‌شده با Enrollment withdrawn دکمه «ثبت‌نام مجدد قطعی» دارد.
- Bulk enroll نیز همین حالت را پشتیبانی می‌کند.

## Migration / Health

Migration: `20261011_041_rc53_program_reapply_after_withdrawal`  
Owner: `programs`  
نوع: code-contract only  
Schema: unchanged

Health card مورد انتظار:

`Program reapply = mode: self-service | source: withdrawn-only | target: submitted | approval: normal-flow | capacity: rechecked | enrollment: reactivate-after-accept`

## تست‌ها

- PHP syntax: `57/57 PASS`
- JavaScript syntax: `12/12 PASS`
- CSS structural: `10/10 PASS`
- RC53 static contracts: `39/39 PASS`
- Reapply/withdraw state runtime assertions: `8/8 PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook`

همین تست‌ها پس از ساخت ZIP نهایی روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## Production acceptance

1. عضوِ دارای Application withdrawn در Program باز، «ثبت‌نام مجدد» ببیند.
2. عضوِ دارای Enrollment withdrawn نیز همین CTA را ببیند.
3. بعد از Reapply وضعیت `submitted` شود و پذیرش قبلی خودکار برنگردد.
4. مدیر دوباره Accept و سپس Enroll کند؛ Enrollment قدیمی re-activate شود.
5. ظرفیت پس از Withdrawal آزاد و پس از Acceptance جدید دوباره یک صندلی رزرو شود.
6. Attendance history و Audit قبلی باقی بمانند.

## Rollback

RC53 تغییر فیزیکی Schema ندارد. Rollback فایل به RC52 نیازمند rollback دیتابیس نیست؛ entry Migration 041 می‌تواند به‌عنوان history در Ledger باقی بماند.

Phase F همچنان **Frozen / Not Started** است.
