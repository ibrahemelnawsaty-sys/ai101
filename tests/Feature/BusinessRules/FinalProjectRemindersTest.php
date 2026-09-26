<?php

declare(strict_types=1);

/**
 * The final project's deadline reminders (D-122): three days, two days and
 * one day before the deadline, then three on its last day — 12, 6 and 1 hour
 * before — to every participant who has not handed it in, on the platform and
 * by letter, each exactly once. Boundaries at ±1 second (CONSTITUTION Art. 20).
 *
 * @see PRD §9.14, §9.16.1 · D-83, D-122
 */

use App\Events\FinalProjectReminderDue;
use App\Listeners\SendFinalProjectReminder;
use App\Mail\AtharLetter;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\ScheduledNotices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-26 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->due = riyadhAt('2026-09-30 23:59:00');
    $this->project = makeFinalProject($this->cohort, [
        'title' => 'CANARY-PROJECT',
        'is_unlocked' => true,
        'due_at' => $this->due,
    ]);
});

function remindersAt(CarbonImmutable $at): array
{
    freezeAt($at);

    return app(ScheduledNotices::class)->run($at);
}

function projectReminders(User $user): int
{
    return Notification::query()->where('user_id', $user->id)->where('type', 'final_project_due_reminder')->count();
}

function projectReminderLetters(?User $to = null): int
{
    return Mail::queued(AtharLetter::class, static fn (AtharLetter $letter): bool => $letter->copyKey === 'emails.final_project_due_reminder'
        && ($to === null || $letter->hasTo($to->email)))->count();
}

it('D-122: ستة تذكيرات لمن لم يسلّم — قبل الموعد بـ72 و48 و24 ساعة ثم 12 و6 وساعة — كلٌّ عند علامته لا قبلها بثانية، ومرة واحدة', function (): void {
    $sent = 0;

    foreach ([72, 48, 24, 12, 6, 1] as $hours) {
        $mark = $this->due->subHours($hours);

        remindersAt($mark->subSecond());
        expect(projectReminders($this->participant))->toBe($sent, "{$hours}h fired a second early");

        expect(remindersAt($mark)['final_projects'])->toBe(1);
        $sent++;

        remindersAt($mark->addSecond());
        expect(projectReminders($this->participant))->toBe($sent, "{$hours}h did not fire exactly once")
            ->and(projectReminderLetters($this->participant))->toBe($sent);
    }

    // The deadline itself and after it: nothing more.
    remindersAt($this->due);
    remindersAt($this->due->addHour());

    expect(projectReminders($this->participant))->toBe(6)
        ->and(projectReminderLetters())->toBe(6);
});

it('D-122: التذكير يقول كم تبقّى بكلمات، ويدلّ على صفحة المشروع، والبريد يذكر الموعد النهائي', function (): void {
    remindersAt($this->due->subHours(72));

    $notice = Notification::query()->where('user_id', $this->participant->id)->where('type', 'final_project_due_reminder')->sole();

    expect($notice->body)->toContain('CANARY-PROJECT')
        ->and($notice->body)->not->toContain(':countdown')
        ->and($notice->link)->toBe(route('finalProject'));

    /** @var AtharLetter $letter */
    $letter = Mail::queued(AtharLetter::class)->first();

    expect($letter->render())->toContain('CANARY-PROJECT')
        ->and($letter->meta)->toHaveKey('assignments.deadline');
});

it('D-122: من سلّم لا يُذكَّر، ومن يسلّم بعد تذكير لا يصله الذي يليه', function (): void {
    $handedIn = makeParticipant($this->cohort);
    makeProjectSubmission($this->project, $handedIn);

    remindersAt($this->due->subHours(72));

    expect(projectReminders($handedIn))->toBe(0)
        ->and(projectReminderLetters($handedIn))->toBe(0)
        ->and(projectReminders($this->participant))->toBe(1);

    makeProjectSubmission($this->project, $this->participant);

    remindersAt($this->due->subHours(48));

    expect(projectReminders($this->participant))->toBe(1)
        ->and(projectReminderLetters($this->participant))->toBe(1);
});

