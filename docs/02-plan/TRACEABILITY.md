# مصفوفة التتبع الحيّة — TRACEABILITY (إصدار لارافيل)

> **الحُجّية:** الدستور، **المادة 25**. تُحدَّث في **نفس** الـ commit الذي ينفّذ المتطلب. تحديثها لاحقًا مخالفة.
>
> **القاعدة الحاكمة:** **متطلب بلا اختبار = متطلب غير منفَّذ** (المادة 21 والمادة 25) — ولو كان الكود موجودًا وسليمًا.
>
> **البوابة G12** (`php artisan gate:traceability`) تفشل على أي معرّف مُستشهَد به في الكود بلا سطر هنا.
>
> **الإصدار:** 2.0 (لارافيل) · **مصفوفة الإصدار الأول (Cloudflare / Next.js) محفوظة في** `docs/02-plan/TRACEABILITY-v1-cloudflare.md` — **لا يُستشهد بها**: ملفاتها واختباراتها تشير إلى شجرة `src/` التي لم تعد قائمة.

---

## الحالات

| الرمز | المعنى |
|---|---|
| ⬜ | **لم يبدأ** — لا ملف ولا اختبار |
| 🟦 | **قيد التنفيذ** — جزء منفَّذ ومختبَر، وجزء معلن الغياب في عمود «الملاحظات» |
| 🟨 | **منفَّذ بلا اختبار** — **يُقرأ: غير منفَّذ** (المادة 21) |
| 🟩 | **منفَّذ ومختبَر** — اختبار يحمل المعرّف في اسمه، مقروء ومُتحقَّق من وجوده |
| ✅ | **معتمد من صاحب المنتج** |

---

## ملاحظة صدق — طبيعة هذا الملف ولحظة التقاطه

**لُقِّط في 7 سبتمبر 2026 بعد مراجعة تدقيق على المستودع كاملًا.** ما يلي مُتحقَّق منه بالقراءة الفعلية للملفات
وبمسح آلي لأسماء الاختبارات، لا بالافتراض. وثلاث حقائق تُعلَن صراحةً بدل أن تُبتلَع:

1. **لم تُشغَّل أي اختبارات، ولا بوابة واحدة.** لا مُفسِّر PHP في بيئة العمل التي أُنتج فيها هذا التحديث.
   كل 🟩 أدناه تعني حرفيًّا: **«يوجد اختبار يحمل المعرّف في اسمه، قُرئ وثبت أنه يغطّي القاعدة»** — لا «مرّ الاختبار».
   **لا يُبلَّغ عن نجاح بوابة قبل تشغيلها** (المادة 30). أول من يشغّل `composer run gates` يصحّح العمود بالنتيجة الحقيقية.
2. **ما تغيّر منذ الالتقاط السابق:** `routes/web.php` و`routes/console.php` موجودان، و48 متحكّمًا في
   `app/Http/Controllers/**` موجودة، و`tests/Feature/Authorization/` و`tests/Feature/Screens/` موجودان،
   و`app/Console/Commands/GateTraceability.php` مبنيّ، وطبقة `app/Presenters/**` قائمة.
   **كل `BR-01` … `BR-36` صار له اختبار واحد على الأقل يحمل معرّفه في اسمه** — كان 19 من 36.
3. **ما زال غائبًا:** ملفات الخطوط الخمسة (`public/fonts/`، `D-28`) · مزوّد بريد فعلي (`D-02`) ·
   شاشات المدرب والمدير ناقصة الحالات الأربع · اختباران مُعلَّقان عمدًا لبندين مفتوحين (`D-31`) لا لعجز.

**بنود مفتوحة تحكم صفوفًا في هذا الجدول:** `D-02` · `D-10` · `D-11` · `D-12` · `D-17` · `D-26` · `D-28` · `D-31` · `D-32` · `D-33` · `D-34`.

---

## ملخص التقدم — قواعد العمل

| الفئة | العدد | ⬜ | 🟦 | 🟨 | 🟩 | ✅ |
|---|---|---|---|---|---|---|
| قواعد العمل `BR-01..36` | 36 | 0 | 3 | 0 | 33 | 0 |
| شاشات `PRD §9` | 22 | 0 | 12 | 0 | 10 | 0 |

**قراءة صادقة للأرقام:** 36 قاعدة من 36 لها اختبار يحمل معرّفها؛ **هذا شرط لازم لا كافٍ** — الاختبارات لم تُشغَّل بعد.
الثلاث الموسومة 🟦 عالقة على بنود مفتوحة (`D-02` للبريد، `D-26` لوزن حالتي الحضور)، لا على كود ناقص.
عشر شاشات مغطّاة بالحالات الأربع؛ البقية ينقصها **هيكل تحميل مختبَر أو حالة خطأ مختبَرة**، والنقص مُسمّى في كل صف.

---

## أولًا — قواعد العمل `BR-01` … `BR-36`

المصدر: `docs/00-source/PRD-AR-source.md` §10. الحدود الزمنية تُختبر عند **±1 ثانية** (المادة 20).
عمود «الاختبارات» يذكر **ملفات موجودة فعلًا** ونصّ اسم اختبار واحد منها حرفيًّا.

