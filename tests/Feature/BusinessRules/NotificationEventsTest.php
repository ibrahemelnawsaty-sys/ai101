<?php

declare(strict_types=1);

/**
 * Five letters the PRD requires, which had a listener and no dispatch site —
 * so none was ever sent (D-77): a cancelled session, an opened final project,
 * a drop in attendance, an issued certificate, and a sign-in from a new device.
 *
 * Each case fires the real action and counts what was announced. The drop in
 * attendance is PRD §9.16.1's «عند النزول تحت الحد» — a CROSSING — measured on
 * the eligibility service's rate, the one the trainee's own screen shows.
 *
 * @see PRD §9.16.1 · BR-26 · D-51, D-77
 */

use App\Enums\CohortStatus;
use App\Events\AttendanceLow;
use App\Events\CertificateIssued;
use App\Events\FinalProjectUnlocked;
use App\Events\NewDeviceLogin;
use App\Events\SessionCancelled;
use App\Mail\AtharLetter;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\Session;
use App\Presenters\Support\Present;
use App\Services\Attendance\LowAttendanceWarning;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Security\KnownDevices;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Attendance dropped below the threshold
|--------------------------------------------------------------------------
*/

/** Four sessions on consecutive days from 5 October; $attended of them present. */
function attendanceCohort(object $test, int $attended, int $sessions = 4, array $cohort = []): void
{
    $test->cohort = makeCohort($cohort + ['min_attendance_rate' => 75, 'status' => CohortStatus::Running->value]);
    $test->participant = makeParticipant($test->cohort);

    for ($i = 0; $i < $sessions; $i++) {
        sessionAttendedBy($test->cohort, $test->participant, 'training', $i < $attended ? 'present' : 'absent', $i);
    }
}

function warn(object $test, Carbon\CarbonImmutable $at): int
{
    return app(LowAttendanceWarning::class)->check($test->cohort->fresh(), $at);
}

it('BR-26: النزول تحت الحد يُنبَّه مرة واحدة عبر تشغيلات متكرّرة — إشعار ورسالة', function (): void {
    Event::fake([AttendanceLow::class]);
    attendanceCohort($this, attended: 1, sessions: 2);
    $at = riyadhAt('2026-10-06 22:00:00'); // both sessions over: 1 of 2 = 50%

    expect(warn($this, $at))->toBe(1)
        ->and(warn($this, $at->addHour()))->toBe(0);

    Event::assertDispatchedTimes(AttendanceLow::class, 1);
    expect(Notification::query()->where('user_id', $this->participant->id)->where('type', 'attendance_low')->count())->toBe(1);
});

it('BR-26: لا تنبيه قبل أن تنتهي أي جلسة — عند النهاية لا، وبعدها بثانية نعم', function (): void {
    Event::fake([AttendanceLow::class]);
    $this->cohort = makeCohort(['min_attendance_rate' => 75, 'status' => CohortStatus::Running->value]);
    $this->participant = makeParticipant($this->cohort);
    $end = riyadhAt('2026-10-05 21:00:00');
    sessionInCohort($this->cohort, riyadhAt('2026-10-05 18:00:00'), $end);

    expect(warn($this, $end))->toBe(0)
        ->and(warn($this, $end->addSecond()))->toBe(1);
});

it('BR-26: نسبة عند الحدّ تمامًا لا تُنبِّه', function (): void {
    Event::fake([AttendanceLow::class]);
    attendanceCohort($this, attended: 3, sessions: 4); // 75% = the threshold

    expect(warn($this, riyadhAt('2026-10-09 22:00:00')))->toBe(0);
    Event::assertNotDispatched(AttendanceLow::class);
});

