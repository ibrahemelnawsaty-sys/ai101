<?php

declare(strict_types=1);

/**
 * BR-01 … BR-10 asserted end to end, through the real HTTP stack, against the real
 * database constraints.
 *
 * ASSUMPTIONS declared here rather than made silently:
 *  - The scheduled reconciliation of BR-08 and BR-09 is `attendance:reconcile`.
 *    PROJECT-CONTRACT.md §15 names the gate commands but no scheduled command.
 *  - The trainer's manual edit of BR-10 posts to `trainer.attendance.update`.
 *    PROJECT-CONTRACT.md §10 collapses the trainer area into `trainer.*`.
 *  - The status field on that request is named `attendance_status`, not `status`.
 *    That is not a guess: lang/{ar,en}/validation.php lists the attendance wire
 *    fields explicitly - `session_id`, `attendance_status`, `checked_in_at`,
 *    `checked_out_at`, `edit_reason` - and carries a `custom.edit_reason`
 *    message block. Both UpdateAttendanceRequest and BulkAttendanceRequest use
 *    that vocabulary. The trainer view was the one place that did not, and it
 *    was corrected rather than the server renamed around it.
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-05, BR-06, BR-07, BR-08, BR-09, BR-10
 * @see PRD §9.9 · PROJECT-CONTRACT.md §6
 */

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->trainer = makeTrainer($this->cohort);

    $this->start = riyadhAt('2026-10-12 18:00:00');
    $this->end = riyadhAt('2026-10-12 21:00:00');
    $this->session = sessionInCohort($this->cohort, $this->start, $this->end);
});

/*
|--------------------------------------------------------------------------
| BR-01 — the check-in window
|--------------------------------------------------------------------------
*/

it('BR-01, D-103: نافذة تسجيل الحضور تبدأ قبل بداية الجلسة بساعة', function (): void {
    freezeAt($this->start->subMinutes(60)->subSecond());

    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session)));

    expect(Attendance::query()->count())->toBe(0);

    freezeAt($this->start->subMinutes(60));

    assertAccepted($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session)));

    expect(Attendance::query()->count())->toBe(1);
});

it('BR-01: نافذة تسجيل الحضور تنتهي بانتهاء الجلسة', function (): void {
    freezeAt($this->end->addSecond());

    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session)));

    expect(Attendance::query()->whereNotNull('check_in_at')->count())->toBe(0);
});

it('BR-01: التسجيل عند نهاية الجلسة بالضبط ما زال مقبولًا', function (): void {
    freezeAt($this->end);

    assertAccepted($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session)));

    expect(Attendance::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| BR-02, BR-03 — present or late
|--------------------------------------------------------------------------
*/

it('BR-02: التسجيل خلال أول ثلاثين دقيقة من بداية الجلسة يُحتسب حاضرًا', function (): void {
    freezeAt($this->start->addMinutes(30));

    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session));

    $attendance = Attendance::query()->sole();

    expect($attendance->status->value)->toBe('present')
        ->and($attendance->check_in_at->equalTo($this->start->addMinutes(30)))->toBeTrue();
});

it('BR-03: التسجيل بعد أول ثلاثين دقيقة يُقبل ويُحتسب متأخرًا', function (): void {
    freezeAt($this->start->addMinutes(30)->addSecond());

    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session));

    $attendance = Attendance::query()->sole();

    expect($attendance->status->value)->toBe('late');
});

/*
|--------------------------------------------------------------------------
| BR-04 — the check-out window
|--------------------------------------------------------------------------
*/

it('BR-04: نافذة الانصراف تبدأ قبل نهاية الجلسة بثلاثين دقيقة وتنتهي بعدها بثلاثين', function (): void {
    freezeAt($this->start);
    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session));

    freezeAt($this->end->subMinutes(30)->subSecond());
    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkOut', $this->session)));
    expect(Attendance::query()->sole()->check_out_at)->toBeNull();

    freezeAt($this->end->subMinutes(30));
    assertAccepted($this->actingAs($this->participant)->post(route('attendance.checkOut', $this->session)));
    expect(Attendance::query()->sole()->check_out_at)->not->toBeNull();
});

