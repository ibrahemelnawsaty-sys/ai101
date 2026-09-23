<?php

declare(strict_types=1);

/**
 * D-106 — self-check-in by a QR code the coordinator's screen displays, and
 * the excuse-request workflow it enables. Every boundary of the NEW
 * [S, S+60m] self-check-in window is asserted at ±1 second (Article 20); the
 * pre-existing D-103 manual window ([S-60m, E], no upper cap) is asserted
 * UNCHANGED by this feature, not merely left alone in the code.
 *
 * @see D-106 · CONSTITUTION Art. 20, Art. 22
 */

use App\Enums\AttendanceExceptionStatus;
use App\Mail\AtharLetter;
use App\Models\Attendance;
use App\Models\AttendanceExceptionRequest;
use App\Models\Notification;
use App\Services\Time\Clock;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->trainer = makeTrainer($this->cohort);

    $this->start = riyadhAt('2026-10-12 18:00:00');
    $this->end = riyadhAt('2026-10-12 20:00:00');
    $this->session = sessionInCohort($this->cohort, $this->start, $this->end);
});

/**
 * A freshly signed self-check-in url for $this->session, valid for the given
 * number of minutes from right now (the caller has already frozen the clock).
 */
function selfCheckInUrl(object $session, int $validMinutes = 10): string
{
    // Signed from Clock::now(), never the global now() — freezeAt() fakes the
    // former only (BR-07, Article 11), so a url built from real wall-clock
    // time would sign an `expires` unrelated to the frozen instant a test is
    // actually asserting against.
    return URL::temporarySignedRoute(
        'attendance.selfCheckIn',
        Clock::now()->addMinutes($validMinutes),
        ['session' => $session->id],
    );
}

/*
|--------------------------------------------------------------------------
| D-106 — the self-check-in window, [S, S+60m], boundaries at ±1s
|--------------------------------------------------------------------------
*/

it('D-106: تحضير ذاتي قبل بداية الجلسة بثانية واحدة يُرفض ولا يُنشئ سجلًا', function (): void {
    freezeAt($this->start->subSecond());

    assertRefused($this->actingAs($this->participant)->get(selfCheckInUrl($this->session)));

    expect(Attendance::query()->count())->toBe(0);
});

it('D-106: تحضير ذاتي عند بداية الجلسة بالضبط يُقبل ويُحتسب حاضرًا', function (): void {
    freezeAt($this->start);

    assertAccepted($this->actingAs($this->participant)->get(selfCheckInUrl($this->session)));

    $attendance = Attendance::query()->sole();
    expect($attendance->status->value)->toBe('present')
        ->and($attendance->is_manual)->toBeFalse()
        ->and($attendance->check_in_at->equalTo($this->start))->toBeTrue();
});

it('D-106: تحضير ذاتي عند S+30m بالضبط يُحتسب حاضرًا لا متأخرًا', function (): void {
    freezeAt($this->start->addMinutes(30));

    $this->actingAs($this->participant)->get(selfCheckInUrl($this->session));

    expect(Attendance::query()->sole()->status->value)->toBe('present');
});

it('D-106: تحضير ذاتي عند S+30m+1s يُحتسب متأخرًا', function (): void {
    freezeAt($this->start->addMinutes(30)->addSecond());

    $this->actingAs($this->participant)->get(selfCheckInUrl($this->session));

    expect(Attendance::query()->sole()->status->value)->toBe('late');
});

it('D-106: تحضير ذاتي عند S+60m بالضبط لا يزال مقبولًا (متأخرًا)', function (): void {
    freezeAt($this->start->addMinutes(60));

    assertAccepted($this->actingAs($this->participant)->get(selfCheckInUrl($this->session)));

    expect(Attendance::query()->sole()->status->value)->toBe('late');
});

it('D-106: تحضير ذاتي عند S+60m+1s يُرفض برسالة انتهاء الفترة ولا يُنشئ سجلًا', function (): void {
    freezeAt($this->start->addMinutes(60)->addSecond());

    $response = assertRefused($this->actingAs($this->participant)->get(selfCheckInUrl($this->session)));
    $response->assertSessionHasErrors('message');

    expect(Attendance::query()->count())->toBe(0);
});

it('D-106: رابط بلا توقيع صالح يُرفض حتى لو كانت اللحظة داخل نافذة التحضير الذاتي', function (): void {
    // Laravel's own `signed` middleware checks a real HMAC, computed and
    // verified against real wall-clock time — a mechanism Clock::fake()
    // deliberately never touches (it fakes only App\Services\Time\Clock, the
    // BR-07 boundary, not Carbon's global test-now). So this asserts the
    // route actually carries `signed` at all, with a request built the same
    // way anyone forging or replaying a stale link would produce it, rather
    // than trying to fake the expiry itself.
    freezeAt($this->start);

    $unsigned = route('attendance.selfCheckIn', ['session' => $this->session->id]);

    $this->actingAs($this->participant)->get($unsigned)->assertForbidden();

    expect(Attendance::query()->count())->toBe(0);
});