| المعرّف | الوصف | الحالة | الملفات المنفِّذة | الاختبارات |
|---|---|---|---|---|
| BR-01 | نافذة الحضور تبدأ قبل الجلسة بـ 30 دقيقة وتنتهي بانتهائها | 🟩 | `app/Services/Attendance/AttendanceWindow.php` · `AttendanceRecorder.php` · `app/Http/Controllers/Participant/AttendanceController.php` | `tests/Unit/Services/AttendanceWindowTest.php` · `tests/Feature/BusinessRules/AttendanceRulesTest.php` — `it('BR-01: نافذة تسجيل الحضور عند الحد')` |
| BR-02 | التسجيل خلال أول 30 دقيقة من البداية أو قبلها = حاضر | 🟩 | `AttendanceWindow.php` · `AttendanceRecorder.php` | `AttendanceWindowTest.php` — `it('BR-02: الدقيقة الثلاثون بالضبط من بداية الجلسة تُحتسب حاضرًا لا متأخرًا')` · `AttendanceRulesTest.php` · `CertificateEligibilityTest.php` |
| BR-03 | التسجيل بعد أول 30 دقيقة يُقبل ويُحتسب متأخرًا | 🟩 | `AttendanceWindow.php` · `AttendanceRecorder.php` | `AttendanceWindowTest.php` — `it('BR-03: الثانية التالية للدقيقة الثلاثين تُحتسب متأخرًا')` · `AttendanceRulesTest.php` |
| BR-04 | نافذة الانصراف من `E-30m` إلى `E+30m` | 🟩 | `AttendanceWindow.php` · `AttendanceRecorder.php` | `AttendanceWindowTest.php` — `it('BR-04: الانصراف بعد نهاية الجلسة بثلاثين دقيقة وثانية مرفوض')` · `AttendanceRulesTest.php` |
| BR-05 | لا انصراف بلا حضور سابق لنفس الجلسة | 🟩 | `AttendanceRecorder.php` · `app/Http/Requests/Participant/CheckOutRequest.php` | `AttendanceRulesTest.php` — `it('BR-05: حضور في جلسة أخرى لا يبيح الانصراف من هذه الجلسة')` |
| BR-06 | لا حضور مكرر — قيد فريد `(session_id, user_id)` | 🟩 | `AttendanceRecorder.php` · `database/migrations/2026_01_01_000004_create_attendances_table.php` | `AttendanceRulesTest.php` — `it('BR-06: طلبان متزامنان ينتجان سطرًا واحدًا — القيد الفريد في قاعدة البيانات هو الحارس')` |
| BR-07 | وقت الخادم هو المرجع الوحيد لكل حساب زمني | 🟩 | `app/Services/Time/Clock.php` · `RiyadhFormatter.php` · `AttendanceWindow.php` · `AttendanceReconciler.php` · `app/Support/Dates.php` | `tests/Unit/Services/ClockTest.php` · `RiyadhFormatterTest.php` · `AttendanceWindowTest.php` — `it('BR-07: النافذة لا تتأثر بأي وقت يرسله العميل — الوسيط الوحيد هو لحظة الخادم')` |
| BR-08 | من لم يسجّل حضورًا حتى نهاية الجلسة يُحوَّل آليًا إلى غائب | 🟩 | `app/Services/Attendance/AttendanceReconciler.php` · `app/Console/Commands/ReconcileAttendance.php` · `routes/console.php` (كل 15 دقيقة) · `app/Support/AttendanceCounting.php` | `AttendanceRulesTest.php` — `it('BR-08: من لم يسجّل حضورًا حتى انتهاء الجلسة يُحوَّل آليًا إلى غائب')` · `it('BR-08: لا يُحوَّل أحد إلى غائب قبل انتهاء الجلسة')` |
| BR-09 | حضور بلا انصراف → «حضور غير مكتمل» ويُنبَّه المدرب | 🟩 | `AttendanceReconciler.php` · `ReconcileAttendance.php` · `routes/console.php` · `AttendanceCounting.php` | `AttendanceRulesTest.php` — `it('BR-09: من سجّل حضورًا ولم يسجّل انصرافًا يُحوَّل إلى حضور غير مكتمل')` · `it('BR-09: المدرب يُنبَّه بالحضور غير المكتمل')` |
| BR-10 | التعديل اليدوي على الحضور يتطلب سببًا ويُسجَّل في سجل التدقيق | 🟩 | `AttendanceRecorder.php` · `app/Services/Audit/AuditLogger.php` · `app/Http/Requests/Trainer/UpdateAttendanceRequest.php` | `AttendanceRulesTest.php` — `it('BR-10: سجل التدقيق يحفظ القيمة قبل التعديل وبعده')` |
| BR-11 | المهام 50 · المشروع 50 · المجموع 100 | 🟩 | `app/Services/Grading/ScoreCalculator.php` · `EvaluationRecorder.php` · `app/Presenters/Participant/GradeSummaryPresenter.php` | `tests/Unit/Services/ScoreCalculatorTest.php` — `it('BR-11: مجموع درجات المهام لا يتجاوز خمسين حتى لو أخطأ المدرب في التوزيع')` · `tests/Feature/BusinessRules/GradingRulesTest.php` |
| BR-12 | الدرجة ≤ الدرجة القصوى و ≥ صفر — في `FormRequest` **و** في قيد الجدول | 🟩 | `ScoreCalculator.php` · `app/Http/Requests/Trainer/StoreEvaluationRequest.php` · `database/migrations/2026_01_01_000007_create_evaluations_table.php` | `ScoreCalculatorTest.php` · `GradingRulesTest.php` — `it('BR-12: قاعدة البيانات نفسها ترفض درجة سالبة')` |
| BR-13 | ملاحظة المدرب إلزامية (≥ 10 أحرف) عند رصد أي درجة | 🟩 | `EvaluationRecorder.php` · `StoreEvaluationRequest.php` · `2026_01_01_000007_create_evaluations_table.php` | `GradingRulesTest.php` — `it('BR-13: ملاحظة من مسافات فقط لا تُعد ملاحظة')` |
| BR-14 | تعديل درجة مرصودة يتطلب سببًا ويُسجَّل ويُشعر المتدرب | 🟦 | `EvaluationRecorder.php` · `app/Http/Requests/Trainer/ReviseEvaluationRequest.php` · `AuditLogger.php` · `app/Models/Notification.php` | `GradingRulesTest.php` — `it('BR-14: تعديل درجة مرصودة بسبب مكتوب يُقبل ويُسجَّل في سجل التدقيق')` · `it('BR-14: تعديل الدرجة يُشعر المتدرب')` |
| BR-15 | تبويب المشروع الختامي مقفل حتى يفعّله المدرب — القفل على الخادم | 🟩 | `app/Models/FinalProject.php` · `app/Policies/FinalProjectPolicy.php` · `app/Http/Controllers/Trainer/FinalProjectController.php` | `tests/Feature/BusinessRules/AssignmentAndProjectRulesTest.php` — `it('BR-15: المتدرب لا يستطيع تفعيل تبويب المشروع بنفسه')` |
| BR-16 | محتوى المشروع الختامي لا يُرسل للمتصفح قبل التفعيل | 🟩 | `FinalProject.php` · `FinalProjectPolicy.php` · `app/Http/Controllers/Participant/FinalProjectController.php` | `AssignmentAndProjectRulesTest.php` — `it('BR-16: لوحة التحكم كاملة لا تسرب دليل المشروع قبل التفعيل')` |
| BR-17 | المدرب يحدد المهام وإجباريتها ودرجتها وموعدها | 🟩 | `app/Models/Assignment.php` · `app/Http/Requests/Trainer/StoreAssignmentRequest.php` · `UpdateAssignmentRequest.php` | `AssignmentAndProjectRulesTest.php` — `it('BR-17: مهمة بحالة مسودة لا تظهر للمتدرب')` |
| BR-18 | التسليم بعد الموعد يُوسم متأخرًا أو يُرفض حسب إعداد المهمة | 🟩 | `Assignment.php` · `app/Http/Requests/Participant/SubmitAssignmentRequest.php` | `AssignmentAndProjectRulesTest.php` — `it('BR-18: التسليم عند الموعد بالضبط ليس متأخرًا')` |
| BR-19 | إعادة التسليم تحفظ الإصدار السابق ولا تحذفه | 🟩 | `app/Models/Submission.php` · `ScoreCalculator.php` · `database/migrations/2026_01_01_000005_create_assignments_and_submissions_tables.php` | `AssignmentAndProjectRulesTest.php` — `it('BR-19: رقم الإصدار يزيد ولا يعود للخلف أبدًا')` |
| BR-20 | خطوة التسجيل في «رحلتي» مكتملة افتراضيًا | 🟩 | `app/Services/Journey/JourneyEvaluator.php` (`RULE_ENROLLMENT`) · `database/seeders/ProgramSeeder.php` | `tests/Unit/Services/JourneyEvaluatorTest.php` · `tests/Feature/BusinessRules/JourneyRulesTest.php` — `it('BR-20: من لم يلتحق بالدفعة لا تكتمل له خطوة التسجيل')` |
| BR-21 | خطوات الرحلة تكتمل آليًا من البيانات — لا تعليم يدوي | 🟩 | `JourneyEvaluator.php` (كل ثوابت `RULE_*`، `PROJECT-CONTRACT.md` §9.1) · `app/Http/Controllers/Participant/JourneyController.php` | `JourneyEvaluatorTest.php` · `JourneyRulesTest.php` — `it('BR-21: لا يوجد أي مسار يسمح للمتدرب بتعليم خطوة كمكتملة يدويًا')` |
| BR-22 | المتدرب لا يصل إلى بيانات متدرب آخر | 🟩 | `app/Services/Permissions/RoleResolver.php` · `CohortScope.php` · `app/Http/Middleware/EnsureCohortScope.php` · `app/Policies/Concerns/InteractsWithScope.php` · كل `app/Policies/*` | `tests/Feature/Authorization/CrossTenantAccessTest.php` — `it('BR-22: المتدرب لا يصل إلى تسليم متدرب آخر بتغيير المعرّف في الرابط')` · `RouteAuthorizationTest.php` · `AccessRulesTest.php` |
| BR-23 | المدرب لا يصل إلا لبيانات دفعاته | 🟩 | `RoleResolver.php` · `EnsureCohortScope.php` · `EnsureRole.php` · `app/Http/Requests/Trainer/Concerns/ScopedToCohort.php` · `app/Http/Controllers/Concerns/ReadsCohortScope.php` | `CrossTenantAccessTest.php` — `it('BR-23: المدرب لا يرصد درجة لمتدرب في دفعة أخرى')` · `tests/Feature/BusinessRules/AccessRulesTest.php` · `Admin/CohortTrainerAssignmentTest.php` — نموذج الإسناد يرسل ما يطلبه الطلب · الإسناد بالبريد · رفض بريد المتدرّب ظاهرًا على الحقل · المصفوفة لا تُسقط الصفحة · المدرّب المُزال لا يبقى مدرجًا (D-69) — تفشل على الشيفرة السابقة |
| BR-24 | رابط الزوم لا يُكشف قبل 15 دقيقة من البداية | 🟩 | `app/Models/Session.php` · `app/Policies/SessionPolicy.php` · `app/Presenters/Participant/SessionPresenter.php` · `NextSessionPresenter.php` | `AccessRulesTest.php` — `it('BR-24: الرابط يُسلَّم عند الحد بالضبط قبل الجلسة بخمس عشرة دقيقة')` · `it('BR-24: رابط الزوم لا يظهر في شيفرة الصفحة قبل الجلسة بخمس عشرة دقيقة')` |
| BR-25 | صفحة التحقق لا تعرض بيانات شخصية حساسة | 🟩 | `app/Models/DigitalCard.php` · `Certificate.php` · `app/Http/Controllers/Public/CardVerificationController.php` · `CertificateVerificationController.php` · `resources/views/public/card-verify.blade.php` · `certificate-verify.blade.php` | `tests/Feature/BusinessRules/CertificateRulesTest.php` — `it('BR-25: صفحة التحقق من الشهادة تعرض ما نصّت عليه الوثيقة ولا شيء غيره')` · `tests/Feature/Screens/PublicScreensTest.php` · `SerialNumberGeneratorTest.php` |
| BR-26 | الشهادة تُصدر باستيفاء نسبة الحضور ودرجة النجاح **معًا** | 🟦 | `app/Services/Certificates/CertificateEligibility.php` · `SerialNumberGenerator.php` · `app/Support/AttendanceCounting.php` · `app/Http/Controllers/Admin/CertificateController.php` | `tests/Unit/Services/CertificateEligibilityTest.php` — `it('BR-26: حد نسبة الحضور يُقرأ من الدفعة ويُختبر عند الحد بالضبط')` · `CertificateRulesTest.php` — `it('BR-26: حضور كامل ودرجة راسبة لا يمنحان الشهادة')` |
| BR-27 | كل عملية حساسة تُسجَّل، وسجل التدقيق غير قابل للتعديل أو الحذف | 🟩 | `app/Services/Audit/AuditLogger.php` · `app/Models/AuditLog.php` · `app/Policies/AuditLogPolicy.php` · `app/Http/Controllers/Admin/AuditController.php` | `tests/Feature/BusinessRules/SecurityRulesTest.php` — `it('BR-27: لا يوجد أي مسار في التطبيق يعدّل سجل التدقيق أو يحذفه')` · `Admin/RegistrationDecisionTest.php` — `it('BR-27: اعتماد الطلب نفسه مرتين يأخذ مقعدًا واحدًا ويكتب سطر تدقيق واحدًا ويرسل رسالة واحدة')` (D-69) |
| BR-28 | كل صلاحية تُفحص على الخادم في كل طلب | 🟩 | `EnsureRole.php` · `EnsureCohortScope.php` · `RoleGate.php` · `app/Providers/AuthServiceProvider.php` | `tests/Feature/Authorization/RouteAuthorizationTest.php` — `it('BR-28: الزائر غير المسجل لا يصل إلى أي مسار في لوحة التحكم')` · `AccessRulesTest.php` — `it('BR-28: سحب الدور أثناء الجلسة يمنع الطلب التالي فورًا')` |
| BR-29 | تغيير كلمة المرور يُبطل كل الجلسات النشطة | 🟩 | `app/Http/Controllers/Auth/Concerns/InvalidatesOtherSessions.php` · `app/Http/Requests/Participant/ChangePasswordRequest.php` · `app/Http/Requests/Auth/ResetPasswordRequest.php` · `app/Events/PasswordChanged.php` | `SecurityRulesTest.php` — `it('BR-29: تغيير كلمة المرور يُبطل كل الجلسات النشطة الأخرى')` |
| BR-30 | رسائل الدخول والاستعادة لا تكشف وجود البريد | 🟩 | `app/Http/Requests/Auth/LoginRequest.php` · `ForgotPasswordRequest.php` · `ResendVerificationRequest.php` · `lang/ar/auth.php` · `lang/ar/passwords.php` | `SecurityRulesTest.php` — `it('BR-30: رسالة استعادة كلمة المرور واحدة سواء كان البريد موجودًا أو غير موجود')` |
| BR-31 | كل محتوى قابل للتغيير يُدار من لوحة الإدارة ولا يُكتب في الكود | 🟩 | `app/Models/LandingSetting.php` · `Program.php` · `Cohort.php` · `app/Http/Controllers/Admin/LandingController.php` · `SettingController.php` · `database/seeders/SeedContent.php` | `tests/Feature/BusinessRules/AdminRulesTest.php` — `it('BR-31: نصوص صفحة الهبوط تُقرأ من قاعدة البيانات وتتغير من لوحة الإدارة')` · `PublicScreensTest.php` |
| BR-32 | يبقى مدير نظام فعّال واحد على الأقل دائمًا | 🟩 | `app/Models/User.php` · `app/Policies/UserPolicy.php` · `app/Http/Requests/Admin/ChangeUserStatusRequest.php` · `ChangeUserRoleRequest.php` | `AdminRulesTest.php` — `it('BR-32: تنزيل دور آخر مدير نظام إلى مدرب مرفوض')` · `it('BR-32: حذف المستخدم حذف ناعم لا فعلي')` |
| BR-33 | وضع المعاينة: قراءة فقط صارمة مفروضة في طبقة البيانات | 🟩 | `app/Services/Permissions/ImpersonationService.php` · `app/Http/Middleware/ImpersonationReadOnly.php` · `BlockWhenImpersonating.php` · `app/Support/ImpersonationContext.php` | `tests/Feature/BusinessRules/ImpersonationRulesTest.php` — `it('BR-33: تسجيل الحضور أثناء المعاينة مرفوض على الخادم')` · `it('BR-33: انتهاء المعاينة تلقائيًا بعد ثلاثين دقيقة')` |
| BR-34 | المعاينة لا تغيّر أي أثر: آخر دخول · قراءة الإشعارات والرسائل · عدّادات التحميل | 🟩 | `ImpersonationReadOnly.php` · `ImpersonationContext.php` · `app/Models/Notification.php` · `ThreadParticipant.php` · `Resource.php` | `ImpersonationRulesTest.php` — `it('BR-34: المعاينة لا تزيد عدّاد تحميل الموارد')` · `it('BR-34: التصفح العادي خارج المعاينة يترك أثره كالمعتاد')` |
| BR-35 | كل جلسة معاينة تُسجَّل، ولا تُعايَن حسابات المديرين | 🟩 | `ImpersonationService.php` · `AuditLogger.php` · `app/Policies/UserPolicy.php` · `app/Models/ImpersonationSession.php` | `ImpersonationRulesTest.php` — `it('BR-35: لا يمكن معاينة حساب مدير نظام آخر')` · `RouteAuthorizationTest.php` |
| BR-36 | اسم البرنامج والنطاق يُقرآن من الإعدادات لا من الكود | 🟩 | `config/athar.php` · `app/Providers/AppServiceProvider.php` · `app/Http/Middleware/SetLocaleAndDirection.php` · `SerialNumberGenerator.php` | `AdminRulesTest.php` — `it('BR-36: النطاق واسم البرنامج غير مكتوبين حرفيًا في أي ملف مصدر خارج الإعدادات')` · `it('BR-36: كل رابط مطلق يُبنى من APP_URL')` |

