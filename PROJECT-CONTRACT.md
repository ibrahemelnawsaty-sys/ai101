# عقد المشروع التقني — منصة أثر (لارافيل)

> **المستوى 3 في تسلسل المرجعية.** كل ملف في المشروع يلتزم بالأسماء والتواقيع هنا حرفيًا.
> تغيير أي اسم هنا يستوجب تحديث هذا الملف **أولًا**، ثم الكود.

---

## 1 · الهوية والثوابت

| البند | القيمة |
|---|---|
| اسم البرنامج | البرنامج التأسيسي في الذكاء الاصطناعي |
| الاسم المختصر | AI 101 |
| اسم المنصة | منصة البرامج التدريبية — مركز أثر |
| النطاق | `ai.wareed.vip` — قرار المالك `C-02` بصيغته المعدَّلة، وسلسلة التصحيح في `D-24` ثم `D-36` |
| نطاق المركز | `athar-dev.edu.sa` |
| البريد | `contact@athar-dev.edu.sa` |
| واتساب | `966582090332` |
| الشعار النصي | من هنا يبدأ الأثر |
| المنطقة الزمنية للعرض | `Asia/Riyadh` |
| التخزين | `UTC` |

**كل ما سبق يُقرأ من `config/athar.php` ← `.env`. يُمنع كتابته في الكود (BR-36).**

---

## 2 · مساحات الأسماء

```
App\Enums\*              أنواع محصورة (PHP 8.1 enums)
App\Models\*             نماذج Eloquent
App\Policies\*           سياسات التفويض
App\Http\Controllers\{Public,Auth,Participant,Trainer,Admin}\*
App\Http\Requests\{Auth,Participant,Trainer,Admin}\*
App\Http\Middleware\*
App\Services\Time\*      الوقت
App\Services\Attendance\*
App\Services\Grading\*
App\Services\Certificates\*
App\Services\Journey\*
App\Services\Permissions\*
App\Services\Audit\*
App\Services\Storage\*
App\Services\Tickets\*   تذاكر «الدعم الفني» (D-124)
App\Services\Cohorts\*   المنسّق الأساسي للدفعة (D-124)
App\Presenters\*         نماذج عرض الشاشات (ViewModel لكل شاشة) — §16
App\Support\*            أدوات صغيرة بلا حالة
App\Console\Commands\*   أوامر artisan والبوابات
```

---

## 3 · الأنواع المحصورة `App\Enums`

| Enum | القيم |
|---|---|
| `UserRole` | `admin` (المشرف العام) · `system_admin` (مدير النظام) · `trainer` · `coordinator` · `participant` — `coordinator` من `D-105`، والانقسام من `D-117`: «مدير النظام» في وثيقة المتطلبات هو `admin` في كل شيء إلا الحسابات ومعاينتها وصفحة الهبوط وإعدادات المنصة، فهذه لـ`system_admin` وحده؛ وفتح التسجيل وإغلاقه لـ`admin` |
| `UserStatus` | `pending` · `active` · `suspended` · `deleted` |
| `Gender` | `male` · `female` |
| `ProgramStatus` | `draft` · `published` · `archived` |
| `CohortStatus` | `upcoming` · `open` · `running` · `completed` |
| `EnrollmentStatus` | `pending` · `active` · `withdrawn` · `completed` |
| `EnrollmentRole` | `participant` · `trainer` · `coordinator` (`D-105`) |
| `SessionType` | `intro` · `training` · `project` · `closing` |
| `SessionStatus` | `scheduled` · `live` · `completed` · `cancelled` |
| `AttendanceStatus` | `present` · `late` · `absent` · `excused` · `incomplete` |
| `AssignmentStatus` | `draft` · `published` |
| `SubmissionStatus` | `submitted` · `under_review` · `graded` |
| `EvaluationEntity` | `assignment` · `final_project` |
| `JourneyStepStatus` | `locked` · `current` · `completed` |
| `ThreadType` | `trainer_dm` · `group` · `announcement` · `direct` (`D-118`: محادثة يبدؤها شخص مع آخر) |
| `EmailTokenType` | `verify` · `reset` |
| `ResourceType` | `file` · `link` · `video` |
| `SubmissionFieldType` | `url` · `github` · `file` · `text` · `textarea` — نوع حقل في نموذج تسليم المشروع الختامي (`D-121`) |
| `SubmissionFileFormat` | `pdf` · `powerpoint` · `word` · `excel` · `csv` · `text` · `markdown` · `zip` · `png` · `jpeg` · `webp` — صيغ يختارها المشرف لحقل رفع، **كلها من قائمة المنصة** (`uploads.allowed_extensions` وأنواع `PrivateFileService`)؛ قائمة المنصة نفسها لـ`D-17` (`D-121`) |
| `SupportTicketStatus` | `open` · `in_progress` · `resolved` · `closed` — تذكرة «الدعم الفني» (`D-124`) |
| `SupportTicketLevel` | `coordinator` · `admin` · `system_admin` — عند أي درجة التذكرة الآن (`D-124`) |
| `SupportTicketCategory` | `account` · `platform` · `program` · `other` — لا يغيّر المسار (`D-124`) |
| `SupportTicketEntryType` | `opened` · `reply` · `message` · `note` · `escalated` · `returned` · `assigned` · `resolved` · `reopened` · `closed` · `auto_closed` — سطر في خطّها الزمني (`D-124`) |