it('D-106: التحضير اليدوي يبقى بلا سقف الساعة — يعمل بعد إغلاق نافذة التحضير الذاتي (D-103 دون تغيير)', function (): void {
    freezeAt($this->start->addMinutes(61));

    assertAccepted($this->actingAs($this->participant)->post(route('attendance.checkIn', $this->session)));

    expect(Attendance::query()->sole()->status->value)->toBe('late');
});

it('403: مشارك من دفعة أخرى لا يستطيع التحضير الذاتي لهذه الجلسة', function (): void {
    $outsider = makeParticipant(makeCohort());
    freezeAt($this->start);

    $this->actingAs($outsider)->get(selfCheckInUrl($this->session))->assertForbidden();

    expect(Attendance::query()->count())->toBe(0);
});

it('D-106: تحضير ذاتي مكرر لنفس الجلسة يُرفض والسجل الأول يبقى كما هو', function (): void {
    freezeAt($this->start);
    $this->actingAs($this->participant)->get(selfCheckInUrl($this->session));

    freezeAt($this->start->addMinutes(5));
    assertRefused($this->actingAs($this->participant)->get(selfCheckInUrl($this->session)));

    expect(Attendance::query()->where('session_id', $this->session->id)->count())->toBe(1)
        ->and(Attendance::query()->sole()->status->value)->toBe('present');
});

/*
|--------------------------------------------------------------------------
| D-106 — filing an excuse request
|--------------------------------------------------------------------------
*/

it('D-106: طلب إعذار غياب يُنشأ لسجل غائب ويصل إشعار للمنسّق', function (): void {
    Mail::fake();
    $attendance = makeAttendance($this->session, $this->participant, 'absent');

    assertAccepted($this->actingAs($this->participant)->post(route('attendance.exceptionRequest', $attendance), [
        'type' => 'absence',
        'reason' => 'كنت في المستشفى برفقة أحد والديّ ولم أستطع الحضور.',
    ]));

    $request = AttendanceExceptionRequest::query()->sole();
    expect($request->status)->toBe(AttendanceExceptionStatus::Pending)
        ->and($request->type->value)->toBe('absence')
        ->and($request->user_id)->toBe($this->participant->id);

    expect(Notification::query()->where('user_id', $this->coordinator->id)->where('type', 'attendance_exception_requested')->exists())->toBeTrue();

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.attendance_exception_requested'
        && $l->hasTo($this->participant->email));
});

it('D-106: طلب من نوع لا يطابق حالة السجل الفعلية يُرفض', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'present');

    assertRefused($this->actingAs($this->participant)->post(route('attendance.exceptionRequest', $attendance), [
        'type' => 'absence',
        'reason' => 'محاولة تقديم طلب غياب على سجل حضور فعلي.',
    ]));

    expect(AttendanceExceptionRequest::query()->count())->toBe(0);
});

it('D-106: سبب أقصر من عشرة أحرف يُرفض', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');

    assertRefused($this->actingAs($this->participant)->post(route('attendance.exceptionRequest', $attendance), [
        'type' => 'absence',
        'reason' => 'قصير',
    ]));

    expect(AttendanceExceptionRequest::query()->count())->toBe(0);
});

it('D-106: طلب ثانٍ لنفس السجل بينما الأول معلّق يُرفض', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    $this->actingAs($this->participant)->post(route('attendance.exceptionRequest', $attendance), [
        'type' => 'absence',
        'reason' => 'السبب الأول المكتوب بعشرة أحرف على الأقل.',
    ]);

    assertRefused($this->actingAs($this->participant)->post(route('attendance.exceptionRequest', $attendance), [
        'type' => 'absence',
        'reason' => 'محاولة ثانية بينما الأولى ما زالت معلّقة.',
    ]));

    expect(AttendanceExceptionRequest::query()->count())->toBe(1);
});