it('BR-04, D-103: الانصراف بعد نهاية الجلسة بساعة وثانية مرفوض', function (): void {
    freezeAt($this->start);
    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session));

    freezeAt($this->end->addMinutes(60)->addSecond());

    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkOut', $this->session)));

    expect(Attendance::query()->sole()->check_out_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| BR-05 — no check-out without a check-in
|--------------------------------------------------------------------------
*/

it('BR-05: لا يمكن تسجيل الانصراف دون تسجيل حضور سابق للجلسة نفسها', function (): void {
    freezeAt($this->end);

    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkOut', $this->session)));

    expect(Attendance::query()->count())->toBe(0);
});

it('BR-05: حضور في جلسة أخرى لا يبيح الانصراف من هذه الجلسة', function (): void {
    $other = sessionInCohort($this->cohort, $this->start->subDay(), $this->end->subDay());
    makeAttendance($other, $this->participant, 'present');

    freezeAt($this->end);

    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkOut', $this->session)));

    expect(Attendance::query()->where('session_id', $this->session->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| BR-06 — one record per user per session, enforced by the database
|--------------------------------------------------------------------------
*/

it('BR-06: التسجيل المكرر للجلسة الواحدة مرفوض', function (): void {
    freezeAt($this->start);

    assertAccepted($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session)));
    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session)));

    expect(Attendance::query()->where('session_id', $this->session->id)->count())->toBe(1);
});

it('BR-06: طلبان متزامنان ينتجان سطرًا واحدًا — القيد الفريد في قاعدة البيانات هو الحارس', function (): void {
    // The application-level guard is bypassed on purpose: two raw inserts race for
    // the same (session_id, user_id). Only the database can settle this, and if the
    // unique index is missing, this test is the one that notices.
    freezeAt($this->start);

    $row = [
        'session_id' => $this->session->id,
        'user_id' => $this->participant->id,
        'status' => 'present',
        'check_in_at' => $this->start->toDateTimeString(),
        'is_manual' => false,
        'created_at' => $this->start->toDateTimeString(),
        'updated_at' => $this->start->toDateTimeString(),
    ];

    DB::table('attendances')->insert($row + ['id' => (string) Str::uuid()]);

    expect(fn () => DB::table('attendances')->insert($row + ['id' => (string) Str::uuid()]))
        ->toThrow(QueryException::class);

    expect(DB::table('attendances')
        ->where('session_id', $this->session->id)
        ->where('user_id', $this->participant->id)
        ->count())->toBe(1);
});

it('BR-06: القيد الفريد لا يمنع متدربين مختلفين في الجلسة نفسها', function (): void {
    $other = makeParticipant($this->cohort);

    makeAttendance($this->session, $this->participant, 'present');
    makeAttendance($this->session, $other, 'present');

    expect(Attendance::query()->where('session_id', $this->session->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| BR-07 — server time is the only reference
|--------------------------------------------------------------------------
*/

it('BR-07: وقت العميل المرسل في الطلب يُتجاهل تمامًا', function (): void {
    // The browser claims it is inside the window; the server clock says otherwise.
    freezeAt($this->start->subHours(5));

    assertRefused($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session), [
        'client_time' => $this->start->toIso8601String(),
        'checked_in_at' => $this->start->toIso8601String(),
        'timestamp' => $this->start->getTimestamp(),
    ]));

    expect(Attendance::query()->count())->toBe(0);
});

it('BR-07: وقت التسجيل المخزَّن هو وقت الخادم لا القيمة المرسلة', function (): void {
    freezeAt($this->start->addMinutes(5));

    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session), [
        'check_in_at' => $this->start->subHour()->toIso8601String(),
    ]);

    expect(Attendance::query()->sole()->check_in_at->equalTo($this->start->addMinutes(5)))->toBeTrue();
});

it('BR-07: يُخزَّن عنوان IP ومعرّف المتصفح مع كل تسجيل', function (): void {
    freezeAt($this->start);

    $this->actingAs($this->participant)
        ->withHeaders(['User-Agent' => 'AtharTestAgent/1.0'])
        ->post(route('attendance.checkIn', $this->session));

    $attendance = Attendance::query()->sole();

    expect($attendance->ip_address)->not->toBeNull()
        ->and($attendance->user_agent)->toContain('AtharTestAgent');
});