كل enum: `enum X: string` + دالة `label(): string` تُرجع `__('enums.x.'.$this->value)`.

---

## 4 · الجداول والأعمدة

> جميع الجداول: `id` = `char(36)` UUID (المفتاح `HasUuids`) · `created_at` · `updated_at`.
> الحذف الناعم `deleted_at` حيث يُذكر. المحرك InnoDB · `utf8mb4_unicode_ci`.

| الجدول | ملاحظات إلزامية |
|---|---|
| `users` | `email` فريد · `password_hash` لا يُرجَع أبدًا · `failed_login_count` · `locked_until` · حذف ناعم |
| `profiles` | `user_id` فريد · الأسماء الرباعية عربي وإنجليزي · `phone` **فريد** |
| `programs` | `slug` فريد · `objectives`/`target_audience`/`certificates` JSON |
| `cohorts` | `pass_score` افتراضي `60` · `min_attendance_rate` افتراضي `75` · `capacity` · `registration_closes_at` · `primary_coordinator_id` يقبل الفراغ (`nullOnDelete`) — المنسّق الأساسي، يقرؤه `PrimaryCoordinator` وحده (`D-124`) |
| `enrollments` | **فريد مركّب** `(cohort_id, user_id)` |
| `weeks` | `index` 1..4 |
| `sessions` | فهرس `(cohort_id, date)` · قيد `end_time > start_time` · `zoom_url` لا يُكشف قبل النافذة |
| `attendances` | **فريد مركّب** `(session_id, user_id)` · `ip_address` · `user_agent` · `edit_reason` |
| `assignments` | `max_score` · `due_at` · `allow_late` · `is_mandatory` |
| `submissions` | فهرس `(assignment_id, user_id)` · `version` يزيد ولا يحذف السابق (BR-19) |
| `evaluations` | `feedback` **إلزامي ≥ 10 أحرف** · قيد `0 ≤ score ≤ max_score` · `revision_reason` |
| `final_projects` | `is_unlocked` · `unlocked_by` · `unlocked_at` |
| `final_project_fields` | حقول نموذج التسليم لكل مشروع (`D-121`): `final_project_id` (`cascadeOnDelete`) · `type` · `label` · `description` · `tips` JSON · `is_required` · `accepted_formats` JSON · `max_kilobytes` · `max_files` · `position` · فهرس `(final_project_id, position)` |
| `project_submissions` | مثل `submissions` · **`answers` JSON** — عنصر لكل حقل بترتيب النموذج، يحمل **نسخة** من عنوان الحقل ونوعه مع القيمة أو الملفات (`SubmissionFields::answer()` كاتبه الوحيد، `D-121`)؛ أعمدة `D-110` القديمة باقية ولا تُكتب · **`receipt_code` فريد** `FP-XXXX-XXXX` لكل نسخة (`ReceiptCodes`، `D-122`) |
| `resources` | `download_count` |
| `journey_steps` | `index` 1..10 · `unlock_rule` |
| `user_journey_states` | `(user_id, journey_step_id)` فريد |
| `threads` · `thread_participants` · `messages` | `last_read_at` · `threads.cohort_id` يقبل الفراغ لمحادثة `direct` · `threads.inbox` (`system_admin` = الصندوق المشترك) · `threads.pair_key` فريد — محادثة واحدة لكل زوجين (`D-118`) |
| `support_tickets` | `number` **فريد** `TK-XXXX-XXXX` · `opener_id` (`restrictOnDelete`) · `cohort_id` يقبل الفراغ (`nullOnDelete`) · `category` · `subject` · `status` · `level` · `assignee_id` المنسّق الذي عنده التذكرة (`nullOnDelete`) · `reached_system_admin_at` · `resolved_at` · `closed_at` · `closed_by` · `last_activity_at` — الكاتب الوحيد `TicketWorkflow` (`D-124`) · التذاكر اليتيمة: `SupportTicket::scopeOrphaned` — سؤال `TicketRouting::holderCanAct` نفسه مطروحًا على قاعدة البيانات، يقرؤه نقل التذاكر (`TicketWorkflow::rehome`) |
| `support_ticket_entries` | `support_ticket_id` (`cascadeOnDelete`) · `position` ترتيب السطر في خطّها (فريد مع التذكرة) · `actor_id` يقبل الفراغ للنظام (`nullOnDelete`) · `type` · `body` · `is_internal` · `from_level` · `to_level` · `target_id` (`nullOnDelete`) · `link_url` — قيد فريد `(support_ticket_id, position)` (`D-124`) |
| `support_ticket_attachments` | `support_ticket_entry_id` (`cascadeOnDelete`) · `disk` · `path` · `original_name` · `mime_type` · `size_bytes` · `checksum` · `kind` (`image`/`video`) — تُعرض برابط موقّع لا يُكشف مساره (`D-124`) |
| `notifications` | فهرس `(user_id, is_read)` |
| `notification_preferences` | |
| `certificates` | `serial_number` **فريد** · `verify_code` **فريد** · `revoked_at` |
| `digital_cards` | `qr_token` فريد وطويل وموقّع · `revoked_at` |
| `landing_settings` | `faq` JSON · `is_registration_open` |
| `audit_logs` | **لا `update` ولا `delete`** · `before`/`after` JSON |
| `email_tokens` | `token_hash` · `expires_at` · `used_at` — لمرة واحدة |
| `impersonation_sessions` | `admin_id` · `target_id` · `started_at` · `ended_at` · مدة أقصاها 30 دقيقة |

