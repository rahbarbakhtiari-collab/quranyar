# چک‌پوینت پروژه قرآن‌یار — Phase C / C6 آماده نصب

**تاریخ:** 2026-10-06  
**نسخه:** QuranYar Messenger `1.3.1-rc24`  
**Schema:** `2026.10.05.7` — بدون تغییر نسبت به RC23  
**مبنای ارتقا:** RC23 / Migration Ledger `12/12` / Module Lifecycle `17/17`  
**وضعیت:** C6 Implemented + Local/Artifact Tests PASS — Pending Production Verification

## 1. هدف C6

بستن مشکل `Persistence Boundary` بدون تغییر business workflow: هر دامنه‌ای که Repository canonical دارد، persistence runtime خود را از همان Repository عبور می‌دهد و Service/Controller دیگر SQL مستقیم اجرا نمی‌کند.

این مرحله عمداً schema ownership را از Feature/Installer نمی‌گیرد. `dbDelta` و schema diagnostics می‌توانند در ماژول مالک schema باقی بمانند؛ هدف C6 حذف bypass کردن Repository در عملیات دامنه است.

## 2. Programs — Repository ownership کامل

`QYS_Program_Service` و `QYS_Programs_Module` دیگر `$wpdb` یا SQL مستقیم ندارند.

عملیات زیر به `QYS_Program_Repository` منتقل شدند:

- transaction boundary (`begin / commit / rollback`)
- Admission counts
- Program create / university scope insert / status CAS / row locks
- Program audit
- Application lock/update/insert/answers
- Enrollment lock/update/insert
- terminal-state reconciliation reads/writes
- Form version / field CRUD / publish
- Attendance link persistence
- Member Program Record read model
- tenant snapshot backfill
- terminal Program ID lookup

Business policy و state machine همچنان در `QYS_Program_Service` باقی مانده‌اند؛ Repository فقط persistence owner است.

## 3. Members — MemberRepository ownership

SQL مستقیم از `QYS_Members_Module` حذف شد.

لغو Birthday jobهای pending مربوط به یک عضو اکنون با:

`QYS_Member_Repository::cancel_pending_birthday_for_member()`

انجام می‌شود. Queue cancellation از قبل متعلق به `QYS_Campaign_Repository` بود و همان contract حفظ شده است.

## 4. Targeted Messaging — CampaignRepository ownership

`QYS_Targeted` برای عملیات runtime زیر دیگر SQL مستقیم ندارد:

- ایجاد Targeted metadata
- ثبت `launched_at_utc`
- ثبت `cancelled_at_utc`
- انتخاب Campaignهای زمان‌بندی‌شده due

مرزهای جدید Repository:

- `insert_targeted_meta()`
- `mark_targeted_launched()`
- `mark_targeted_cancelled()`
- `due_targeted_campaign_ids()`

DDL جدول Targeted در `ensure_schema()` عمداً در Feature owner باقی مانده است؛ این DDL business persistence محسوب نمی‌شود.

## 5. Universities — UniversityRepository ownership

runtime transaction و audit چنددانشگاهی به `QYS_University_Repository` منتقل شدند:

- `begin / commit / rollback`
- `insert_audit()`

`QYS_Multitenant` دیگر برای workflowهای انتقال عضو، تثبیت مالک، تأیید/تعلیق نماینده و تأیید عضو SQL runtime مستقیم اجرا نمی‌کند.

Safety check حضور باز نیز از SQL cross-domain خارج شد و با API مالک Attendance انجام می‌شود:

`QYS_Attendance::open_record_count_for_member()`

در Multitenant فقط schema creation/introspection متعلق به همان ماژول باقی مانده است.

## 6. Migration C6

Migration جدید:

`20261006_013_rc24_repository_ownership`

ویژگی‌ها:

- Infrastructure-only
- بدون جدول/ستون جدید
- بدون rewrite داده دامنه
- متعلق به lifecycle module `programs`
- contract وجود boundaryهای Repository جدید را قبل از complete شدن تأیید می‌کند
- Ledger مورد انتظار پس از نصب: `13/13 completed`
- Schema همچنان `2026.10.05.7`

پس از RC24، در جدول Module Lifecycle انتظار می‌رود migration count ماژول `programs` برابر `1` باشد.

## 7. تست‌های اجراشده

- PHP syntax: `47/47 PASS`
- JavaScript syntax: `10/10 PASS`
- CSS structural: `10/10 PASS`
- C6 source/static contracts: `35/35 PASS`
- Repository ownership runtime: `15 assertions PASS`
- Module lifecycle runtime: `17 assertions PASS` و `17/17 modules booted`
- Migration regression RC23→RC24: `18 assertions PASS` و Ledger `12/13 → 13/13`
- C5 Program/Attendance boundary regression: `11 assertions PASS`
- C5 Attendance adapter regression: `10 assertions PASS`
- C5 Permission/Targeted regression: `7 assertions PASS`
- C5 Security/owner regression: `6 assertions PASS`
- Full plugin boot smoke: `15 assertions PASS` و `17/17 modules booted`
- Hook parity RC23→RC24:
  - actions `53 = 53 PASS`
  - filters `4 = 4 PASS`
  - shortcodes `2 = 2 PASS`
  - activation hooks `1 = 1 PASS`
- C5/C4 shared-helper behavior parity: `PASS`

تمام تست‌های Artifact پس از ZIP نهایی دوباره اجرا می‌شوند و نتیجه در Test Report ثبت می‌شود.

## 8. خارج از دامنه C6

عمداً انجام نشده‌اند:

- C7: `FeatureRegistry → Module Registry` نهایی
- cycle باقی‌مانده FeatureRegistry ↔ Multitenant که با C7 جمع می‌شود
- split کردن God Classهای بزرگ Attendance / Birthday / Excel به Repositoryهای تازه؛ این‌ها Repository موجودی را bypass نمی‌کنند و موضوع جداگانه P2-04 هستند
- Phase D Performance
- UX/UI یا Feature محصولی جدید
- Live Edge Acceptance Deferred مربوط به Phase A/B

## 9. نصب روی Production

RC23 را با RC24 **جایگزین** کنید؛ افزونه حذف/Uninstall نشود.

Migration 013 تغییر فیزیکی دیتابیس ندارد. automatic runner باید روی اولین درخواست عادی آن را اجرا کند.

پس از نصب، Health Center باید نشان دهد:

- Schema = `2026.10.05.7`
- Migration Ledger = `13/13 completed`
- Upgrade gate = آماده
- Module lifecycle = `17/17 booted`
- ماژول Programs: Migration count = `1`
- Attendance dependency همچنان شامل `programs`
- failed = `0`
- blocked = `0`
- degraded = `0`
- Worker/Cron/Retention سالم
- Queue بدون وضعیت غیرعادی

## 10. Rollback

C6 هیچ Schema فیزیکی یا داده دامنه‌ای را تغییر نمی‌دهد.

Rollback فایل به RC23 نیازمند rollback دیتابیس نیست. Entry تکمیل‌شده Migration 013 می‌تواند به‌عنوان history در Ledger باقی بماند.

## دستور بعدی پس از تأیید Production

**«مرحله بعدی — Phase C / C7 — Module Registry»**