it('D-122: المشروع المقفل، ومتدرب دفعة أخرى، والدفعة المنتهية — لا يصلهم شيء', function (): void {
    $otherCohort = makeCohort();
    $stranger = makeParticipant($otherCohort);

    $closedCohort = makeCohort(['status' => 'completed']);
    $graduate = makeParticipant($closedCohort);
    makeFinalProject($closedCohort, ['is_unlocked' => true, 'due_at' => $this->due]);

    $this->project->update(['is_unlocked' => false]);

    remindersAt($this->due->subHours(72));
    remindersAt($this->due->subHours(1));

    expect(projectReminders($this->participant))->toBe(0)
        ->and(projectReminders($stranger))->toBe(0)
        ->and(projectReminders($graduate))->toBe(0);
    Mail::assertNothingQueued();
});

it('D-122: ما فاتت مهلته يُترك — تذكير الساعة الأخيرة لا يصل في ربع الساعة الأخير', function (): void {
    remindersAt($this->due->subHours(1)->addMinutes(15));
    remindersAt($this->due->subMinutes(5));

    expect(projectReminders($this->participant))->toBe(0);
});

it('D-122: من أطفأ بريد التذكير يصله الجرس وحده، ومن أطفأ الجرس يصله البريد وحده', function (): void {
    $bellOnly = $this->participant;
    $mailOnly = makeParticipant($this->cohort);

    NotificationPreference::factory()->create(['user_id' => $bellOnly->id, 'type' => 'final_project_due_reminder', 'email_enabled' => false, 'in_app_enabled' => true]);
    NotificationPreference::factory()->create(['user_id' => $mailOnly->id, 'type' => 'final_project_due_reminder', 'email_enabled' => true, 'in_app_enabled' => false]);

    remindersAt($this->due->subHours(24));

    expect(projectReminders($bellOnly))->toBe(1)
        ->and(projectReminderLetters($bellOnly))->toBe(0)
        ->and(projectReminders($mailOnly))->toBe(0)
        ->and(projectReminderLetters($mailOnly))->toBe(1);
});

it('D-122: بريد التذكير يُقرأ حين يدور الطابور — بعد الموعد بثانية أو والمشروع مقفل لا يُرسل، وعند الموعد نفسه يُرسل', function (): void {
    $event = new FinalProjectReminderDue(
        cohortId: (string) $this->cohort->id,
        projectId: (string) $this->project->id,
        projectTitle: 'CANARY-PROJECT',
        dueAtIso: $this->due->toIso8601ZuluString(),
        dueAtLabel: 'CANARY-DEADLINE',
    );
    $listener = app(SendFinalProjectReminder::class);

    freezeAt($this->due->addSecond());
    $listener->handle($event);

    expect(projectReminderLetters())->toBe(0);

    freezeAt($this->due->subHour());
    $this->project->update(['is_unlocked' => false]);
    $listener->handle($event);

    expect(projectReminderLetters())->toBe(0);

    $this->project->update(['is_unlocked' => true]);
    freezeAt($this->due);
    $listener->handle($event);

    expect(projectReminderLetters($this->participant))->toBe(1);
});

it('D-122: تغيير الموعد يعيد التذكيرات للموعد الجديد', function (): void {
    remindersAt($this->due->subHours(72));

    $later = $this->due->addDays(2);
    $this->project->update(['due_at' => $later]);

    remindersAt($later->subHours(72));

    expect(projectReminders($this->participant))->toBe(2);
});

it('D-122: أمر التذكيرات يعدّ تذكيرات المشروع الختامي', function (): void {
    freezeAt($this->due->subHours(72));

    $this->artisan('athar:send-reminders')
        ->expectsOutputToContain('1 final project notice(s)')
        ->assertSuccessful();

    expect(projectReminders($this->participant))->toBe(1);
});