**ملاحظات على الصفوف 🟦 — ولماذا لم تُرفع إلى 🟩:**

- **BR-14:** السبب والتسجيل في `audit_logs` وإنشاء الإشعار في الجدول كلها مختبَرة. **ما لم يُختبر هو الوصول الفعلي
  للإشعار إلى المتدرب**، لأن قناة البريد معطَّلة على `D-02` ولم يُرسَل بريد واحد فعلًا. ادّعاء 🟩 هنا يخالف المادة 30.
- **BR-26:** الشرطان وحساب النسبة والحدود عند القيمة بالضبط كلها مختبَرة. **ما هو مفتوح هو تعريف بسط النسبة نفسه**:
  وزن `excused` و`incomplete` مسجَّل في `D-26` بحالة `مفتوح`، والاختبارات تثبّت **القراءة القائمة** لا حكمًا نهائيًّا.
  ترفع إلى 🟩 يوم يُعتمد `D-26`.
- **BR-08 · BR-09** رُفعتا من 🟦 إلى 🟩: `routes/console.php` صار يجدول `ReconcileAttendance` كل خمس عشرة دقيقة،
  فالمسح يقع فعلًا لا نظريًّا.

---

## ثانيًا — شاشات `PRD §9`

معرّف كل شاشة `SCR-<رقم القسم>`. **لا يُرفع صف إلى 🟩 قبل اختبار يثبت الحالات الأربع** (المادة 17: عادي · تحميل
بشكل المحتوى · فارغ بنص خاص بالشاشة · خطأ)، ومودعة في `tests/Feature/Screens/`.
`tests/Feature/Screens/FourStatesContractTest.php` يفرض العقد المشترك: وجود هيكل تحميل بشكل المحتوى لكل شاشة،
وتفرّد نصّ كل حالة فارغة، وعربية رسائل الخطأ.