it('BR-26: التعافي يعيد التسليح، ونزول ثانٍ يُنبَّه مرة أخرى', function (): void {
    Event::fake([AttendanceLow::class]);
    attendanceCohort($this, attended: 0, sessions: 1);
    $session = Session::query()->sole();

    expect(warn($this, riyadhAt('2026-10-05 22:00:00')))->toBe(1);

    // The absence was excused by hand to present: 1 of 1.
    App\Models\Attendance::query()->where('session_id', $session->id)->update(['status' => 'present']);
    expect(warn($this, riyadhAt('2026-10-05 23:00:00')))->toBe(0)
        ->and(Enrollment::query()->where('user_id', $this->participant->id)->sole()->attendance_low_notified_at)->toBeNull();

    // A second session, missed: 1 of 2.
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'absent', 1);
    expect(warn($this, riyadhAt('2026-10-06 22:00:00')))->toBe(1);

    Event::assertDispatchedTimes(AttendanceLow::class, 2);
});

it('BR-26: رفع الحد الأدنى فوق نسبة متدرّب يُنبِّهه', function (): void {
    Event::fake([AttendanceLow::class]);
    attendanceCohort($this, attended: 3, sessions: 4); // 75%

    expect(warn($this, riyadhAt('2026-10-09 22:00:00')))->toBe(0);

    $this->cohort->update(['min_attendance_rate' => 80]);

    expect(warn($this, riyadhAt('2026-10-09 23:00:00')))->toBe(1);
});

it('BR-26: الرسالة تذكر النسبة كما تعرضها شاشة الحضور', function (): void {
    Event::fake([AttendanceLow::class]);
    attendanceCohort($this, attended: 2, sessions: 3);
    $at = riyadhAt('2026-10-07 22:00:00');

    warn($this, $at);

    $expected = Present::decimal(app(CertificateEligibility::class)->attendanceRate($this->participant, $this->cohort, $at));

    Event::assertDispatched(AttendanceLow::class, fn (AttendanceLow $e): bool => $e->currentRate === $expected
        && $e->requiredRate === Present::decimal(75.0));
});

it('BR-26: متدرّب منسحب لا يُنبَّه، ودفعة غير جارية لا تُنبِّه أحدًا', function (): void {
    Event::fake([AttendanceLow::class]);
    attendanceCohort($this, attended: 0, sessions: 2);
    Enrollment::query()->where('user_id', $this->participant->id)->update(['status' => 'withdrawn']);

    expect(warn($this, riyadhAt('2026-10-06 22:00:00')))->toBe(0);

    attendanceCohort($this, attended: 0, sessions: 2, cohort: ['status' => CohortStatus::Completed->value]);
    expect(warn($this, riyadhAt('2026-10-06 22:00:00')))->toBe(0);
});

it('BR-26: نزول بعد آخر جلسة يُكتب بكلماته — لا وعد بجلسات قادمة', function (): void {
    Mail::fake();
    attendanceCohort($this, attended: 0, sessions: 2);

    warn($this, riyadhAt('2026-10-06 22:00:00')); // both sessions over, none remain

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.attendance_low_final');
    Mail::assertNotQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.attendance_low');
});

it('BR-26: المسوِّي نفسه يستدعي التنبيه بعد إعادة حساب النسب', function (): void {
    Event::fake([AttendanceLow::class]);
    attendanceCohort($this, attended: 0, sessions: 2);

    app(App\Services\Attendance\AttendanceReconciler::class)->run(riyadhAt('2026-10-06 22:00:00'), 30);

    Event::assertDispatchedTimes(AttendanceLow::class, 1);
});

/*
|--------------------------------------------------------------------------
| Certificate issued
|--------------------------------------------------------------------------
*/

it('BR-25: الإصدار يُعلن الشهادة برقمها المحفوظ ورابط تحقّقها، وزرّ الرسالة يفتح صفحة تجيب 200', function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));
    Event::fake([CertificateIssued::class]);
    $cohort = makeCohort(['pass_score' => 60, 'min_attendance_rate' => 75]);
    $participant = makeParticipant($cohort);
    attendSessions($cohort, $participant, 8, 8);
    awardFinalScore($cohort, $participant, 95.0);

    assertAccepted($this->actingAs(makeAdmin())->post(route('admin.certificates.issue'), [
        'user_id' => $participant->id,
        'cohort_id' => $cohort->id,
    ]));

    $certificate = Certificate::query()->sole();

    Event::assertDispatched(CertificateIssued::class, fn (CertificateIssued $e): bool => $e->serial === $certificate->serial_number
        && $e->verifyUrl === route('certificate.verify', ['code' => $certificate->verify_code])
        && $e->url === route('certificate'));

    expect(Notification::query()->where('user_id', $participant->id)->where('type', 'certificate_issued')->count())->toBe(1);

    $this->actingAs($participant)->get(route('certificate'))->assertOk();
});