it('403: متدرب آخر لا يرفع طلبًا عن سجل حضور ليس له', function (): void {
    $other = makeParticipant($this->cohort);
    $attendance = makeAttendance($this->session, $this->participant, 'absent');

    $this->actingAs($other)->post(route('attendance.exceptionRequest', $attendance), [
        'type' => 'absence',
        'reason' => 'محاولة رفع طلب باسم متدرب آخر.',
    ])->assertForbidden();

    expect(AttendanceExceptionRequest::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| D-106 — deciding a request
|--------------------------------------------------------------------------
*/

it('D-106: قبول الطلب يُعذر سجل الحضور ويرسل بريدًا وإشعارًا للمتدرب', function (): void {
    Mail::fake();
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    $request = app(App\Services\Attendance\AttendanceExceptionRequester::class)
        ->request($this->participant, $attendance, App\Enums\AttendanceExceptionType::Absence, 'سبب حقيقي بعشرة أحرف على الأقل.');

    assertAccepted($this->actingAs($this->coordinator)->post(route('trainer.attendance-exceptions.approve', $request)));

    $fresh = $attendance->fresh();
    expect($fresh->excused_at)->not->toBeNull()
        ->and($fresh->excused_by)->toBe($this->coordinator->id)
        ->and($fresh->status->value)->toBe('absent')
        ->and($request->fresh()->status)->toBe(AttendanceExceptionStatus::Approved);

    expect(Notification::query()->where('user_id', $this->participant->id)->where('type', 'attendance_exception_approved')->exists())->toBeTrue();
    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.attendance_exception_approved');
});

it('D-106: رفض الطلب بسبب يُسجَّل ويُرسَل للمتدرب، ولا يغيّر سجل الحضور', function (): void {
    Mail::fake();
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    $request = app(App\Services\Attendance\AttendanceExceptionRequester::class)
        ->request($this->participant, $attendance, App\Enums\AttendanceExceptionType::Absence, 'سبب حقيقي بعشرة أحرف على الأقل.');

    assertAccepted($this->actingAs($this->coordinator)->post(route('trainer.attendance-exceptions.reject', $request), [
        'decision_reason' => 'لا يوجد ما يثبت العذر المذكور في الطلب.',
    ]));

    expect($attendance->fresh()->excused_at)->toBeNull()
        ->and($request->fresh()->status)->toBe(AttendanceExceptionStatus::Rejected)
        ->and($request->fresh()->decision_reason)->not->toBeNull();

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.attendance_exception_rejected'
        && $l->hasTo($this->participant->email));
});

it('D-106: رفض بسبب أقصر من عشرة أحرف مرفوض والحالة تبقى معلّقة', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    $request = app(App\Services\Attendance\AttendanceExceptionRequester::class)
        ->request($this->participant, $attendance, App\Enums\AttendanceExceptionType::Absence, 'سبب حقيقي بعشرة أحرف على الأقل.');

    assertRefused($this->actingAs($this->coordinator)->post(route('trainer.attendance-exceptions.reject', $request), [
        'decision_reason' => 'قصير',
    ]));

    expect($request->fresh()->status)->toBe(AttendanceExceptionStatus::Pending);
});

it('D-106: قرار ثانٍ على طلب مُقرَّر مسبقًا يُرفض', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    $request = app(App\Services\Attendance\AttendanceExceptionRequester::class)
        ->request($this->participant, $attendance, App\Enums\AttendanceExceptionType::Absence, 'سبب حقيقي بعشرة أحرف على الأقل.');

    $this->actingAs($this->coordinator)->post(route('trainer.attendance-exceptions.approve', $request));

    expect(fn () => app(App\Services\Attendance\AttendanceExceptionRequester::class)->reject(
        $this->coordinator, $request->fresh(), 'محاولة رفض طلب سبق قبوله.',
    ))->toThrow(App\Exceptions\AttendanceException::class);
});

it('403: منسّق لا يقرر طلبًا من دفعة لم يُسنَد إليها', function (): void {
    $otherCoordinator = makeCoordinator(makeCohort());
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    $request = app(App\Services\Attendance\AttendanceExceptionRequester::class)
        ->request($this->participant, $attendance, App\Enums\AttendanceExceptionType::Absence, 'سبب حقيقي بعشرة أحرف على الأقل.');

    $this->actingAs($otherCoordinator)
        ->post(route('trainer.attendance-exceptions.approve', $request))
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(AttendanceExceptionStatus::Pending);
});

it('403: متدرب لا يقرر طلب إعذار حتى لو كان طلبه هو', function (): void {
    $attendance = makeAttendance($this->session, $this->participant, 'absent');
    $request = app(App\Services\Attendance\AttendanceExceptionRequester::class)
        ->request($this->participant, $attendance, App\Enums\AttendanceExceptionType::Absence, 'سبب حقيقي بعشرة أحرف على الأقل.');

    $this->actingAs($this->participant)
        ->post(route('trainer.attendance-exceptions.approve', $request))
        ->assertForbidden();
});