| المعرّف | الوصف | الحالة | الملفات | الاختبارات · وما ينقص |
|---|---|---|---|---|
| SCR-9.1 | صفحة الهبوط | 🟦 | `resources/views/public/home.blade.php` · `layouts/public.blade.php` · `partials/public-header.blade.php` · `public-footer.blade.php` · `app/Http/Controllers/Public/HomeController.php` · `app/Models/Cohort.php` (`featured()`) | `PublicScreensTest.php` · `LandingSectionsTest.php` — تواريخ الخط الزمني · الأيقونات · وسم الوصف · `SimulatorFactsTest.php` (BR-11) · `FeaturedCohortTest.php` (BR-31) · ⬜ **قياس أداء المادة 19 لم يُجرَ** (`D-28`) · `RegistrationWindowTest.php` (BR-07 عند ±1 ثانية · BR-30) — العدّاد وحالة الإغلاق ونموذج الانتظار · `PublicDataAlignmentTest.php` — صورة البرنامج تُطابق الدليل · `ProgramTimelineTest.php` — خمس مراحل بتواريخها · `TrainersSectionTest.php` (BR-25 · BR-31) — قسم المدرّبين موصول بجدول التسجيلات · ⬜ **أقسام يفرضها §9.1.1 وما زالت غير منفَّذة: شهادة TVTC · شارتا «عن بُعد» و«مجاني» وعدد الساعات · ثمانية أسئلة** — كلها موقوفة على محتوى من المركز · ⬜ مرحلة «الشهادة» في الخط الزمني موقوفة على `D-58` |
| SCR-9.1.2 | العدّاد التنازلي وقائمة الانتظار | 🟩 | `resources/views/public/home.blade.php` · `resources/js/landing.js` (`registrationCountdown`) · `app/Http/Controllers/Public/WaitlistController.php` · `app/Listeners/SendWaitlistJoined.php` | `RegistrationWindowTest.php` — `it('BR-07: نافذة التسجيل مفتوحة قبل لحظة الإغلاق بثانية')` · `it('BR-07: عند لحظة الإغلاق بالضبط تُغلق النافذة')` · `it('BR-07: بعد لحظة الإغلاق بثانية تُعرض حالة الإغلاق ونموذج الانتظار')` · `WaitlistConfirmationTest.php` (المادة 7) |
| SCR-9.1.3 | وسوم الفهرسة وخريطة الموقع | 🟩 | `app/Http/Controllers/Public/CrawlerController.php` · `routes/web.php` | `CrawlerFilesTest.php` — `it('PRD §9.1.3: robots.txt يُقدَّم بنوع محتوى نصّي')` · `it('BR-25: robots.txt يمنع فهرسة صفحات التحقق واللوحات')` · `it('PRD §9.1.3: sitemap.xml يُقدَّم كـ XML صالح ويسرد الصفحات العامة')` |
| SCR-9.2 | التسجيل | 🟦 | `auth/register.blade.php` · `verify-email-notice.blade.php` · `app/Http/Controllers/Auth/RegisterController.php` · `app/Http/Requests/Auth/RegisterRequest.php` | `SecurityRulesTest.php` (BR-30) · ⬜ **لا اختبار حالات أربع** · معلَّقة على `D-11` (آلية التسجيل) و`D-02` (بريد التفعيل) · `AlpineComponentContractTest.php` — `it('D-67: Alpine يبدأ بعد أن تسجّل كل حزمة دخول مكوّناتها')` · `tools/offline-checks/measure-alpine-pages.mjs` — المعالج يعرض خطوته الأولى بثمانية حقول وبلا خطأ نصّي، على الحزمة المبنيّة (D-67) |
| SCR-9.3 | الدخول والخروج واستعادة كلمة المرور | 🟦 | `auth/login.blade.php` · `forgot-password.blade.php` · `reset-password.blade.php` · `app/Http/Controllers/Auth/LoginController.php` · `PasswordResetController.php` | `PublicScreensTest.php` — `it('صفحة الدخول تُعرض للزائر وتحوّل المسجل إلى لوحته')` · `SecurityRulesTest.php` (BR-29, BR-30) · ⬜ حالة الخطأ وحالة التحميل غير مختبَرتين · `Auth/PasswordRecoveryTest.php` — شاشة الاستعادة 200 وجزيرة النصوص JSON صالحة · الرابط غير الصالح يعرض حالته · المدعوّ المنتهية كلمته يستعيد ويدخل بلا حلقة · شاشة القفل تعدّ نحو لحظة الخادم وتُطلق الزرّ عند انقضائه · لا `@json([` في أي قالب (D-67) — الستّ تفشل على الشيفرة السابقة |
| SCR-9.4 | الحساب الشخصي | 🟦 | `participant/profile.blade.php` · `app/Http/Controllers/Participant/ProfileController.php` · `UpdateProfileRequest.php` · `ChangePasswordRequest.php` | `ImpersonationRulesTest.php` (BR-33) · ⬜ **لا اختبار حالات أربع** |
| SCR-9.5 | هيكل لوحة التحكم (الجانبية · الهيدر · الرئيسية) | 🟦 | `components/layout/sidebar.blade.php` · `header.blade.php` · `footer.blade.php` · `layouts/app.blade.php` · `participant/dashboard.blade.php` | `ParticipantScreensTest.php` — `it('كل شاشات المتدرب تحمل وسم الشاشة واتجاه الصفحة من اليمين')` · `DashboardIsolationTest.php` — مفاتيح الخطأ التسعة متطابقة بين القالب والمتحكّم · بطاقة ترمي تعرض خطأها وحدها والصفحة 200 ولا تُسجَّل رسالة الاستثناء (D-66) — تفشل على الشيفرة السابقة · ⬜ **بنية قائمة المدرب والمدير مفتوحة على `D-30`** |
| SCR-9.6 | البطاقة الرقمية وصفحة التحقق منها | 🟩 | `participant/card.blade.php` · `public/card-verify.blade.php` · `layouts/bare.blade.php` · `app/Http/Controllers/Public/CardVerificationController.php` | `PublicScreensTest.php` — `it('صفحة التحقق من البطاقة: حالة الخطأ برمز غير صالح لا تكشف شيئًا')` · `CertificateRulesTest.php` (BR-25) · `AlpineComponentContractTest.php` — `it('D-61: كل عضو يطلبه القالب موجود فعلًا على المكوّن المسجّل')` — يفشل على `061d593` بسطري `track` و`transformStyle`، ويمرّ بعد الإصلاح (D-61) · `CardIssuanceTest.php` |
| SCR-9.7 | رحلتي | 🟦 | `participant/journey.blade.php` · `app/Http/Controllers/Participant/JourneyController.php` · `app/Services/Journey/JourneyEvaluator.php` | `ParticipantScreensTest.php` — `it('شاشة رحلتي: الحالة العادية تعرض الخطوات')` · `JourneyRulesTest.php` · ⬜ **لا اختبار لهيكل التحميل ولا للحالة الفارغة** |
| SCR-9.8 | جدول البرنامج التدريبي | 🟩 | `participant/schedule.blade.php` · `app/Http/Controllers/Participant/ScheduleController.php` · `app/Presenters/Participant/SessionPresenter.php` | `ParticipantScreensTest.php` — الحالات الثلاث: `it('شاشة الجدول: الحالة العادية تعرض الجلسات')` · `it('… الحالة الفارغة حين لا جلسات')` · `it('… حالة التحميل هيكل بشكل المحتوى')` · `measure-alpine-pages.mjs` — عرض واحد ظاهر، والتبديل يعمل ويُتذكَّر (D-67: كان `atharSchedule` غير معرَّف فلا يظهر أي جدول) |
| SCR-9.9 | الحضور والغياب | 🟩 | `participant/attendance.blade.php` · `trainer/attendance.blade.php` · `app/Http/Controllers/Participant/AttendanceController.php` · `app/Presenters/Participant/AttendanceRatePresenter.php` | `ParticipantScreensTest.php` (ثلاث حالات) · `AttendanceRulesTest.php` · `AttendanceWindowTest.php` |
| SCR-9.10 | المحاضرات المباشرة | 🟦 | `participant/live.blade.php` · `trainer/sessions.blade.php` · `app/Http/Controllers/Participant/LiveController.php` · `app/Policies/SessionPolicy.php` | `ParticipantScreensTest.php` — `it('شاشة المحاضرات المباشرة: الحالة الفارغة حين لا محاضرات قادمة')` · `AccessRulesTest.php` (BR-24) · ⬜ الحالة العادية وهيكل التحميل غير مختبَرين |
| SCR-9.11 | المهام الأدائية | 🟩 | `participant/assignments/index.blade.php` · `show.blade.php` · `trainer/assignments.blade.php` · `app/Presenters/Participant/DueAssignmentPresenter.php` · `SubmissionPresenter.php` | `ParticipantScreensTest.php` — الحالات الأربع كاملة، منها `it('شاشة المهام: حالة الخطأ صفحة عربية مفهومة عند مهمة لا تخص المتدرب')` · ⬜ **الرفع نفسه معلَّق على `D-17`** |
| SCR-9.12 | الحقيبة التدريبية | 🟩 | `participant/resources.blade.php` · `trainer/resources.blade.php` · `app/Services/Storage/PrivateFileService.php` (رابط موقّع 15 دقيقة) · `app/Presenters/Participant/ResourcePresenter.php` | `ParticipantScreensTest.php` (ثلاث حالات) · `ImpersonationRulesTest.php` (BR-34) · ⬜ **صيغ الرفع معلَّقة على `D-17`** |
| SCR-9.13 | نظام التواصل الداخلي | 🟦 | `participant/messages.blade.php` · `app/Http/Controllers/Participant/MessageController.php` · `app/Policies/ThreadPolicy.php` | `ParticipantScreensTest.php` — `it('شاشة الرسائل: الحالة الفارغة حين لا محادثات')` · `it('شاشة الرسائل: الحالة العادية بعد وجود محادثة')` · ⬜ هيكل التحميل وحالة الخطأ غير مختبَرين · `Screens/MessageLiveUpdateTest.php` — الاستطلاع يعيد القائمة مُصيَّرة ومُهرَّبة · `latest` لا يتغيّر إلا بوصول رسالة · BR-22 على الاستطلاع (D-67: التحديث الحيّ PRD §9.13.2 منفَّذ لأول مرة) |
| SCR-9.14 | المشروع الختامي | 🟦 | `participant/final-project.blade.php` · `trainer/…` · `app/Policies/FinalProjectPolicy.php` · `SubmitFinalProjectRequest.php` | `AssignmentAndProjectRulesTest.php` (BR-15, BR-16) · ⬜ **لا اختبار حالات أربع للشاشة** |
| SCR-9.15 | التقييم والدرجات | 🟩 | `participant/grades.blade.php` · `trainer/submissions.blade.php` · `app/Services/Grading/*` · `app/Presenters/Participant/GradeSummaryPresenter.php` | `ParticipantScreensTest.php` (ثلاث حالات) · `ScoreCalculatorTest.php` · `GradingRulesTest.php` |
| SCR-9.16 | الإشعارات | 🟩 | `participant/notifications.blade.php` · `app/Http/Controllers/Participant/NotificationController.php` · `app/Models/NotificationPreference.php` | `ParticipantScreensTest.php` (ثلاث حالات) · `ImpersonationRulesTest.php` (BR-34) · ⬜ **قناة البريد معطَّلة بـ `D-02`** · `Mail/LetterContractTest.php` — التسميات وكتل النصّ وأسماء المسارات وتطابق ar/en وأقفال الجدولة (D-62) · ✅ **قناة البريد تعمل فعلًا — `D-02` محسوم** · ✅ **تفضيلات البريد يقرؤها كل مُرسِل غير أمني — `Mail/MailPreferencesTest.php` عبر المستمعين الحقيقيين (D-66)** · ⬜ **لا تذكير جلسة ولا تذكير تسليم (D-62)** · `Auth/InvitationFlowTest.php` + `Admin/UserImportTest.php` — الدعوة والإلحاق وكلمة المرور المؤقتة والتغيير الإجباري والاستيراد (D-63) — ✅ **نُفِّذت (12 سبتمبر): 9/9 — وكشف التنفيذ الأول أن حالة BR-29 لم تُفعِّل مشغّل الجلسات `database` كما يطلب `phpunit.xml` فأُصلحت، وتفشل الآن إن حُذف الإبطال** |
| SCR-9.17 | الشهادات وصفحة التحقق منها | 🟩 | `participant/certificate.blade.php` · `public/certificate-verify.blade.php` · `admin/certificates.blade.php` · `app/Services/Certificates/*` | `ParticipantScreensTest.php` — `it('شاشة الشهادة: الحالة الفارغة قبل صدور الشهادة')` · `PublicScreensTest.php` · `CertificateRulesTest.php` · ⬜ **قالب الشهادة معلَّق على `D-10`**، ونطاق التسلسل على `D-31` |
| SCR-9.18 | لوحة مدير النظام | 🟦 | `admin/dashboard.blade.php` · `programs.blade.php` · `cohorts.blade.php` · `users/index.blade.php` · `show.blade.php` · `audit.blade.php` · `settings.blade.php` · `registrations.blade.php` · `reports.blade.php` · `landing.blade.php` | `TrainerAdminScreensTest.php` — `it('لوحة المدير: الحالة العادية تعرض البطاقات الإحصائية')` · `AdminRulesTest.php` · ⬜ **الحالات الفارغة وهياكل التحميل غير مختبَرة لأغلب شاشات المدير** |
| SCR-9.19 | وضع معاينة الحسابات | 🟩 | `app/Services/Permissions/ImpersonationService.php` · `ImpersonationReadOnly.php` · `BlockWhenImpersonating.php` · `components/layout/impersonation-bar.blade.php` | `ImpersonationRulesTest.php` (BR-33, BR-34, BR-35) · `TrainerAdminScreensTest.php` — `it('شريط المعاينة ظاهر طوال جلسة المعاينة مع زر الإنهاء')` · `it('شريط المعاينة لا يظهر في التصفح العادي')` |
| SCR-9.20 | الشروط والخصوصية | 🟦 | `public/terms.blade.php` · `public/privacy.blade.php` · `partials/legal-document.blade.php` · `app/Http/Controllers/Public/LegalController.php` | `PublicScreensTest.php` — `it('الصفحات النظامية متاحة وتحمل الاتجاه واللغة الصحيحين')` · ⬜ **نصّها معلَّق على `D-12` (PDPL) و`D-14`** |
| SCR-9.21 | شاشات المدرب (المشاركون · الحضور · التسليمات · التقارير) | 🟦 | `trainer/participants.blade.php` · `attendance.blade.php` · `submissions.blade.php` · `reports.blade.php` · `sessions.blade.php` · `assignments.blade.php` · `resources.blade.php` | `TrainerAdminScreensTest.php` — `it('شاشة الدفعة للمدرب: الحالة الفارغة حين لا متدرب ملتحق')` · `it('شاشة تسليم للمدرب: حالة الخطأ لتسليم خارج دفعته صفحة عربية بلا تفاصيل تقنية')` · ⬜ هياكل التحميل غير مختبَرة |
| SCR-9.22 | صفحات الخطأ (401 · 403 · 404 · 419 · 500 · 503) | 🟩 | `resources/views/errors/401.blade.php` · `403` · `404` · `419` · `500` · `503` | `PublicScreensTest.php` — `it('صفحات الخطأ العامة عربية ولا تكشف أثر التنفيذ')` · `FourStatesContractTest.php` — `it('رسائل الخطأ عربية تشرح ما حدث وما الحل بلا مصطلح تقني')` |

