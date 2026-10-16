# چک‌پوینت پروژه قرآن‌یار — Phase B / B5 آماده نصب

**تاریخ:** 2026-10-05  
**نسخه:** QuranYar Messenger `1.3.1-rc17`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC16  
**مبنای ارتقا:** RC16  
**وضعیت:** B5 Implemented + Local/Artifact Tests PASS — Pending Production Functional Verification

---

## 1. هدف B5

یک Policy واحد برای «ظرفیت، پذیرش، Waitlist و Enrollment» برقرار شود تا:

- تعداد افراد پذیرفته‌شده از ظرفیت عبور نکند.
- پذیرش هم‌زمان چند دانشگاه باعث overbooking نشود.
- Waitlist واقعاً از سمت Server enforce شود.
- تبدیل Accepted به Enrollment ظرفیت دوم مصرف نکند.
- آزادشدن ظرفیت قابل پیش‌بینی باشد.

---

## 2. مدل ظرفیت

ظرفیت از لحظه `accepted` شدن Application رزرو می‌شود.

هر صندلی فقط یک بار شمرده می‌شود:

- `accepted` بدون Enrollment = یک صندلی رزرو
- Enrollment با وضعیت `enrolled` یا `participating` = یک صندلی فعال
- Enrollment پایانی مانند `withdrawn / no_show / cancelled / closed` = ظرفیت آزاد

تبدیل:

`accepted → enrolled`

مصرف ظرفیت را از ۱ به ۲ افزایش نمی‌دهد؛ همان Reservation به Enrollment تبدیل می‌شود.

ظرفیت `0` همچنان به معنای «بدون سقف» است.

---

## 3. Concurrency و چنددانشگاهی

ظرفیت در Program مشترک، ظرفیت **کل Program** است، نه ظرفیت جداگانه برای هر دانشگاه.

در پذیرش:

1. Transaction آغاز می‌شود.
2. سطر Program با `FOR UPDATE` قفل می‌شود.
3. Application نیز با `FOR UPDATE` خوانده می‌شود.
4. ظرفیت جهانی دوباره محاسبه می‌شود.
5. تنها در صورت وجود صندلی، وضعیت به `accepted` تغییر می‌کند.

بنابراین دو نماینده دانشگاه مختلف نمی‌توانند هم‌زمان آخرین صندلی را هر دو رزرو کنند.

---

## 4. Waitlist

- اگر `waitlist_enabled=0` باشد، Server انتقال به `waitlisted` را رد می‌کند.
- اگر ظرفیت پر و Waitlist فعال باشد، درخواست پذیرش با `capacity_waitlist` متوقف می‌شود.
- مدیر می‌تواند متقاضی را در Waitlist قرار دهد.
- ارتقای `waitlisted → accepted` فقط وقتی ظرفیت آزاد باشد انجام می‌شود.
- ارتقا عمداً خودکار نیست؛ انتخاب متقاضی در این مرحله مدیریتی می‌ماند.
- اگر ظرفیت پر و Waitlist غیرفعال باشد، Submit جدید عضو نیز بسته می‌شود.
- اگر Waitlist فعال باشد، Submit اولیه هنگام ظرفیت پر همچنان مجاز است تا مدیر درخواست را بررسی کند.

---

## 5. Legacy Safety

اگر داده‌های قدیمی از قبل بیش از ظرفیت Accepted داشته باشند:

- هیچ فرد پذیرفته‌شده‌ای خودکار تنزل داده نمی‌شود.
- تعهد تاریخی حفظ می‌شود.
- `over_capacity` قابل تشخیص است.
- پذیرش جدید تا آزادشدن ظرفیت متوقف می‌شود.
- Accepted قدیمی همچنان می‌تواند به Enrollment تبدیل شود؛ سیستم تعهد قبلی را بی‌صدا لغو نمی‌کند.

---

## 6. UI مدیریتی

در جزئیات Program اکنون نمایش داده می‌شود:

- ظرفیت کل
- ظرفیت رزروشده/فعال کل Program
- ظرفیت باقی‌مانده
- تعداد کل Waitlist
- فعال/غیرفعال بودن Waitlist
- هشدار ظرفیت کامل یا Legacy over-capacity

نماینده هر دانشگاه همچنان فقط PII متقاضیان Tenant خودش را می‌بیند؛ اعداد ظرفیت، Aggregate غیرشخصی کل Program هستند.

---

## 7. Schema

B5 هیچ جدول یا ستونی اضافه نمی‌کند.

**Schema همچنان:** `2026.10.05.7`

بنابراین Migration دیتابیس جدیدی وجود ندارد.

---

## 8. تست‌های اجراشده

- PHP syntax: 34/34 PASS
- JavaScript syntax: 10/10 PASS
- CSS structural: 10/10 PASS
- B5 source contracts: 21/21 PASS
- B5 Admission runtime-like scenarios: 16/16 PASS
- Phase A source regression: 26/26 PASS
- Phase A cross-tenant regression: 14/14 PASS
- B1 Delivery State regression: PASS
- B2 Queue Lease/Recovery regression: PASS
- B3 Welcome/Activation regression: PASS
- B4 Program terminal-state regression: PASS
- B4 Attendance terminalization regression: PASS
- Plugin boot smoke: PASS

---

## 9. Live Acceptance پیشنهادی

بعداً روی یک Program آزمایشی ظرفیت محدود:

1. ظرفیت = 1، Waitlist فعال
2. پذیرش نفر اول → موفق
3. پذیرش نفر دوم → رد به دلیل ظرفیت
4. نفر دوم → Waitlist
5. انصراف/آزادشدن صندلی نفر اول
6. ارتقای نفر دوم Waitlist → Accepted
7. Enrollment نفر دوم بدون مصرف صندلی دوم
8. تکرار با Waitlist غیرفعال
9. سناریوی هم‌زمان دو دانشگاه برای آخرین صندلی

---

## 10. وضعیت Phase B

| زیرمرحله | وضعیت |
|---|---|
| B1 — Message Delivery State Model | ✅ Production Verified |
| B2 — Sending Lease & Recovery | ✅ Production Verified |
| B3 — Welcome / Activation Reliability | ✅ Production Health Verified |
| B4 — Program Terminal State Orchestration | ✅ Production Health Verified |
| B5 — Capacity / Waitlist Policy | 🟨 Implemented — Pending Production Functional Verification |
| B6 — Retention Policy | ⬜ Next |

---

## دستور بعدی پس از نصب/تأیید RC17

**«مرحله بعدی — Phase B / B6»**