---

## 5 · الخدمة الوحيدة للوقت — `App\Services\Time\Clock`

```php
final class Clock
{
    public static function now(): CarbonImmutable;          // UTC دائمًا
    public static function riyadh(): CarbonImmutable;       // Asia/Riyadh للعرض
    public static function toRiyadh(DateTimeInterface $t): CarbonImmutable;
    public static function fake(?DateTimeInterface $at): void;   // للاختبار فقط
    public static function reset(): void;
}
```
**لا يُستدعى `now()` في أي مكان آخر. البوابة G4 تفرض ذلك.**

---

## 6 · نوافذ الحضور — `App\Services\Attendance\AttendanceWindow`

مرجع: PRD §9.9.2 + §9.9.3 · `BR-01` … `BR-09`

```php
final class AttendanceWindow
{
    public const CHECK_IN_OPENS_BEFORE_START_MINUTES = 30;   // BR-01
    public const LATE_AFTER_START_MINUTES           = 30;   // BR-02, BR-03
    public const CHECK_OUT_OPENS_BEFORE_END_MINUTES = 30;   // BR-04
    public const CHECK_OUT_CLOSES_AFTER_END_MINUTES = 30;   // BR-04

    public function checkInOpensAt(Session $s): CarbonImmutable;   // S - 30m
    public function checkInClosesAt(Session $s): CarbonImmutable;  // E
    public function checkOutOpensAt(Session $s): CarbonImmutable;  // E - 30m
    public function checkOutClosesAt(Session $s): CarbonImmutable; // E + 30m

    public function canCheckIn(Session $s, CarbonImmutable $at): bool;
    public function canCheckOut(Session $s, CarbonImmutable $at): bool;
    public function classify(Session $s, CarbonImmutable $at): AttendanceStatus; // present | late
}
```

**الحدود — تُختبر عند ±1 ثانية (المادة 20):**

| اللحظة | تسجيل الحضور | التصنيف | تسجيل الانصراف |
|---|---|---|---|
| `S-30m-1s` | ✗ مغلق | — | ✗ |
| `S-30m` | ✓ مفتوح | `present` | ✗ |
| `S+30m` | ✓ | `present` (آخر لحظة) | ✗ |
| `S+30m+1s` | ✓ | `late` | ✗ |
| `E-30m` | ✓ | `late` | ✓ يفتح |
| `E` | ✓ آخر لحظة | `late` | ✓ |
| `E+1s` | ✗ مغلق | — | ✓ |
| `E+30m` | ✗ | — | ✓ آخر لحظة |
| `E+30m+1s` | ✗ | — | ✗ |

**قواعد إضافية مفروضة على الخادم:**
- `BR-05` لا انصراف بلا حضور سابق لنفس الجلسة.
- `BR-06` لا حضور مكرر — يُفرض بقيد فريد `(session_id, user_id)` في قاعدة البيانات، لا بفحص تطبيقي وحده.
- الجلسة الملغاة `cancelled` → يُرفض التسجيل دائمًا.
- يُخزَّن `ip_address` و `user_agent` مع كل تسجيل.

---

## 7 · الدرجات — `App\Services\Grading\ScoreCalculator`

مرجع: PRD §9.15 · `BR-11` … `BR-14`

```php
final class ScoreCalculator
{
    public const ASSIGNMENTS_TOTAL = 50;   // BR-11
    public const PROJECT_TOTAL     = 50;   // BR-11
    public const GRAND_TOTAL       = 100;  // BR-11

    public function assignmentsScore(User $u, Cohort $c): float;  // ≤ 50
    public function projectScore(User $u, Cohort $c): float;      // ≤ 50
    public function finalScore(User $u, Cohort $c): float;        // ≤ 100
    public function passes(User $u, Cohort $c): bool;             // ≥ cohort.pass_score
}
```
- `BR-12` الدرجة لا تتجاوز `max_score` ولا تقل عن صفر — يُفرض في `FormRequest` **و** في قيد قاعدة البيانات.
- `BR-13` الملاحظة إلزامية ولا تقل عن **10 أحرف** عند رصد أي درجة.
- `BR-14` تعديل درجة مرصودة يتطلب `revision_reason`، ويُسجَّل في `audit_logs`، ويُشعر المتدرب.
- تُدعم الدرجات العشرية (`decimal(5,2)`).
- إن لم يساوِ مجموع `max_score` للمهام 50 بالضبط → **تحذير للمدرب في لوحته، ولا يُمنع الاستمرار**.

---

## 8 · الشهادة — `App\Services\Certificates\CertificateEligibility`

مرجع: PRD §9.17 · `BR-26`

