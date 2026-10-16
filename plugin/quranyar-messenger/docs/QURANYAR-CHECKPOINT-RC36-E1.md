# چک‌پوینت پروژه قرآن‌یار — Phase E / E1 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc36`  
**Schema:** `2026.10.06.2` — بدون تغییر نسبت به RC35  
**مبنای ارتقا:** RC35 / Migration Ledger `24/24` / Module Lifecycle `17/17` / Module Registry `17/10`  
**وضعیت:** E1 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف E1

بستن `QYS-P2-21 — تشکیل پرونده به رضایت دریافت پیام وابسته است`.

از RC36، **عضویت/پرونده** و **Messaging Consent** دو وضعیت مستقل‌اند:

- تشکیل یا ویرایش پرونده به اجازه دریافت پیام وابسته نیست.
- دریافت پیام خصوصی یک opt-in جداگانه و اختیاری است.
- عضو جدید اگر مجوز پیام را صریحاً فعال نکرده باشد، با `consent=0` ثبت می‌شود.
- Existing member هنگام ویرایش پرونده، consent فعلی خود را حفظ می‌کند.
- غیرفعال بودن پیام، `registered/profile_complete/verified` را تغییر نمی‌دهد.

## 2. Backend Contract

`QYS_Messenger::membership_consent_contract()`:

`membership: independent | messaging: optional | default: off`

همچنین Status API علاوه بر فیلدهای سازگار قبلی:

- `registered`
- `enabled`

فیلدهای صریح زیر را نیز برمی‌گرداند:

- `membership_active`
- `messaging_consent`

بنابراین clientهای فعلی نمی‌شکنند و clientهای آینده می‌توانند دو مفهوم را جدا مصرف کنند.

## 3. ثبت عضو جدید

`/qys/v1/enroll` دیگر `consent=true` را شرط تشکیل پرونده نمی‌داند.

رفتار:

- بدون درخواست/تأیید مجوز پیام → پرونده ساخته می‌شود و `consent=0`.
- با opt-in صریح از پنجره رسمی ایتا → پرونده ساخته می‌شود و `consent=1`.
- Welcome فقط وقتی attempt می‌شود که consent فعال باشد.
- اگر consent خاموش باشد، پرونده بدون ساخت delivery attempt پیام تشکیل می‌شود.

Schema از قبل `consent DEFAULT 0` داشته و E1 نیاز به تغییر فیزیکی دیتابیس ندارد.

## 4. Mini-app UX

در فرم عضو:

- دکمه ثبت پرونده دیگر به `requestWriteAccess` وابسته نیست.
- «فعال‌سازی دریافت پیام» به‌صورت گزینه اختیاری جدا نمایش داده می‌شود.
- ردکردن یا صادرنشدن مجوز پیام، دکمه ثبت پرونده را غیرفعال نمی‌کند.
- متن‌ها صریحاً اعلام می‌کنند عضویت بدون دریافت پیام ممکن است.
- «بیشتر / حساب» فقط نقص پرونده یا تأیید هویت را به‌عنوان attention حساب می‌کند؛ خاموش بودن پیام، حساب را ناقص نشان نمی‌دهد.
- تبریک تولد فقط وقتی «فعال» گزارش می‌شود که هم opt-in تولد و هم messaging consent فعال باشند.

## 5. Safety

بدون تغییر باقی مانده‌اند:

- احراز `initData`
- Tenant / permission boundaries
- `stop_member()` و لغو پیام‌های pending
- Reactivation با opt-in صریح
- Queue delivery-state semantics
- Welcome/Activation reliability
- Birthday consent checks
- Program/Attendance eligibility

هیچ consent موجودی به‌صورت خودکار روشن یا خاموش نمی‌شود.

## 6. Health Center و Migration

کارت جدید:

`Membership / messaging`

انتظار:

`membership: independent | messaging: optional | default: off`

Migration جدید:

`20261006_025_rc36_membership_messaging_consent`

ویژگی‌ها:

- متعلق به lifecycle module `members`
- UX/workflow code contract only
- بدون table/column/index جدید
- بدون rewrite داده دامنه
- Schema همچنان `2026.10.06.2`
- Ledger مورد انتظار پس از نصب: `25/25 completed`
- Members migration count پس از RC36 = `3`

## 7. تست‌های اجراشده

- PHP syntax: `54/54 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- E1 source/static contracts: `30/30 PASS`
- E1 runtime contract: `7/7 PASS`
- D10 Portal Asset Policy runtime: `20/20 PASS`
- D10 Feature enqueue runtime: `18/18 PASS`
- D10 migration contract: `PASS`
- Hook inventory: `57 actions / 6 filters / 2 shortcodes / 1 activation hook` — بدون تغییر نسبت به RC35

پس از ساخت ZIP نهایی همین تست‌ها روی Artifact استخراج‌شده دوباره اجرا می‌شوند.

## 8. خارج از دامنه E1

عمداً انجام نشده‌اند:

- E2: Gate واضح Programs
- E3: Confirmation برای destructive actions
- E4: Program Edit workflow
- E5: Member Withdraw
- E6: Error/Empty/Loading استاندارد
- E7: Draft فرم
- E8: validation field-level
- E9: bulk applicant management
- E10: اصلاح progress پرونده
- تغییر delivery-state یا retry semantics
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC35 را با RC36 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 025 تغییر فیزیکی دیتابیس ندارد و automatic runner باید در اولین درخواست عادی آن را complete کند.

پس از نصب Health Center باید نشان دهد:

- Schema = `2026.10.06.2`
- Migration Ledger = `25/25 completed`
- Upgrade gate = آماده
- Module Lifecycle = `17/17 booted`
- Module Registry = `17 modules / 10 features`
- Members migration count = `3`
- Universities migration count = `1`
- Birthday migration count = `1`
- Membership / messaging = `membership: independent | messaging: optional | default: off`
- Portal assets = `shell: base | feature: conditional | scope: section+permission`
- Birthday lookup = `column: birth_md | query: exact | index: ready`
- Batch export = `mode: streaming | chunk: 250 | cursor: keyset | memory: bounded`
- Health snapshot = `heartbeat: lightweight | full: 15m/on-demand`
- Queue dispatch index = `(status,id): ready | pending scan: indexed`
- Queue throughput = `mode: adaptive | budget: 20s | max: 30 | rate-limit: stop`
- Programs query plan = `forms: batch | counts: grouped | answers: batch`
- Home summary = `endpoint: lightweight | programs: compact | forms: deferred`
- Mini-app feature load = `programs: lazy | attendance: lazy | data: on_view`
- Runtime schema gate = `mode: version_ledger | probes: 0`
- Schema diagnostics = `Physical: 12 / 12`
- failed / blocked / degraded = `0`
- Worker/Cron/Retention سالم

### پذیرش UX اصلی E1

علاوه بر Health Center، یک آزمون کوتاه زنده لازم است:

1. با یک حساب ایتای آزمایشی که هنوز عضو نیست وارد برنامک شوید.
2. **بدون زدن «فعال‌سازی دریافت پیام»** فرم پرونده را کامل و ثبت کنید.
3. انتظار: پرونده با موفقیت تشکیل شود و وضعیت «دریافت پیام غیرفعال» باشد.
4. سپس «فعال‌سازی مجدد دریافت پیام» را بزنید و مجوز رسمی ایتا را تأیید کنید.
5. انتظار: همان پرونده باقی بماند و فقط وضعیت دریافت پیام فعال شود.

## 10. Rollback

E1 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC35 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 025 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«Phase E / E2 — Gate واضح Programs»**
