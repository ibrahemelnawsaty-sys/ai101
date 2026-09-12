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
App\Presenters\*         نماذج عرض الشاشات (ViewModel لكل شاشة) — §16
App\Support\*            أدوات صغيرة بلا حالة
App\Console\Commands\*   أوامر artisan والبوابات
```

---

## 3 · الأنواع المحصورة `App\Enums`

| Enum | القيم |
|---|---|
| `UserRole` | `admin` · `trainer` · `participant` |
| `UserStatus` | `pending` · `active` · `suspended` · `deleted` |
| `Gender` | `male` · `female` |
| `ProgramStatus` | `draft` · `published` · `archived` |
| `CohortStatus` | `upcoming` · `open` · `running` · `completed` |
| `EnrollmentStatus` | `pending` · `active` · `withdrawn` · `completed` |
| `EnrollmentRole` | `participant` · `trainer` |
| `SessionType` | `intro` · `training` · `project` · `closing` |
| `SessionStatus` | `scheduled` · `live` · `completed` · `cancelled` |
| `AttendanceStatus` | `present` · `late` · `absent` · `excused` · `incomplete` |
| `AssignmentStatus` | `draft` · `published` |
| `SubmissionStatus` | `submitted` · `under_review` · `graded` |
| `EvaluationEntity` | `assignment` · `final_project` |
| `JourneyStepStatus` | `locked` · `current` · `completed` |
| `ThreadType` | `trainer_dm` · `group` · `announcement` |
| `EmailTokenType` | `verify` · `reset` |
| `ResourceType` | `file` · `link` · `video` |

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
| `cohorts` | `pass_score` افتراضي `60` · `min_attendance_rate` افتراضي `75` · `capacity` · `registration_closes_at` |
| `enrollments` | **فريد مركّب** `(cohort_id, user_id)` |
| `weeks` | `index` 1..4 |
| `sessions` | فهرس `(cohort_id, date)` · قيد `end_time > start_time` · `zoom_url` لا يُكشف قبل النافذة |
| `attendances` | **فريد مركّب** `(session_id, user_id)` · `ip_address` · `user_agent` · `edit_reason` |
| `assignments` | `max_score` · `due_at` · `allow_late` · `is_mandatory` |
| `submissions` | فهرس `(assignment_id, user_id)` · `version` يزيد ولا يحذف السابق (BR-19) |
| `evaluations` | `feedback` **إلزامي ≥ 10 أحرف** · قيد `0 ≤ score ≤ max_score` · `revision_reason` |
| `final_projects` | `is_unlocked` · `unlocked_by` · `unlocked_at` |
| `project_submissions` | مثل `submissions` |
| `resources` | `download_count` |
| `journey_steps` | `index` 1..10 · `unlock_rule` |
| `user_journey_states` | `(user_id, journey_step_id)` فريد |
| `threads` · `thread_participants` · `messages` | `last_read_at` |
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
| `GET /dashboard` | `dashboard` | `auth` · `verified` |
| `POST /dashboard/cohort` | `cohort.switch` | `auth` · `verified` |
| `GET /dashboard/card` | `participant.card` | `auth` · `role:participant` |
| `GET /dashboard/journey` | `participant.journey` | `auth` · `role:participant` |
| `GET /dashboard/schedule` | `schedule` | `auth` |
| `GET /dashboard/attendance` | `attendance.index` | `auth` |
| `POST /dashboard/attendance/{session}/check-in` | `attendance.checkIn` | `auth` · `role:participant` · `not.impersonating` · `throttle:attendance` |
| `POST /dashboard/attendance/{session}/check-out` | `attendance.checkOut` | نفسه |
| `GET /dashboard/live` | `live` | `auth` |
| `GET /dashboard/assignments` | `assignments.index` | `auth` |
| `GET /dashboard/assignments/{assignment}` | `assignments.show` | `auth` |
| `POST /dashboard/assignments/{assignment}/submit` | `assignments.submit` | `auth` · `role:participant` · `not.impersonating` |
| `GET /dashboard/resources` | `resources.index` | `auth` |
| `GET /dashboard/messages` | `messages.index` | `auth` |
| `GET /dashboard/final-project` | `finalProject` | `auth` · `role:participant` |
| `GET /dashboard/grades` | `grades` | `auth` · `role:participant` |
| `GET /dashboard/certificate` | `certificate` | `auth` · `role:participant` |
| `GET /dashboard/profile` | `profile` | `auth` |
| `GET /dashboard/notifications` | `notifications` | `auth` |
| `/trainer/*` | `trainer.*` | `auth` · `role:trainer,admin` · `cohort.scope` |
| `/admin/*` | `admin.*` | `auth` · `role:admin` |
| `POST /admin/users/{user}/preview` | `admin.users.preview` | `auth` · `role:admin` · `not.impersonating` |
| `DELETE /admin/impersonation` | `admin.impersonation.stop` | `auth` |

---

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
         journey,messages,notifications,admin,errors,emails,enums}.php
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
(`i-warn` · `i-check` · `i-clock` · `i-user` …).

المكوّن `<x-ui.icon :name="…" />` **يُطبّع الاسم**: يقبل `warn` و`i-warn` معًا ويصل في الحالتين إلى `#i-warn`.
فالقاعدة للكاتب:

- في السبرايت: المعرّف **دائمًا** `i-<name>`.
- في المقدِّم أو القالب: يُمرَّر **الاسم المجرّد** (`warn`) تفضيلًا؛ الاسم المسبوق مقبول ولا يُكسر شيئًا.
- **مكان واحد فقط يعرف بالبادئة**: `resources/views/components/ui/icon.blade.php`. لا يُكرَّر التطبيع في أي مكان آخر.
- أيقونة تحمل معنى بذاتها تُمرَّر معها `:label` (المادة 18)؛ الأيقونة الزخرفية تبقى `aria-hidden`.