```php
final class CertificateEligibility
{
    public function attendanceRate(User $u, Cohort $c): float;   // %
    public function meetsAttendance(User $u, Cohort $c): bool;   // ≥ cohort.min_attendance_rate
    public function meetsScore(User $u, Cohort $c): bool;        // ≥ cohort.pass_score
    public function isEligible(User $u, Cohort $c): bool;        // الشرطان معًا — لا تعويض
    public function reasons(User $u, Cohort $c): array;          // أسباب عدم الاستحقاق بالعربية
}
```
- **الشرطان يُطبَّقان معًا. اجتياز أحدهما لا يُعوّض الآخر.**
- صيغة الرقم التسلسلي: **`ATHAR-AI101-2026-0001`** (PRD §9.17).
- `verify_code` عشوائي طويل وموقّع — **ليس** معرّف المستخدم.
- تجاوز يدوي من المدير ممكن **مع تسجيل السبب** في `audit_logs`.
- صفحة `/certificate/verify/{code}` عامة، تعرض: الاسم الأول واسم العائلة · البرنامج · الدفعة · التاريخ · الحالة. **لا شيء غير ذلك** (BR-25).

---

## 9 · الرحلة — `App\Services\Journey\JourneyEvaluator`

مرجع: PRD §9.7 · `BR-20`, `BR-21`

الخطوات العشر بترتيبها وشرط اكتمالها:

| # | الخطوة | شرط الاكتمال |
|---|---|---|
| 1 | التسجيل في البرنامج | مكتملة تلقائيًا فور تفعيل الحساب والالتحاق (BR-20) |
| 2 | حضور اللقاء التعريفي | سجل حضور `present` أو `late` لجلسة `intro` |
| 3 | الأسبوع الأول | حضور جلسات الأسبوع + تسليم كل المهام **الإجبارية** للأسبوع |
| 4 | الأسبوع الثاني | نفس الشرط |
| 5 | الأسبوع الثالث | نفس الشرط |
| 6 | الأسبوع الرابع | نفس الشرط |
| 7 | تسليم المشروع النهائي | وجود `project_submission` |
| 8 | تقييم المشروع | وجود `evaluation` من نوع `final_project` |
| 9 | الحفل الختامي | حضور جلسة `closing` |
| 10 | الشهادة | إصدار الشهادة بعد استيفاء الشرطين |

**`BR-21` تكتمل آليًا من البيانات الفعلية. لا يوجد تعليم يدوي للمتدرب إطلاقًا.**

### 9.1 · مفردات `journey_steps.unlock_rule` — قائمة مغلقة

القيم المعتمدة هي **ثوابت `JourneyEvaluator::RULE_*` حصرًا**، ولا تُكتب نصًّا حرفيًّا في متحكّم ولا بذر ولا اختبار.
أي قيمة خارج هذه القائمة تعني خطوة لا يعرف المقيِّم كيف يحسمها، فتُعامل **مقفلة** (المادة 7).

| القيمة | الثابت | شرط الاكتمال | العمود المصاحب |
|---|---|---|---|
| `enrollment` | `RULE_ENROLLMENT` | التحاق فعّال بالدفعة (BR-20) | — |
| `intro_attendance` | `RULE_INTRO_ATTENDANCE` | حضور `present` أو `late` لجلسة `intro` | — |
| `week_completion` | `RULE_WEEK_COMPLETION` | حضور جلسات الأسبوع + تسليم كل مهامه الإجبارية | `related_entity_type='week'` · `related_entity_id` |
| `project_submission` | `RULE_PROJECT_SUBMISSION` | وجود `project_submission` | — |
| `project_evaluation` | `RULE_PROJECT_EVALUATION` | وجود `evaluation` من نوع `final_project` | — |
| `closing_attendance` | `RULE_CLOSING_ATTENDANCE` | حضور جلسة `closing` | — |
| `certificate_issued` | `RULE_CERTIFICATE_ISSUED` | شهادة مُصدَرة غير ملغاة | — |

`week_completion` هي القاعدة الوحيدة التي تلزمها إشارة إلى كيان: أربع خطوات تحمل القاعدة نفسها وتختلف بـ
`related_entity_id`. البقية تُحسم من الدفعة والمستخدم وحدهما.

---

## 10 · المسارات — أسماء لارافيل

