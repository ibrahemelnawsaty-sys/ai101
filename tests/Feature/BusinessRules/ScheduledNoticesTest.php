<?php

declare(strict_types=1);

/**
 * The notices the calendar sends on its own (PRD §9.16.1).
 *
 * WHY THIS SUITE EXISTS
 * The matrix promised a reminder a day and an hour before every session, a
 * notice when it starts, and two deadline reminders to whoever has not handed
 * in. Nothing sent any of them — the only scheduled task was attendance — and
 * the preferences screen offered switches for notices that never came (D-83).
 *
 * Each mark is tested at −1 s, at the mark, and at the edge of its grace.
 *
 * @see PRD §9.10, §9.16.1 · FR-NOTIF-10, FR-NOTIF-11, FR-NOTIF-14 · D-83
 */

use App\Mail\AtharLetter;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Profile;
use App\Models\User;
use App\Services\Notifications\ScheduledNotices;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->start = riyadhAt('2026-10-12 18:00:00');
    $this->session = sessionInCohort($this->cohort, $this->start, $this->start->addHours(3), [
        'title' => 'CANARY-SESSION',
        'trainer_id' => $this->trainer->id,
    ]);
});

function runNoticesAt(CarbonImmutable $at): array
{
    freezeAt($at);

    return app(ScheduledNotices::class)->run($at);
}

function noticeCount(User $user, string $type): int
{
    return Notification::query()->where('user_id', $user->id)->where('type', $type)->count();
}

function lettersOf(string $copyKey): int
{
    return Mail::queued(AtharLetter::class, static fn (AtharLetter $l): bool => $l->copyKey === $copyKey)->count();
}

/*
|--------------------------------------------------------------------------
| Session reminders and the start notice
|--------------------------------------------------------------------------
*/

it('FR-NOTIF-10: حدود نافذة كل علامة بدقة الثانية — قبلها بثانية لا، عندها نعم، عند نهاية مهلتها لا', function (): void {
    $target = $this->start;

    foreach ([...ScheduledNotices::SESSION_MARKS, ...ScheduledNotices::ASSIGNMENT_MARKS] as $kind => [$before, $grace]) {
        $mark = $target->subMinutes($before);

        expect(ScheduledNotices::isDue($target, $before, $grace, $mark->subSecond()))->toBeFalse("{$kind} at mark-1s")
            ->and(ScheduledNotices::isDue($target, $before, $grace, $mark))->toBeTrue("{$kind} at mark")
            ->and(ScheduledNotices::isDue($target, $before, $grace, $mark->addMinutes($grace)->subSecond()))->toBeTrue("{$kind} at grace-1s")
            ->and(ScheduledNotices::isDue($target, $before, $grace, $mark->addMinutes($grace)))->toBeFalse("{$kind} at grace end");
    }
});

it('FR-NOTIF-10: تذكير اليوم السابق يصل عند S-24h لا قبلها بثانية، على المنصة وبالبريد، ومرة واحدة', function (): void {
    Profile::factory()->create(['user_id' => $this->trainer->id, 'first_name_ar' => 'CANARYCOACH']);

    runNoticesAt($this->start->subHours(24)->subSecond());
    expect(noticeCount($this->participant, 'session_reminder'))->toBe(0)
        ->and(lettersOf('emails.session_reminder'))->toBe(0);

    runNoticesAt($this->start->subHours(24));
    runNoticesAt($this->start->subHours(24)->addSecond());
    runNoticesAt($this->start->subHours(24)->addMinutes(30));

    expect(noticeCount($this->participant, 'session_reminder'))->toBe(1)
        ->and(lettersOf('emails.session_reminder'))->toBe(1);

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_reminder'
        && $l->hasTo($this->participant->email)
        && $l->values['when'] === __('emails.session_reminder.when_24h')
        && str_contains((string) $l->values['trainer'], 'CANARYCOACH')
        && $l->values['session'] === 'CANARY-SESSION');

    // The trainer is not reminded of their own session as a participant.
    expect(noticeCount($this->trainer, 'session_reminder'))->toBe(0);
});

