# چک‌پوینت قرآن‌یار — RC15 / Phase B / B3

**تاریخ:** 2026-10-05  
**نسخه:** `1.3.1-rc15`  
**Schema هدف:** `2026.10.05.6`  
**مبنای ارتقا:** RC14 / Schema `2026.10.05.5`

## وضعیت مسیر

- Phase A: پیاده‌سازی + Health Production تأیید؛ Live Cross-Tenant Acceptance همچنان Deferred.
- B1: Production Verified.
- B2: Production Verified.
- B3: Implemented + Local/Artifact Tests PASS؛ منتظر نصب و تأیید Production.
- B4 تا B6: Remaining.

## تغییر اصلی B3

پیام‌های Welcome و Activation دیگر یک log یک‌بارمصرف با `accepted/failed` نیستند. آن‌ها اکنون از lifecycle امن تحویل استفاده می‌کنند:

`pending → sending → sent | retryable_failed | definitive_failed | uncertain | cancelled`

### ایمنی Crash/Retry

- Lease هر claim: ۱۸۰ ثانیه.
- قبل از شبکه `outbound_started_at_utc` ثبت می‌شود.
- Crash قبل از outbound marker → بازیابی امن به `pending`.
- Crash بعد از outbound marker → `uncertain` و بدون resend خودکار.
- `uncertain` و `definitive_failed` هرگز خودکار resend نمی‌شوند.
- `retryable_failed` حداکثر ۳ تلاش در پنجره ۲۴ ساعته دارد؛ backoff: ۶۰ و ۳۰۰ ثانیه.
- نبود توکن برنامک قبل از شبکه attempt را مصرف نمی‌کند و رکورد در `pending` می‌ماند.

### کنترل دامنه عضو

پیش از retry دوباره بررسی می‌شود:

- consent فعال باشد.
- Activation فقط برای عضو verified ارسال شود.
- دانشگاه عضو با snapshot ثبت‌شده در log یکسان باشد.
- تغییر scope، retry معلق را `cancelled` می‌کند.

### Migration قدیمی

- `accepted → sent`
- `sending → uncertain`
- `failed →` طبقه‌بندی با `QYS_Delivery_State`
- `network_failure → uncertain`
- `429/rate_limited → retryable_failed` فقط در پنجره retry

## Health Center

Health Center بدون PII اکنون شمارنده‌های Welcome/Activation را نمایش می‌دهد و stale `sending` این دو مسیر را تشخیص می‌دهد.

## پس از نصب

- یک بار `wp-admin` باز شود.
- Schema باید `2026.10.05.6` باشد.
- وضعیت کلی Health باید سالم باشد.
- در حالت عادی Welcome/Activation stale sending باید صفر باشد.
- وجود historical `uncertain` الزاماً به معنی خرابی سیستم نیست و نباید خودکار resend شود.

## گام بعدی پس از تأیید Production

**Phase B / B4 — Program Terminal State Orchestration**