| المسار | الاسم | الوسيط |
|---|---|---|
| `GET /` | `home` | — |
| `GET/POST /register` | `register` | `guest` · `throttle:register` |
| `GET /verify-email/{token}` | `verify-email` | — |
| `GET/POST /login` | `login` | `guest` · `throttle:login` |
| `POST /logout` | `logout` | `auth` |
| `GET/POST /forgot-password` | `password.request` | `guest` · `throttle:password` |
| `GET/POST /reset-password/{token}` | `password.reset` | `guest` |
| `GET /verify/{token}` | `card.verify` | — |
| `GET /certificate/verify/{code}` | `certificate.verify` | — |
| `GET /terms` · `/privacy` | `terms` · `privacy` | — |
| `GET /dashboard` | `dashboard` | `auth` · `verified` — يوجّه `system_admin` إلى `admin.users.index` (`D-117`) |
| `POST /dashboard/cohort` | `cohort.switch` | `auth` · `verified` · `role:participant,trainer,coordinator,admin` (`D-117`) |
| `GET /admin/roles` | `admin.roles.index` | `auth` · `verified` · `role:system_admin` · السياسة `console.roles` — **صفحة شرح الأدوار، قراءة فقط** (`D-133`) |
| `GET /dashboard/card` | `participant.card` | `auth` · `role:participant` |
| `GET /dashboard/journey` | `participant.journey` | `auth` · `role:participant` |
| `GET /dashboard/schedule` | `schedule` | `auth` · `role:participant,trainer,coordinator,admin` (`D-117`) |
| `GET /dashboard/attendance` | `attendance.index` | `auth` · `role:participant,trainer,coordinator,admin` (`D-117`) |
| `POST /dashboard/attendance/{session}/check-in` | `attendance.checkIn` | `auth` · `role:participant` · `not.impersonating` · `throttle:attendance` |
| `POST /dashboard/attendance/{session}/check-out` | `attendance.checkOut` | نفسه |
| `GET /dashboard/live` | `live` | `auth` · `role:participant,trainer,coordinator,admin` (`D-117`) |
| `GET /dashboard/assignments` | `assignments.index` | `auth` · `role:participant,trainer,coordinator,admin` (`D-117`) |
| `GET /dashboard/assignments/{assignment}` | `assignments.show` | `auth` · `role:participant,trainer,coordinator,admin` (`D-117`) |
| `POST /dashboard/assignments/{assignment}/submit` | `assignments.submit` | `auth` · `role:participant` · `not.impersonating` |
| `GET /dashboard/resources` | `resources.index` | `auth` · `role:participant,trainer,coordinator,admin` (`D-117`) |
| `GET /dashboard/messages` | `messages.index` | `auth` · `role:participant,trainer,coordinator,admin,system_admin` (`D-118`) — ما يظهر: `Thread::scopeVisibleTo` |
| `GET /dashboard/messages/new` | `messages.create` | `auth` · الأدوار نفسها · `not.impersonating` (`D-118`) — القائمة: `ConversationRules::recipients` |
| `POST /dashboard/messages` | `messages.start` | `auth` · الأدوار نفسها · `not.impersonating` · `throttle:messages` (`D-118`) — `StartConversationRequest` + `ThreadPolicy::start/startInbox` |
| `GET /dashboard/support` | `support.index` | `auth` · `verified` · `role:participant,coordinator,admin,system_admin` (`D-124`) — المتدرب تذاكره، والفريق ما يراه: `SupportTicket::scopeVisibleTo` · **أثناء المعاينة 403** (`SupportTicketPolicy::viewAny`، `D-125` مفتوح) |
| `GET /dashboard/support/new` · `POST /dashboard/support` | `support.create` · `support.store` | `auth` · `verified` · `role:participant` · `not.impersonating` · والحفظ `throttle:support` (`D-124`) — `OpenTicketRequest` + `SupportTicketPolicy::create`: حساب فعّال له التحاق متدرب «فعّال» أو «مكتمل» في دفعة |
| `GET /dashboard/support/{ticket}` | `support.show` | `auth` · `verified` · `role:participant,coordinator,admin,system_admin` (`D-124`) — `SupportTicketPolicy::view` · **أثناء المعاينة 403** (`D-125` مفتوح) |
| `POST /dashboard/support/{ticket}/reply` · `…/close` | `support.reply` · `support.close` | `auth` · `verified` · أدوار المجموعة (`role:participant,coordinator,admin,system_admin`) **بلا `role:participant`** · `not.impersonating` · `throttle:support` (`D-124`) — `SupportTicketPolicy::reply/close`: صاحب التذكرة وحده، أيًّا كان دوره الآن |
| `POST /dashboard/support/{ticket}/note` · `…/resolve` | `support.note` · `support.resolve` | `auth` · `verified` · `role:coordinator,admin,system_admin` · `not.impersonating` · `throttle:support` (`D-124`) — `SupportTicketPolicy::note/resolve`؛ والكتابة للمتدرب `SupportTicketPolicy::writeToParticipant` للمنسّق الذي عنده التذكرة في درجة المنسّق وحده، وما يُكتب في الدرجتين فوقها يُحفظ داخليًّا (`D-126` مفتوح) |
| `POST /dashboard/support/{ticket}/escalate` · `…/return` · `…/assign` | `support.escalate` · `support.return` · `support.assign` | `auth` · `verified` · `role:coordinator,admin,system_admin` · `not.impersonating` · `throttle:support` (`D-124`) — `SupportTicketPolicy::escalate/returnDown/assign` |
| `GET /files/support-attachments/{attachment}` | `files.supportAttachment` | `auth` · `verified` · `signed` — `SupportTicketPolicy::viewAttachment` عند الوصول؛ يُعرض داخل الصفحة ويدعم التشغيل المتقطّع (`D-124`) |
| `GET /dashboard/final-project` | `finalProject` | `auth` · `role:participant` |
| `GET /dashboard/final-project/receipt/{code}` | `finalProject.receipt` | `auth` · `verified` · `role:participant,trainer,coordinator,admin` — `ProjectSubmissionPolicy::view` (صاحب التسليم · مدرب دفعته · المشرف العام)، والرمز بنمط `ReceiptCodes::PATTERN` (`D-122`) |
| `GET /dashboard/grades` | `grades` | `auth` · `role:participant` |
| `GET /dashboard/certificate` | `certificate` | `auth` · `role:participant` |
| `GET /dashboard/profile` | `profile` | `auth` |
| `GET /dashboard/notifications` | `notifications` | `auth` |
| `/trainer/*` | `trainer.*` | `auth` · `role:trainer,admin` · `cohort.scope` |
| `/admin/*` | `admin.*` | `auth` · `role:admin` — عدا الأربعة التالية (`D-117`) |
| `/admin/users*` | `admin.users.*` | `auth` · `role:system_admin` (`D-117`) — `admin.users.export` يرفضه `UserPolicy::export()` للجميع |
| `/admin/landing*` | `admin.landing.*` | `auth` · `role:system_admin` (`D-117`) — لا يحمل مفتاح التسجيل |
| `/admin/settings*` | `admin.settings.*` | `auth` · `role:system_admin` · الكتابة `not.impersonating` (`D-117`) — `console.settings`. **الشاشة نفسها للقراءة فقط** (`D-148`: لا `settings.update` ولا `settings.notifications`)؛ الكتابة الوحيدة فيها قوالب البريد (`settings.template.*`، `D-136`) |
| `PUT /admin/registrations/intake/{cohort}` | `admin.registrations.intake` | `auth` · `role:admin` · `not.impersonating` (`D-117`) — فتح التسجيل وإغلاقه، `CohortPolicy::manageRegistrations` |
| `POST /admin/users/{user}/preview` | `admin.users.preview` | `auth` · `role:system_admin` · `not.impersonating` (`D-117`) |
| `POST /admin/final-project/{project}/fields` | `admin.finalProject.fields.store` | `auth` · `role:admin` · `not.impersonating` (`D-121`) — `SaveFinalProjectFieldRequest` + `FinalProjectPolicy::update` |
| `PATCH /admin/final-project/{project}/fields/{field}` · `…/move` · `DELETE …` | `admin.finalProject.fields.update` · `.move` · `.destroy` | نفسه · ربط الحقل مقيَّد بمشروعه (`scopeBindings`) · `FinalProjectFieldPolicy` (`D-121`) |
| `GET /files/project-submissions/{projectSubmission}/answers/{answer}/{index}` | `files.projectSubmissionAnswer` | `auth` · `verified` · `signed` — `ProjectSubmissionPolicy::download` عند الوصول (`D-80` · `D-121`) |
| `POST /admin/cohorts/{cohort}/participants` | `admin.cohorts.participants.attach` | `auth` · `role:admin` · `not.impersonating` (`D-84` · `D-117`) |
| `PUT /admin/cohorts/{cohort}/primary-coordinator` | `admin.cohorts.coordinators.primary` | `auth` · `role:admin` · `not.impersonating` (`D-124`) — `SetPrimaryCoordinatorRequest` + `CohortPolicy::assignCoordinator` |
| `DELETE /admin/impersonation` | `admin.impersonation.stop` | `auth` |