it('FR-NOTIF-10: تذكير الساعة يصل عند S-1h بكلماته، وبلا مدرّب مسمّى يقول «مدربك»', function (): void {
    $this->session->forceFill(['trainer_id' => null])->save();

    runNoticesAt($this->start->subHour()->subSecond());
    expect(noticeCount($this->participant, 'session_reminder'))->toBe(0);

    runNoticesAt($this->start->subHour());
    runNoticesAt($this->start->subMinutes(50));

    expect(noticeCount($this->participant, 'session_reminder'))->toBe(1)
        ->and(lettersOf('emails.session_reminder'))->toBe(1);

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_reminder'
        && $l->values['when'] === __('emails.session_reminder.when_1h')
        && $l->values['trainer'] === __('emails.session_reminder.trainer_fallback'));
});

it('FR-NOTIF-11: إشعار «بدأت الجلسة» عند S لا قبلها بثانية، على المنصة وحدها', function (): void {
    runNoticesAt($this->start->subSecond());
    expect(noticeCount($this->participant, 'session_started'))->toBe(0);

    runNoticesAt($this->start);
    runNoticesAt($this->start->addMinutes(5));

    expect(noticeCount($this->participant, 'session_started'))->toBe(1);
    Mail::assertNothingQueued();
});

it('FR-NOTIF-10: ما فاتت مهلته يُترك ولا يُرسل متأخرًا بكلمات لم تعد صحيحة', function (): void {
    runNoticesAt($this->start->subHours(23));          // the 24-hour grace has closed
    runNoticesAt($this->start->subMinutes(45));        // the one-hour grace has closed
    runNoticesAt($this->start->addMinutes(15));        // the start grace has closed

    expect(noticeCount($this->participant, 'session_reminder'))->toBe(0)
        ->and(noticeCount($this->participant, 'session_started'))->toBe(0);
    Mail::assertNothingQueued();
});

it('FR-NOTIF-10: الجلسة الملغاة، والمنسحب، ومتدرّب دفعة أخرى، والدفعة المنتهية — لا يصلهم شيء', function (): void {
    $withdrawn = makeParticipant($this->cohort);
    Enrollment::query()->where('user_id', $withdrawn->id)->update(['status' => 'withdrawn']);

    $other = makeCohort();
    $outsider = makeParticipant($other);

    $done = makeCohort(['status' => 'completed']);
    $alumnus = makeParticipant($done);
    sessionInCohort($done, $this->start, $this->start->addHours(3));

    $cancelledStart = $this->start->addHours(4);
    $cancelled = sessionInCohort($this->cohort, $cancelledStart, $cancelledStart->addHour(), ['status' => 'cancelled']);

    runNoticesAt($this->start->subHours(24));
    runNoticesAt($cancelledStart->subHours(24));

    expect(noticeCount($this->participant, 'session_reminder'))->toBe(1)
        ->and(noticeCount($withdrawn, 'session_reminder'))->toBe(0)
        ->and(noticeCount($outsider, 'session_reminder'))->toBe(0)
        ->and(noticeCount($alumnus, 'session_reminder'))->toBe(0)
        ->and(Notification::query()->where('type', 'session_reminder')->count())->toBe(1)
        ->and($cancelled->fresh()->isCancelled())->toBeTrue();
});

it('FR-NOTIF-10: الجلسة المنقولة تُذكَّر من جديد بموعدها الجديد', function (): void {
    runNoticesAt($this->start->subHours(24));

    $moved = $this->start->addDay();
    $this->session->forceFill(['date' => $moved->setTimezone('Asia/Riyadh')->toDateString()])->save();

    runNoticesAt($moved->subHours(24));

    expect(noticeCount($this->participant, 'session_reminder'))->toBe(2);
});

it('FR-NOTIF-10: من أطفأ بريد التذكير يصله الجرس وحده، ومن أطفأ الجرس يصله البريد وحده', function (): void {
    $noMail = makeParticipant($this->cohort);
    $noBell = makeParticipant($this->cohort);
    NotificationPreference::factory()->create(['user_id' => $noMail->id, 'type' => 'session_reminder', 'email_enabled' => false]);
    NotificationPreference::factory()->create(['user_id' => $noBell->id, 'type' => 'session_reminder', 'in_app_enabled' => false]);

    runNoticesAt($this->start->subHours(24));

    expect(noticeCount($noMail, 'session_reminder'))->toBe(1)
        ->and(noticeCount($noBell, 'session_reminder'))->toBe(0);
    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_reminder' && $l->hasTo($noBell->email));
    Mail::assertNotQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_reminder' && $l->hasTo($noMail->email));
});