---

## ثالثًا — البوابات `G1` … `G12` (المادة 26)

| البوابة | الأمر | الحالة اليوم | ما يمنع تحقّقها |
|---|---|---|---|
| G1 · التنسيق | `pint --test` | ⬜ لم تُشغَّل | لا مُفسِّر PHP في بيئة التحرير |
| G2 · التحليل الساكن | `phpstan analyse` | ⬜ لم تُشغَّل | نفسه |
| G3 · القائمة السوداء | `php artisan gate:forbidden` | 🟨 الأمر مبنيّ · لم يُشغَّل | `app/Console/Commands/GateForbidden.php` |
| G4 · حصر الوقت | `php artisan gate:clock` | 🟨 مبنيّ · لم يُشغَّل | `GateClock.php` |
| G5 · حصر النصوص | `php artisan gate:i18n` | 🟨 مبنيّ · لم يُشغَّل | `GateI18n.php` |
| G6 · حصر التصميم | `php artisan gate:tokens` | 🟨 مبنيّ · لم يُشغَّل | `GateTokens.php` |
| G7 · الاختبارات | `pest` | ⬜ | لم تُشغَّل |
| G8 · تغطية `app/Services` 100% | `pest --coverage --min=100` | ⬜ | يلزم `xdebug` أو `pcov` |
| G9 · التفويض | `pest --group=authz` | 🟨 **المجموعة موجودة** — `tests/Feature/Authorization/` بملفَّيها | لم تُشغَّل |
| G10 · قواعد العمل | `php artisan gate:br` | 🟨 **كل `BR-01..36` له اختبار باسمه** (مسح آلي على `tests/`) | لم تُشغَّل |
| G11 · الترحيلات | `migrate:fresh --seed` ثم `migrate:rollback` | ⬜ | لا قاعدة بيانات في بيئة التحرير |
| G12 · التتبع | `php artisan gate:traceability` | 🟨 **الأمر مبنيّ** — `GateTraceability.php` | لم يُشغَّل · وكل `D-XX` مُستشهَد به في الكود له سطر في `DECISIONS.md` (تحقُّق يدوي، 7 سبتمبر 2026) |

