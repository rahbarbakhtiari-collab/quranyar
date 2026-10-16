# چک‌پوینت پروژه قرآن‌یار — RC51 / Mobile OTP Verification

**تاریخ:** 2026-10-11  
**نسخه:** QuranYar Messenger `1.3.1-rc51`  
**Schema:** `2026.10.11.2`  
**مبنای ارتقا:** RC50 / Ledger هدف قبلی `39/39`  
**وضعیت:** Implemented + Local/Artifact Tests PASS — Pending Production Verification and IPPanel credentials

## هدف

در ثبت‌نام جدید، شماره موبایل تنها پس از تأیید OTP قابل ذخیره باشد. همین قاعده هنگام تغییر شماره نیز اجرا شود.

## Provider

بر اساس مستندات IPPanel Edge، ارسال Pattern SMS از `POST https://edge.ippanel.com/v1/api/send` با `sending_type=pattern` انجام می‌شود. IPPanel این مسیر را برای پیام‌های فوری/OTP مناسب معرفی می‌کند. API key در Header `Authorization` ارسال می‌شود.

QuranYar از API احراز هویت حساب IPPanel (`send_sms_otp/confirm_otp`) استفاده نمی‌کند، چون آن flow برای ورود کاربر خود پنل IPPanel است؛ OTP کاربر قرآن‌یار توسط خود QuranYar تولید و از Pattern SMS ارسال می‌شود.

## Security contract

- code: 6 digit secure `random_int`
- TTL: 300s
- max verify attempts: 5
- resend cooldown: 60s
- per-user hourly requests: 5
- per-mobile hourly requests: 3
- OTP storage: HMAC transient only; no plaintext DB/log
- verified claim binding: `eitaa_id + mobile`
- claim TTL after verify: 15m for profile save
- duplicate mobile rejected before SMS send
- IPPanel API key encrypted with existing AES-256-GCM TokenStore

## Data model

`qys_members`:

- `mobile_verified_at_utc datetime NULL`
- `mobile_verification_required tinyint unsigned NOT NULL`

Fresh/new numbers use `required=1`. Existing RC50 rows receive `required=0` by migration to avoid falsely claiming historical OTP verification and to avoid breaking established members. Any mobile change requires a verified claim and switches the row to `required=1`.

## UX contract

- mobile input + Send OTP button
- 6-digit input appears after send
- exact field state: unverified / legacy / verified
- submit blocked in Client when current mobile does not match a verified/grandfathered state
- Server repeats the enforcement

## Health / expected production

- Schema=`2026.10.11.2`
- Migration Ledger=`40/40`
- Members migration count=`8`
- Physical diagnostics=`13/13`
- Mobile OTP=`provider ippanel | channel pattern-sms | registration required | change required | configured yes`
- failed/blocked/degraded=`0` after IPPanel settings are completed

## Rollback

RC51 adds two nullable/small member columns. File rollback to RC50 is technically possible because RC50 ignores these columns, but the completed migration may remain in Ledger history. Do not drop columns during rollback. New registrations created under RC51 keep their data.

## Phase status

Phase F remains **Frozen / Not Started**.