**حدّ الطلب `throttle:support`** (`RouteServiceProvider`، `D-124`) — على مسارات الكتابة الثمانية في التذاكر (`support.store` · `reply` · `close` · `note` · `resolve` · `escalate` · `return` · `assign`):
السطر النصي **30 في الدقيقة** بمفتاح `lines|user:<id>`، والطلب الذي يحمل `attachments` **20 في الساعة** بمفتاح `files|user:<id>` — عدّادان منفصلان للحساب،
فلا تُحسب ملاحظات المنسّق النصية على حدّ الملفات، ولا يُحسب شيء منها على `throttle:upload` في بقية المنصة.

**حدّ الطلب `throttle:password-admin`** (`RouteServiceProvider`، `D-151`) — على مسارَي مدير النظام `admin.users.resetPassword` و`admin.users.resendVerification` وحدهما:
**3 في الساعة** بمفتاح `user:<المشرف>|target:<الحساب المستهدف>`، يتقاسمه الإجراءان لكل شخص، ولا يقرأ جسم الطلب (حقل `email` مخفي لا يغيّره). المسارات العامة
(`forgot-password` · `reset-password` · `invitation` · `verify-email/resend`) تبقى على `throttle:password` بمفتاح البريد.

**النموذج المتأخر عن حال التذكرة** (`ValidatesTicketInput::failedAuthorization`، `D-124`): نماذج صفحة التذكرة ترسل `seen` = `TicketWorkflow::formStamp()`
(بصمة الدرجة والمرحلة ومن عنده التذكرة وانتهاء مهلتها، مفتاحها مفتاح التطبيق). إن رفضت السياسة الطلب وختمه غير ختم التذكرة الآن وصاحبه يقرؤها:
سطر `access.denied` بسبب `support.stale_form`، ثم رجوع إلى `support.show` برسالة `support.errors.closed` أو `moved_on` ونصّه محفوظ. وبلا ختم، أو ممن لا يقرؤها: 403 كأي مسار.
**رابط ملف موقّع منتهٍ أو مُعدَّل** على `files.*`: 403 بصفحة `errors.file-link` («انتهت صلاحية رابط الملف» وما العمل)، ويُكتب `access.denied` بسبب `signature.invalid` مع عنوان IP (`bootstrap/app.php`).

---


**قواعد القائمة الجانبية (`D-127` · `D-133`):** كل عنصر في `Sidebar` يحمل `route` وقد يحمل `also` (صفحات تحته تُضيئه). أي مسار GET جديد تحت اسم عنصر يجب أن يُدرَج في `also` أو يُصنَّف «ليس صفحة» في `NavigationOverhaulTest::NOT_A_SHELL_PAGE`، وإلا فشل الاختبار. والاسم الواحد للمفهوم الواحد في كل الأدوار: «التواصل الداخلي» · «رصد الحضور» · «الدعم الفني» · «التسليمات والتصحيح» · «متدرب».