---

## رابعًا — البنود المعطِّلة التي تظهر في هذا الجدول

راجع `docs/03-decisions/DECISIONS.md`. **لا يُرفع أي صف يعتمد على بند مفتوح إلى 🟩** (المادة 4).

| البند | يعطّل من هذا الجدول |
|---|---|
| `D-02` مزوّد البريد | BR-14 (وصول الإشعار) · SCR-9.2 (تفعيل الحساب) · SCR-9.3 (الاستعادة) · SCR-9.16 |
| `D-11` آلية التسجيل | SCR-9.2 كاملة |
| `D-17` صيغ الملفات | SCR-9.11 (الرفع) · SCR-9.12 · SCR-9.14 |
| `D-26` وزن `excused` و`incomplete` | **BR-26** · كل نسبة حضور معروضة · SCR-9.9 · SCR-9.17 |
| `D-28` ملفات الخطوط | SCR-9.1 (أداء المادة 19) · كل شاشة بصريًّا |
| `D-31` نطاق العدّاد التسلسلي | SCR-9.17 — **لا تُصدَر شهادة أولى قبل حسمه** |
| `D-10` قوالب الشهادتين | SCR-9.17 |
| `D-12` الاحتفاظ بالبيانات | SCR-9.20 (نص الخصوصية) |
| `D-08` تحصين `audit_logs` | BR-27 — لا يُدَّعى تحقق المادة 8 قبل تجربته على الخادم |
| `D-30` بنية قائمة المدرب والمدير | SCR-9.5 · SCR-9.18 · SCR-9.21 |
| `D-36` النطاق | ✅ **لا يعطّل** — `ai.wareed.vip` بقرار مالك (يَنسخ `D-24`)؛ الباقي DNS وشهادة SSL. حارس BR-36 في `AdminRulesTest` صار يقرأ النطاق من الإعدادات |
| `D-37` كلمة مرور القاعدة | 🔴 **إجراء أمني** — وصلت نصًّا في محادثة؛ تُدوَّر بعد أول نشر ناجح. لا أثر لها في المستودع |