it('D-83: تشغيل يفشل في منتصفه لا يترك حجزًا، والدقيقة التالية ترسل مرة واحدة', function (): void {
    Log::spy();

    // The platform notice cannot be written: the claim must roll back with it.
    Schema::rename('notifications', 'notifications_away');
    runNoticesAt($this->start->subHours(24));
    Schema::rename('notifications_away', 'notifications');

    expect(DB::table('scheduled_notices')->count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => $message === 'notices.scheduled_failed');

    runNoticesAt($this->start->subHours(24)->addMinute());
    runNoticesAt($this->start->subHours(24)->addMinutes(2));

    expect(noticeCount($this->participant, 'session_reminder'))->toBe(1)
        ->and(DB::table('scheduled_notices')->count())->toBe(1);
});

it('D-83: عنوان لا يُراسَل يُسجَّل ولا يوقف الباقين — والإشعار على المنصة يُكتب', function (): void {
    Log::spy();
    makeParticipant($this->cohort);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp unreachable'));

    runNoticesAt($this->start->subHours(24));

    expect(Notification::query()->where('type', 'session_reminder')->count())->toBe(2)
        ->and(DB::table('scheduled_notices')->count())->toBe(1);
    Log::shouldHaveReceived('warning')->twice()->withArgs(static fn (string $message): bool => $message === 'mail.scheduled_notice_failed');
});

it('D-83: جلسات دفعة قادمة تُذكَّر — مقاعدها لمن يبدأ غدًا', function (): void {
    $upcoming = makeCohort(['status' => 'upcoming']);
    $seated = makeParticipant($upcoming);
    sessionInCohort($upcoming, $this->start, $this->start->addHours(2));

    runNoticesAt($this->start->subHours(24));

    expect(noticeCount($seated, 'session_reminder'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Assignment deadline reminders
|--------------------------------------------------------------------------
*/

it('FR-NOTIF-14: تذكير الموعد قبل 48 ساعة وقبل 6 ساعات لمن لم يسلّم وحده، ولا تكرار', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['title' => 'CANARY-TASK', 'due_at' => $due]);
    $handedIn = makeParticipant($this->cohort);
    makeSubmission($assignment, $handedIn);

    runNoticesAt($due->subHours(48)->subSecond());
    expect(noticeCount($this->participant, 'assignment_due_reminder'))->toBe(0);

    runNoticesAt($due->subHours(48));
    runNoticesAt($due->subHours(48)->addSecond());
    expect(noticeCount($this->participant, 'assignment_due_reminder'))->toBe(1)
        ->and(lettersOf('emails.assignment_due_reminder'))->toBe(1);

    runNoticesAt($due->subHours(6)->subSecond());
    runNoticesAt($due->subHours(6));
    runNoticesAt($due->subHours(6)->addMinutes(10));

    expect(noticeCount($this->participant, 'assignment_due_reminder'))->toBe(2)
        ->and(lettersOf('emails.assignment_due_reminder'))->toBe(2)
        ->and(noticeCount($handedIn, 'assignment_due_reminder'))->toBe(0);
    Mail::assertNotQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->hasTo($handedIn->email));
});

it('FR-NOTIF-14: المسودة لا تُذكَّر', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    makeAssignment($this->cohort, ['status' => 'draft', 'due_at' => $due]);

    runNoticesAt($due->subHours(48));
    runNoticesAt($due->subHours(6));

    expect(Notification::query()->where('type', 'assignment_due_reminder')->count())->toBe(0);
    Mail::assertNothingQueued();
});

/*
|--------------------------------------------------------------------------
| The schedule runs it
|--------------------------------------------------------------------------
*/

it('D-83: أمر التذكيرات مجدول كل دقيقة، ويعمل من سطر الأوامر', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn ($event): bool => str_contains((string) $event->command, 'athar:send-reminders'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');

    freezeAt($this->start->subHours(24));
    $this->artisan('athar:send-reminders')->assertSuccessful();

    expect(noticeCount($this->participant, 'session_reminder'))->toBe(1);
});
