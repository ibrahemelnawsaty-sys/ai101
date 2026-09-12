<?php

declare(strict_types=1);

/**
 * Switching e-mail off for a kind of letter stops that letter.
 *
 * WHY THIS SUITE EXISTS
 * The preferences screen wrote `notification_preferences.email_enabled`, and no
 * sender read it: a trainee who switched grade letters off kept receiving them
 * (D-66). These cases send through the real listeners, so a sender that forgets
 * to ask is caught here rather than in someone's inbox.
 *
 * @see PRD §9.16, §9.16.1 · BR-33 · D-66
 */

use App\Events\GradeRecorded;
use App\Mail\AtharLetter;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Mail\CohortAudience;
use App\Services\Mail\MailPreferences;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));

    $this->cohort = makeCohort();
    $this->keeps = makeParticipant($this->cohort);
    $this->optsOut = makeParticipant($this->cohort);
});

function switchEmail(User $user, string $type, bool $email, bool $platform = true): void
{
    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'type' => $type,
        'in_app_enabled' => $platform,
        'email_enabled' => $email,
    ]);
}

it('D-66: لا صفّ تفضيل يعني أن البريد يُرسَل', function (): void {
    expect(app(MailPreferences::class)->allows($this->keeps, 'grade_recorded'))->toBeTrue();
});

it('D-66: إيقاف بريد نوعٍ يمنعه، ولا يمسّ نوعًا آخر', function (): void {
    switchEmail($this->optsOut, 'grade_recorded', false);

    $preferences = app(MailPreferences::class);

    expect($preferences->allows($this->optsOut, 'grade_recorded'))->toBeFalse()
        ->and($preferences->allows($this->optsOut, 'grade_revised'))->toBeTrue();
});

it('D-66: قرار الالتحاق والشهادة لا يُكتمان ولو وُجد صفّ يوقفهما', function (): void {
    switchEmail($this->optsOut, 'certificate_issued', false, false);
    switchEmail($this->optsOut, 'enrollment_approved', false, false);

    $preferences = app(MailPreferences::class);

    expect($preferences->allows($this->optsOut, 'certificate_issued'))->toBeTrue()
        ->and($preferences->allows($this->optsOut, 'enrollment_approved'))->toBeTrue();
});

it('D-66: رسالة الدرجة لا تصل مَن أوقفها وتصل غيره — عبر المستمع الحقيقي', function (): void {
    Mail::fake();
    switchEmail($this->optsOut, 'grade_recorded', false);

    GradeRecorded::dispatch($this->keeps, 'Assignment 1', '8', '10');
    GradeRecorded::dispatch($this->optsOut, 'Assignment 1', '8', '10');

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $letter): bool => $letter->hasTo($this->keeps->email));
    Mail::assertNotQueued(AtharLetter::class, fn (AtharLetter $letter): bool => $letter->hasTo($this->optsOut->email));
});

it('D-66: جمهور الدفعة يُرشَّح بالنوع، وبلا نوع يبقى كما كان', function (): void {
    switchEmail($this->optsOut, 'assignment_published', false);

    $audience = app(CohortAudience::class);
    $cohortId = (string) $this->cohort->getKey();

    expect($audience->reachable($cohortId))->toHaveCount(2)
        ->and($audience->reachable($cohortId, 'assignment_published')->map->getKey()->all())
        ->toBe([$this->keeps->getKey()]);
});