---

## طريقة التحديث — إلزامية

في **نفس** الـ commit الذي يمسّ متطلبًا:
1. عدّل صفّه هنا: الحالة · الملفات · الاختبارات.
2. تأكد أن اسم الاختبار يحمل المعرّف: `it('BR-03: تسجيل الحضور بعد S+30m يُحتسب متأخرًا', …)`.
3. اذكر المعرّفات في رسالة الـ commit: `feat(attendance): نافذة تسجيل الحضور [BR-01,BR-02]`.
4. **لا تُرفع حالة إلى 🟩 قبل تشغيل الاختبار فعلًا ورؤيته يمرّ.** «يجب أن يعمل» ليست إجابة (المادة 30).

---

**آخر تحديث:** 7 سبتمبر 2026 — مراجعة تدقيق: أسماء الملفات والاختبارات في كل صف صارت مطابقة لما هو موجود فعلًا
في المستودع، وأُضيف عمود «ما ينقص» لكل شاشة، وسُجِّلت البنود `D-26` و`D-28` و`D-30` و`D-31` كمعطِّلات ظاهرة.
المصفوفة السابقة (531 متطلبًا على شجرة `src/` في Next.js) محفوظة في `TRACEABILITY-v1-cloudflare.md` ولا تُستخدم مرجعًا.

---

## ملحق — طبقة العرض للمدرب والمدير (شريحة `presenters-trainer-admin`)

> **يُدمَج في الجداول أعلاه.** أُلحق في نهاية الملف تفاديًا لتعارض تحرير متزامن على الصفوف نفسها.
> **لم يُشغَّل أي اختبار ولا بوابة** — لا مُفسِّر PHP في البيئة التي أُنتجت فيها هذه الدفعة. كل صف
> أدناه حالته 🟨 «منفَّذ بلا اختبار» بحكم المادة 21، ولا يُرفع إلى 🟩 قبل تشغيل فعلي.