## 11 · مصفوفة الانتقال من Cloudflare إلى لارافيل

| كان (وثائق التحليل) | صار (ملزم) |
|---|---|
| Cloudflare D1 + Drizzle | **MySQL 8 + Eloquent** |
| Workers KV | `cache` على `database` |
| R2 | القرص المحلي **خارج جذر الويب** + رابط موقّع مؤقت (15 دقيقة) |
| Cloudflare Queues | طابور `database` + `queue:work --stop-when-empty` من كرون |
| Cron Triggers | `schedule:run` كل دقيقة من كرون cPanel |
| Workers Rate Limiting | `RateLimiter` في لارافيل |
| Wrangler Secrets | `.env` خارج جذر الويب · `chmod 600` |
| OpenNext / Next.js | Blade + Vite |
| Auth.js | جلسات لارافيل (`database`) |
| Resend / Brevo API | **SMTP عبر مزوّد معاملاتي** — يُحسم في `D-02` |
| Turnstile | **يُحسم في `D-03`** |
| Sentry | سجلّ لارافيل + `D-04` |

---

## 12 · تسمية الملفات والقوالب

```
resources/views/layouts/public.blade.php
resources/views/layouts/app.blade.php          لوحة التحكم
resources/views/layouts/auth.blade.php
resources/views/layouts/bare.blade.php         صفحات التحقق العامة

resources/views/components/ui/*.blade.php      button, input, card, badge, pill,
                                               modal, drawer, toast, skeleton,
                                               empty-state, progress-bar, timeline,
                                               avatar, file-uploader, countdown,
                                               tooltip, pagination, search-input, stat-card
resources/views/components/layout/*.blade.php  header, sidebar, footer, impersonation-bar
```

**كل مكوّن `ui` يقبل `:variant` و `:size` و `:state` ولا يحوي نصًّا عربيًا.**

---

## 13 · ملفات اللغة

```
lang/ar/{app,auth,validation,nav,attendance,assignments,grades,certificates,
         journey,messages,notifications,admin,errors,emails,enums,support}.php
lang/en/…   نفس المفاتيح
```
`ar` هي المرجع. **تطابق المفاتيح بين `ar` و`en` في الاتجاهين يمنع الدمج** عبر `tests/Feature/LangKeyParityTest.php` تحت G7؛ وبوابة G5 ما زالت تُنبّه به (D-78). السبب: اللغة الاحتياطية عربية، فمفتاح إنجليزي ناقص يظهر عربيًّا داخل صفحة من اليسار يوم تُفعَّل الإنجليزية — ولا يفشل شيء.

---

## 14 · الاختبارات

```
tests/Unit/Services/…        منطق خالص — 100% تغطية إلزامية (G8)
tests/Feature/Authorization/ اختبارات 403 لكل مسار محمي (G9)
tests/Feature/BusinessRules/ اختبار لكل BR-01..36 (G10)
tests/Feature/Screens/       الحالات الأربع لكل شاشة (المادة 17)
```
اسم كل اختبار قاعدة عمل يبدأ بمعرّفها: `it('BR-01: …')`.

---

## 15 · أوامر البوابات

```
php artisan gate:forbidden      G3
php artisan gate:clock          G4
php artisan gate:i18n           G5
php artisan gate:tokens         G6
php artisan gate:br             G10
php artisan gate:traceability   G12
composer run gates              الكل بالترتيب
```

---

## 16 · طبقة العرض — `App\Presenters`

> **القاعدة:** Blade لا يحسب شيئًا. المتحكّم لا يمرّر نماذج Eloquent خامًا إلى القالب.
> بينهما **مقدِّم** (`Presenter`) يبني نموذج عرض واحدًا للشاشة، ويُقرِّر على الخادم كل ما ستطبعه الشاشة.

```
app/Presenters/<النطاق>/<الشاشة>Presenter.php
```

كل نموذج عرض يرث `App\Support\ViewModel`، وهو غلاف مصفوفة يمنح:

| السلوك | التفصيل |
|---|---|
| قراءة الخصائص | `$vm->rateVariant` — عبر `__get` |
| مُشتقّات محسوبة | تعريف `getXxx(): mixed` يجعل `$vm->xxx` متاحًا بلا تخزين |
| **قراءة فقط** | `__set` يرمي `BadMethodCallException` — القالب لا يقرّر قيمة |
| **الفشل الصاخب** | خاصية غير منشورة ترمي `OutOfBoundsException` وتذكر ما هو منشور فعلًا |
| التسلسل | `Arrayable` · `ArrayAccess` · `JsonSerializable` |

**ما يُقرَّر في المقدِّم لا في القالب — بلا استثناء:**
- **المتغيّر البصري** (`variant`): مثال BR-26 و PRD §9.9.6 — نسبة حضور فوق **85** → `success`،
  ومن **70** إلى **85** → `warning` (برتقالي محروق `#C97A17`)، ودون **70** → `danger`.
  والعتبة المرجعية للنجاح هي `cohorts.min_attendance_rate` لا رقم مكتوب في القالب.
