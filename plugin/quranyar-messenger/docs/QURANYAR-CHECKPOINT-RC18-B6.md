# چک‌پوینت پروژه قرآن‌یار — Phase B / B6 آماده نصب

**تاریخ:** 2026-10-05  
**نسخه:** QuranYar Messenger `1.3.1-rc18`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC17  
**مبنای ارتقا:** RC17  
**وضعیت:** B6 Implemented + Local/Artifact Tests PASS — Pending Production Cleanup/Health Verification

---

## 1. هدف B6

جلوگیری از نگهداری نامحدود داده‌های حساس و عملیاتی، بدون حذف داده‌ای که هنوز برای ارسال، recovery، reconciliation یا audit فعال لازم است.

اصل اصلی:

> Retention فقط داده نهایی و قدیمی را کمینه/حذف می‌کند. وضعیت‌های فعال یا نامشخص هرگز قربانی cleanup خودکار نمی‌شوند.

---

## 2. Retention Matrix

| داده | اقدام | بازه |
|---|---|---:|
| Queue payload در وضعیت نهایی | حذف متن پیام و حفظ metadata تحویل | 90 روز |
| Excel follow-up | ناشناس‌سازی member_id، نام، کد ملی و موبایل | 180 روز |
| Birthday archive نهایی | حذف PII مستقیم و متن پیام | 180 روز |
| Birthday archive نهایی | حذف کامل رکورد | 730 روز |
| Birthday log نهایی | حذف رکورد | 550 روز |
| Campaign + Queue history کاملاً نهایی | حذف کامل به‌صورت یک واحد | 730 روز |
| Birthday archive access audit | حذف | 730 روز |
| Tenant audit | حذف | 730 روز |
| Program audit | حذف | 730 روز |
| Health Incident Log | از قبل bounded و بدون PII؛ حداکثر 50 رکورد | بدون cleanup جدا |

---

## 3. وضعیت‌های محافظت‌شده

Retention هرگز Queue با این وضعیت‌ها را خودکار حذف نمی‌کند:

- `pending`
- `sending`
- `retryable_failed`
- `uncertain`
- legacy `failed`

Birthday archive با وضعیت‌های `sending` و `uncertain` نیز از anonymize/delete خودکار محافظت می‌شود.

---

## 4. Campaign purge

Campaign فقط زمانی پس از 730 روز حذف می‌شود که:

- scheduled نباشد؛
- هیچ Queue غیرنهایی نداشته باشد؛
- هیچ Queue جدیدتر از cutoff نداشته باشد؛
- یا Draft/Cancelled قدیمی باشد، یا حداقل Queue history نهایی داشته باشد.

حذف به‌صورت تراکنشی انجام می‌شود و شامل این داده‌هاست:

- `excel_followup`
- `import_reports`
- `target_campaign_meta`
- `queue`
- `campaigns`

بنابراین Campaign نیمه‌پاک‌شده و گزارش ناقص باقی نمی‌ماند.

---

## 5. Cron و Fault Isolation

رویداد مستقل:

`qys_retention_cleanup`

- اجرای پیش‌فرض: روزانه
- اولین اجرا: حدود 5 دقیقه پس از اولین request نسخه RC18
- Batch عمومی: حداکثر 250 رکورد در هر دسته
- Campaign purge: حداکثر 25 Campaign در هر اجرا
- Lock مستقل: 15 دقیقه
- اجرای هم‌زمان دوم متوقف می‌شود.
- خطای DB به‌صورت `error` در telemetry دیده می‌شود اما raw error/PII ذخیره نمی‌شود.

---

## 6. Health Center

RC18 این موارد را اضافه می‌کند:

- وضعیت Scheduled بودن Retention
- زمان اجرای بعدی
- آخرین اجرای cleanup
- نتیجه آخر (`ok / error / never`)
- تعداد تغییرات آخر
- هشدار unscheduled / failed / بیش از 48 ساعت stale
- نمایش Retention Matrix

در اولین مشاهده پس از نصب، اگر هنوز cleanup اجرا نشده باشد، حالت طبیعی:

`زمان‌بندی‌شده؛ در انتظار اولین اجرا`

است و نباید وضعیت کلی را بحرانی کند.

---

## 7. Schema

B6 جدول یا ستون جدیدی اضافه نمی‌کند.

**Schema همچنان:** `2026.10.05.7`

در نتیجه Migration دیتابیس جدیدی وجود ندارد.

---

## 8. تست‌های اجراشده

- PHP syntax: 35/35 PASS
- JavaScript syntax: 10/10 PASS
- CSS structural: 10/10 PASS
- B6 source contracts: 29/29 PASS
- B6 runtime-like retention scenarios: PASS
- schedule / first-run status: PASS
- bounded cleanup: PASS
- lock/concurrency: PASS
- write-failure health telemetry: PASS
- no raw error persisted: PASS
- protected delivery-state SQL contract: PASS
- campaign transactional purge contract: PASS
- Phase A source regression: 26/26 PASS
- Phase A cross-tenant regression: 14/14 PASS
- B1 Delivery State regression: PASS
- B2 Queue Lease/Recovery regression: PASS
- B3 Welcome/Activation regression: PASS
- B4 terminal-state/Attendance regression: PASS
- B5 Admission/Capacity regression: PASS
- Plugin boot smoke: PASS

---

## 9. Production Acceptance پس از نصب

بعد از جایگزینی RC17 با RC18:

1. Health Center باید Schema `2026.10.05.7` را سالم نشان دهد.
2. Retention باید `زمان‌بندی‌شده` باشد.
3. قبل از اولین اجرا، `در انتظار اولین اجرا` طبیعی است.
4. حدود 5 دقیقه بعد، آخرین نتیجه باید `ok` و تعداد تغییرات معمولاً 0 باشد، مگر داده قدیمی واجد شرایط وجود داشته باشد.
5. Queue/Lease/Welcome/Activation نباید تغییر غیرعادی کند.

تست حذف/ناشناس‌سازی روی داده مصنوعی قدیمی Production فعلاً Deferred و برای Acceptance Pass جامع نگه داشته می‌شود.

---

## 10. وضعیت Phase B پس از RC18

| زیرمرحله | وضعیت |
|---|---|
| B1 — Message Delivery State Model | ✅ Production Verified |
| B2 — Sending Lease & Recovery | ✅ Production Verified |
| B3 — Welcome / Activation Reliability | ✅ Production Health Verified |
| B4 — Program Terminal State Orchestration | ✅ Production Health Verified |
| B5 — Capacity / Waitlist Policy | 🟨 Production Health/UI Verified / Functional Edge Acceptance Deferred |
| B6 — Retention Policy | 🟨 Implemented — Pending Production Cleanup/Health Verification |

پس از تأیید RC18، **Phase B از نظر کد کامل است**. گام منطقی بعدی یک Acceptance Pass جمع‌بندی برای تست‌های زنده Deferred است؛ سپس Phase C آغاز می‌شود.