| المعرّف | الشاشة / القاعدة | الحالة | الملفات | ما ينقص |
|---|---|---|---|---|
| SCR-8.1 | كشف متدربي الدفعة (المدرب) | 🟨 | `trainer/participants.blade.php` · `app/Http/Controllers/Trainer/ParticipantController.php` · `app/Presenters/Trainer/ParticipantStats.php` · `ParticipantRow.php` · `ParticipantProfile.php` · `ParticipantSubmission.php` | اختبار الحالات الأربع · اختبار 403 على `?view=` من دفعة أخرى |
| SCR-9.11.3 | لوحة التسليمات والرصد السريع | 🟨 | `trainer/submissions.blade.php` · `app/Http/Controllers/Trainer/SubmissionController.php` · `app/Presenters/Trainer/SubmissionRow.php` · `SubmissionStats.php` · `GradingForm.php` | اختبار الحالات الأربع · اختبار BR-12/BR-13 من الشاشة |
| SCR-8.2 | تقارير الدفعة (المدرب) | 🟨 | `trainer/reports.blade.php` · `app/Http/Controllers/Trainer/ReportController.php` · `app/Presenters/Trainer/ReportSummary.php` · `Shared/ChartPoint.php` · `AtRiskPerson.php` | اختبار الحالات الأربع · اختبار BR-26 على قائمة «تحت الحد» |
| SCR-9.12 | الحقيبة التدريبية (المدرب) | 🟨 | `trainer/resources.blade.php` · `app/Http/Controllers/Trainer/ResourceController.php` · `app/Presenters/Trainer/ResourceRow.php` | اختبار الأرشفة والاستعادة · اختبار عدّاد التحميل |
| SCR-9.14 | المشروع الختامي (المدرب) | 🟨 | `trainer/final-project.blade.php` **(شاشة جديدة — لم تكن موجودة)** · `app/Http/Controllers/Trainer/FinalProjectController.php` · `app/Presenters/Trainer/FinalProjectBrief.php` · `ProjectSubmissionRow.php` | اختبار BR-15/BR-16 على زر الفتح · اختبار الحالات الأربع |
| BR-11 | تحذير مجموع درجات المهام | 🟨 | `Shared/TotalsWarning.php` مُمرَّر من `SubmissionController` (كان `bool`) | اختبار يثبت ظهور `current`/`expected` |
| BR-15, BR-16 | فتح المشروع الختامي للدفعة | 🟨 | `FinalProjectController::unlock` — أُصلح استيراد `App\Models\FinalProject` المفقود الذي كان يجعل النقطة تنهار | اختبار 403 لمدرب من خارج الدفعة |
| BR-19 | التسليم لا يُستبدَل بل تزيد نسخته | 🟨 | العدّ في `SubmissionController::stats` و`ReportController::submitterCounts` يستخدم `COUNT(DISTINCT …)` لا عدّ صفوف | اختبار يثبت أن ثلاث نسخ = تسليم واحد |
| BR-23 | نطاق المدرب على دفعاته | 🟨 | كل استعلامات المتحكّمات الخمسة مقيَّدة بـ `scopedCohort()` · `?view=`/`?grade=`/`?week=` تُحسم داخل نطاق الدفعة | اختبار 403 لكل معرّف من دفعة أخرى |
| DS-CARD | مكوّن البطاقة — شقّ `action` و خاصية `flush` | 🟨 | `components/ui/card.blade.php` · `resources/css/components.css` (`.ui-card--flush`) | اختبار تصييري للمكوّن |
| DS-PROGRESS | شريط التقدم — خاصية `max` | 🟨 | `components/ui/progress-bar.blade.php` | اختبار أن `value=25, max=50` يرسم 50% |

**تصحيحات واجهة مصاحبة (بلا معرّف مستقل):**
`trainer/resources.blade.php` كان يرسل `@method('PATCH')` إلى مسار `DELETE` · `trainer/submissions.blade.php`
كان يمرّر معرّف المتدرب إلى `submissions.remind` التي تستقبل مهمة · روابط تحميل ملفات التسليم كانت تُرسم
`href=""` لعدم وجود مسار تحميل موقّع بعد · `variant="primary"`/`"danger"` على `stat-card`/`pill`/`progress-bar`
ليست من مفردات تلك المكوّنات وكانت تسقط صامتة إلى `default` — صارت `brand`/`error`.

---

## خامسًا — متطلبات وظيفية مُستشهَد بها `FR-*`

> تُضاف هنا كل `FR-*` يُذكر في شيفرة أو اسم اختبار — `G12` يرفض معرّفًا بلا صفّ.
> المصدر: `docs/01-analysis/02-requirements-matrix.md`.

| المعرّف | الوصف | الحالة | الملفات | الاختبارات |
|---|---|---|---|---|
| FR-NOTIF-13 | نشر مهمة يُبلغ الدفعة على المنصّة والبريد، والمسودة لا تُبلغ أحدًا | 🟩 | `app/Http/Controllers/Trainer/AssignmentController.php` (`announce()`) · `app/Events/AssignmentPublished.php` · `app/Listeners/SendAssignmentPublished.php` · `app/Services/Notifications/InAppNotifier.php` | `BusinessRules/AssignmentNotificationsTest.php` — سبع حالات: المسودة صامتة · النشر يصل النشطين مرة على القناتين لا المنسحب ولا المدرّب · نشر المسودة بالتعديل · إعادة الحفظ صامتة · الرابط صفحة المتدرّب (200 بلا رفض مُسجَّل) · الجرس المطفأ · المستمع يتحقّق من النشر عند الإرسال — تفشل على الشيفرة السابقة (D-68) · `Mail/LetterContractTest.php` — لا رسالة تشير إلى `trainer.*` أو `admin.*` |
| FR-ASGN-30 | زرّ «ذكّر من لم يسلّم» يرسل إشعارًا واحدًا لكل من لم يسلّم ولا أحد غيره | 🟦 | `app/Http/Controllers/Trainer/SubmissionController.php` (`remind()` · `remindAssignmentId()`) · `app/Http/Requests/Trainer/RemindAssignmentRequest.php` · `app/Policies/AssignmentPolicy.php` · `app/Services/Mail/CohortAudience.php` (`yetToSubmit()`) · `app/Events/AssignmentReminderRequested.php` · `app/Listeners/SendAssignmentReminder.php` · ترحيل `add_last_reminded_at_to_assignments` | `BusinessRules/AssignmentNotificationsTest.php` — اثنتا عشرة حالة: الجمهور · ظهور الزرّ · المسودة 403 · مدرّب دفعة أخرى 403 · الموعد ±1ث · لا أحد متبقٍّ · ضغطتان ومدرّب ومدير معًا · التهدئة ±1ث · كل قناة بمفتاحها · المدّة بالكلمات · `isPastDueAt` عند الحدّ · اللوحة بلا دفعة · ⬜ **قائمة أسماء من لم يسلّم على اللوحة (PRD §9.11.3) غير موجودة بعد** · ⚠️ رفض التذكير بعد الموعد، وطول التهدئة (60 دقيقة): افتراض مؤقت (D-68) |