- **اسم الأيقونة** (§17) · **مفتاح الترجمة** المُمرَّر إلى `__()` · **صيغة التاريخ والوقت** عبر `App\Support\Dates`
  (`longDate` · `shortDate` · `time` · `dateTime` · `timeRange` · `shortRange` · `relative` · `isoUtc`،
  وكلها تقبل `null` وتُرجع `Dates::ABSENT` = `—`).
- **الحالة الفارغة والحالة الخاطئة**: `isEmpty` و`hasError` أعلام يبنيها المقدِّم، لا شروط في Blade.

**ممنوع:** لون أو مسافة أو خط داخل Blade (المادة 13 بند 4) · مقارنة عتبة داخل Blade · `new Date`/`now()` في أي طبقة
غير `Clock` · نصّ عربي في المقدِّم (المادة 15).

---

## 17 · تسمية الأيقونات

مصدر الأيقونات ملف واحد: `resources/views/partials/icon-sprite.blade.php`، ومعرّف كل رمز فيه **مسبوق بـ `i-`**
(`i-warn` · `i-check` · `i-clock` · `i-user` …). **لا نسخة ثانية** — كل الأغلفة (`app` · `auth` · `bare` · `public`)
تضمّنه، وقد كان في المستودع ملفان يتباعدان (`D-127`).

- **العائلة واحدة: Lucide** (رخصة ISC؛ أجزاء منها Feather/MIT — نصّ الإسناد في رأس الملف)، مخطَّطة على شبكة 24×24.
  الرموز المملوءة الوحيدة علامات العلامة والمنصّات: `i-wa` · `i-zoom` · `i-meet` · `i-teams` وشعار الهوية.
- **سماكة الخط واحدة** وعلى **رمز واحد**: `--bw-glyph` في `tokens.css` (1.75)، تُطبَّقها قاعدة `use { stroke-width }`
  في `components.css`. **لا رمز يحمل `stroke-width` خاصًّا به** — تغيير الرمز يحرّك كل الأيقونات. الاستثناء الوحيد
  علامة الصحّ داخل خانة الاختيار (`--bw-glyph-mark`).
- **المعرّف مفهوم لا اسم Lucide:** `i-attendance` هو مفهوم الحضور، يُرسم اليوم كـ `user-check`.
- **خريطة «مفهوم ← معرّف» واحدة:** `App\Support\Icons` (ثوابت بمعرّفات **مجرّدة**). القوائم والعارضات تستعملها ولا
  تكتب معرّفًا حرفيًّا حيث يوجد ثابت. **لا مفهومان بلا فرق يتشاركان رسمًا:** كان «الخطأ» و«التحذير» مثلثًا واحدًا،
  و«لوحة المعلومات» و«طيّ القائمة» لوحةً واحدة، و«الإعدادات» قفلًا — `IconSpriteContractTest` يفشل إن عاد شيء من هذا.
- **الملف يُعدَّل يدويًّا — لا مولّد** (قرار صاحب المنتج، 28 سبتمبر 2026): خمس خطوات في رأس الملف نفسه (رسم من lucide.dev
  بلا `stroke-width` ولا لون · `<g class="rtl-mirror">` للسهم المرسوم باتجاه LTR · ثابت في `Icons` · تشغيل
  `IconSpriteContractTest`). الاختبار هو الحارس، لا ذاكرة أحد.
- **معرّف مذكور ولا يُرسم** يظهر مربعًا فارغًا ولا يرمي شيئًا؛ الاختبار نفسه يفحص كل إشارة ثابتة في العروض والمقدِّمات.
- **اتجاه الأسهم في RTL يُحسَم في السبرايت لا عند المستدعي:** الرموز المرسومة باتجاه LTR (`i-login` · `i-logout` ·
  `i-send` · `i-undo` · `i-external`) داخلها `<g class="rtl-mirror">`، وقاعدة واحدة في `components.css` تقلبها تحت
  `dir="rtl"`. فلا يحتاج مستدعٍ إلى `directional` ولا يستطيع نسيانه. `i-chev` مرسوم أصلًا باتجاه RTL.
- **الرموز الصغيرة (16px فأقل) أثقل خطًّا:** `stroke-width` بوحدات الشبكة (24)، فيبدو 1.75 على 16px نحو 1.2px؛
  `--bw-glyph-sm` (2.25) لهذه الأحجام و`--bw-glyph-mark` لما دونها.

المكوّن `<x-ui.icon :name="…" />` **يُطبّع الاسم**: يقبل `warn` و`i-warn` معًا ويصل في الحالتين إلى `#i-warn`.
فالقاعدة للكاتب:

- في السبرايت: المعرّف **دائمًا** `i-<name>`.
- في المقدِّم أو القالب: يُمرَّر **الاسم المجرّد** (`warn`) تفضيلًا؛ الاسم المسبوق مقبول ولا يُكسر شيئًا.
- **مكان واحد فقط يعرف بالبادئة**: `resources/views/components/ui/icon.blade.php`. لا يُكرَّر التطبيع في أي مكان آخر.
- أيقونة تحمل معنى بذاتها تُمرَّر معها `:label` (المادة 18)؛ الأيقونة الزخرفية تبقى `aria-hidden`.