it('BR-26: إصدار مرفوض لا يُعلن شيئًا', function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));
    Event::fake([CertificateIssued::class]);
    $cohort = makeCohort(['pass_score' => 60, 'min_attendance_rate' => 75]);
    $participant = makeParticipant($cohort);
    attendSessions($cohort, $participant, 8, 2);

    $this->actingAs(makeAdmin())->post(route('admin.certificates.issue'), [
        'user_id' => $participant->id,
        'cohort_id' => $cohort->id,
    ]);

    Event::assertNotDispatched(CertificateIssued::class);
});

/*
|--------------------------------------------------------------------------
| Final project opened
|--------------------------------------------------------------------------
*/

it('D-77: فتح المشروع الختامي يُعلَن مرة، وإعادة فتحه المفتوح لا تكتب إشعارًا ثانيًا، والإغلاق صامت', function (): void {
    freezeAt(riyadhAt('2026-11-01 12:00:00'));
    Event::fake([FinalProjectUnlocked::class]);
    $cohort = makeCohort();
    $trainer = makeTrainer($cohort);
    $participant = makeParticipant($cohort);
    $project = makeFinalProject($cohort, ['is_unlocked' => false]);

    $this->actingAs($trainer)->put(route('trainer.finalProject.unlock', $project), ['is_unlocked' => true]);
    $this->actingAs($trainer)->put(route('trainer.finalProject.unlock', $project), ['is_unlocked' => true]);
    $this->actingAs($trainer)->put(route('trainer.finalProject.unlock', $project), ['is_unlocked' => false]);

    Event::assertDispatchedTimes(FinalProjectUnlocked::class, 1);
    expect(Notification::query()->where('user_id', $participant->id)->where('type', 'final_project_unlocked')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Session cancelled
|--------------------------------------------------------------------------
*/

it('D-77: إلغاء جلسة يُعلَن للدفعة بسببه مرة واحدة، وبكلمات الإلغاء لا «تغيّر الموعد»', function (): void {
    freezeAt(riyadhAt('2026-10-01 12:00:00'));
    Mail::fake();
    $cohort = makeCohort();
    $trainer = makeTrainer($cohort);
    $participant = makeParticipant($cohort);
    $session = sessionInCohort($cohort, riyadhAt('2026-10-05 18:00:00'), riyadhAt('2026-10-05 21:00:00'), ['title' => 'CANARY-SESSION']);

    $reason = 'The trainer is travelling for a conference.';
    $this->actingAs($trainer)->post(route('trainer.sessions.cancel', $session), ['cancel_reason' => $reason]);
    $this->actingAs($trainer)->post(route('trainer.sessions.cancel', $session), ['cancel_reason' => $reason]);

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_cancelled'
        && $l->hasTo($participant->email)
        && ($l->values['reason'] ?? null) === $reason);
    expect(Mail::queued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_cancelled')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $participant->id)->where('type', 'session_cancelled')->count())->toBe(1);
    Mail::assertNotQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_changed');
});

it('D-77: محرّر الجلسة لا يغيّر الحالة في أيّ اتجاه', function (): void {
    freezeAt(riyadhAt('2026-10-01 12:00:00'));
    Event::fake([SessionCancelled::class]);
    $cohort = makeCohort();
    $trainer = makeTrainer($cohort);
    $scheduled = sessionInCohort($cohort, riyadhAt('2026-10-05 18:00:00'), riyadhAt('2026-10-05 21:00:00'));
    $cancelled = sessionInCohort($cohort, riyadhAt('2026-10-06 18:00:00'), riyadhAt('2026-10-06 21:00:00'), ['status' => 'cancelled']);

    $rules = (new App\Http\Requests\Trainer\UpdateSessionRequest)->rules();
    expect(array_key_exists('status', $rules))->toBeFalse();

    foreach ([[$scheduled, 'cancelled'], [$cancelled, 'scheduled']] as [$session, $status]) {
        $before = $session->fresh()?->status;
        $this->actingAs($trainer)->patch(route('trainer.sessions.update', $session), ['status' => $status]);
        expect($session->fresh()?->status)->toBe($before);
    }

    Event::assertNotDispatched(SessionCancelled::class);
});

/*
|--------------------------------------------------------------------------
| A sign-in from a new device
|--------------------------------------------------------------------------
*/

it('D-77: أول جهاز للحساب لا يُنبِّه، وجهاز ثانٍ يُنبِّه بعنوانه ولحظة الخادم، وعودة الجهاز نفسه صامتة', function (): void {
    freezeAt(riyadhAt('2026-10-01 12:00:00'));
    Event::fake([NewDeviceLogin::class]);
    $user = withPassword(makeParticipant(makeCohort(), ['email' => 'device@example.com']), 'Canary-Device-1!');
    $credentials = ['email' => 'device@example.com', 'password' => 'Canary-Device-1!'];

    // First browser — the account's first device.
    $first = $this->post(route('login.store'), $credentials);
    $cookie = collect($first->headers->getCookies())->first(fn ($c) => $c->getName() === KnownDevices::COOKIE);
    expect($cookie)->not->toBeNull();
    Event::assertNotDispatched(NewDeviceLogin::class);

    // The same browser again, carrying its cookie: silent.
    $this->post(route('logout'));
    $this->withUnencryptedCookie(KnownDevices::COOKIE, $cookie->getValue())->post(route('login.store'), $credentials);
    Event::assertNotDispatched(NewDeviceLogin::class);

    // A second browser, with no cookie: told.
    $this->post(route('logout'));
    $this->withUnencryptedCookie(KnownDevices::COOKIE, '')->post(route('login.store'), $credentials);
    Event::assertDispatched(NewDeviceLogin::class, fn (NewDeviceLogin $e): bool => $e->user->is($user)
        && $e->at->equalTo(riyadhAt('2026-10-01 12:00:00')));
});

it('D-77: كلمة مرور خاطئة لا تُنبِّه ولا تسجّل جهازًا', function (): void {
    Event::fake([NewDeviceLogin::class]);
    withPassword(makeParticipant(makeCohort(), ['email' => 'device@example.com']), 'Canary-Device-1!');

    $this->post(route('login.store'), ['email' => 'device@example.com', 'password' => 'wrong-password-1!']);

    Event::assertNotDispatched(NewDeviceLogin::class);
    expect(App\Models\KnownDevice::query()->count())->toBe(0);
});

it('D-77: حسابان يتناوبان على متصفح واحد لا يُنبِّه أيٌّ منهما بعد أوّل مرة لكلٍّ هناك', function (): void {
    $at = riyadhAt('2026-10-01 12:00:00');
    $devices = app(KnownDevices::class);
    $a = makeParticipant();
    $b = makeParticipant();
    $token = str_repeat('a', 64);

    // Each already has another device elsewhere.
    foreach ([$a, $b] as $u) {
        App\Models\KnownDevice::query()->create([
            'user_id' => $u->id, 'token_hash' => hash('sha256', str_repeat('f', 64)),
            'first_seen_at' => $at, 'last_seen_at' => $at,
        ]);
    }

    $lab = fn (): Illuminate\Http\Request => Illuminate\Http\Request::create('/login', 'POST', [], [KnownDevices::COOKIE => $token]);

    expect($devices->recordSignIn($a, $lab(), $at))->toBeTrue()   // new to A
        ->and($devices->recordSignIn($b, $lab(), $at))->toBeTrue() // new to B
        ->and($devices->recordSignIn($a, $lab(), $at))->toBeFalse()
        ->and($devices->recordSignIn($b, $lab(), $at))->toBeFalse();
});