it('BR-07: الجلسة الملغاة ترفض التسجيل في كل لحظات النافذة', function (): void {
    $cancelled = sessionInCohort($this->cohort, $this->start, $this->end, [
        'status' => 'cancelled',
        'cancellation_reason' => 'trainer-unavailable',
    ]);

    foreach ([$this->start->subMinutes(30), $this->start, $this->end] as $moment) {
        freezeAt($moment);
        assertRefused($this->actingAs($this->participant)->post(route('attendance.checkIn', $cancelled)));
    }

    expect(Attendance::query()->where('session_id', $cancelled->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| BR-08, BR-09 — the scheduled reconciliation
|--------------------------------------------------------------------------
*/

it('BR-08: من لم يسجّل حضورًا حتى انتهاء الجلسة يُحوَّل آليًا إلى غائب', function (): void {
    freezeAt($this->end->addMinutes(61));

    Artisan::call('attendance:reconcile');

    $attendance = Attendance::query()
        ->where('session_id', $this->session->id)
        ->where('user_id', $this->participant->id)
        ->sole();

    expect($attendance->status->value)->toBe('absent')
        ->and($attendance->check_in_at)->toBeNull();
});

it('BR-08: لا يُحوَّل أحد إلى غائب قبل انتهاء الجلسة', function (): void {
    freezeAt($this->end->subSecond());

    Artisan::call('attendance:reconcile');

    expect(Attendance::query()->where('session_id', $this->session->id)->count())->toBe(0);
});

it('BR-09: من سجّل حضورًا ولم يسجّل انصرافًا يُحوَّل إلى حضور غير مكتمل', function (): void {
    freezeAt($this->start);
    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session));

    freezeAt($this->end->addMinutes(60)->addSecond());
    Artisan::call('attendance:reconcile');

    $attendance = Attendance::query()->where('user_id', $this->participant->id)->sole();

    expect($attendance->status->value)->toBe('incomplete')
        ->and($attendance->check_in_at)->not->toBeNull()
        ->and($attendance->check_out_at)->toBeNull();
});

it('BR-09: المدرب يُنبَّه بالحضور غير المكتمل', function (): void {
    freezeAt($this->start);
    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session));

    freezeAt($this->end->addMinutes(60)->addSecond());
    Artisan::call('attendance:reconcile');

    expect(Notification::query()->where('user_id', $this->trainer->id)->count())->toBeGreaterThan(0);
});

it('BR-09: من سجّل حضوره وانصرافه لا تتغير حالته عند المعالجة الآلية', function (): void {
    freezeAt($this->start);
    $this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session));

    freezeAt($this->end);
    $this->actingAs($this->participant)->post(route('attendance.checkOut', $this->session));

    freezeAt($this->end->addHours(2));
    Artisan::call('attendance:reconcile');

    expect(Attendance::query()->sole()->status->value)->toBe('present');
});

it('BR-08, BR-09: المعالجة الآلية قابلة لإعادة التشغيل بلا أثر جانبي', function (): void {
    freezeAt($this->end->addMinutes(61));

    Artisan::call('attendance:reconcile');
    Artisan::call('attendance:reconcile');

    expect(Attendance::query()->where('session_id', $this->session->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| BR-10 — manual edits
|--------------------------------------------------------------------------
*/

it('BR-10: التعديل اليدوي على الحضور بلا سبب مكتوب مرفوض', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    freezeAt($this->end->addHour());

    assertRefused($this->actingAs($this->trainer)->patch(route('trainer.attendance.update', $attendance), [
        'attendance_status' => 'excused',
    ]));

    expect($attendance->fresh()->status->value)->toBe('absent');
});

it('BR-10: سبب التعديل أقل من عشرة أحرف مرفوض', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    freezeAt($this->end->addHour());

    assertRefused($this->actingAs($this->trainer)->patch(route('trainer.attendance.update', $attendance), [
        'attendance_status' => 'excused',
        'edit_reason' => 'short',
    ]));

    expect($attendance->fresh()->status->value)->toBe('absent');
});

it('BR-10: التعديل اليدوي بسبب مكتوب يُقبل ويُسجَّل في سجل التدقيق', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    freezeAt($this->end->addHour());

    assertAccepted($this->actingAs($this->trainer)->patch(route('trainer.attendance.update', $attendance), [
        'attendance_status' => 'excused',
        'edit_reason' => 'A documented medical excuse was provided by the trainee.',
    ]));

    $fresh = $attendance->fresh();

    expect($fresh->status->value)->toBe('excused')
        ->and($fresh->is_manual)->toBeTrue()
        ->and($fresh->edited_by)->toBe($this->trainer->id)
        ->and($fresh->edit_reason)->not->toBeNull();

    expect(AuditLog::query()
        ->where('entity_type', 'attendance')
        ->where('entity_id', $attendance->id)
        ->where('actor_id', $this->trainer->id)
        ->count())->toBe(1);
});

it('BR-10: سجل التدقيق يحفظ القيمة قبل التعديل وبعده', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    freezeAt($this->end->addHour());

    $this->actingAs($this->trainer)->patch(route('trainer.attendance.update', $attendance), [
        'attendance_status' => 'excused',
        'edit_reason' => 'A documented medical excuse was provided by the trainee.',
    ]);

    $log = AuditLog::query()->where('entity_id', $attendance->id)->sole();

    expect($log->before)->not->toBeNull()
        ->and($log->after)->not->toBeNull()
        ->and($log->ip_address)->not->toBeNull();
});
