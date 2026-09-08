<?php

declare(strict_types=1);

/**
 * Article 17 for the trainer and administrator areas.
 *
 * The empty state matters more here than anywhere else: a trainer opening an empty
 * submissions queue must be told the queue is empty, not shown a blank panel that
 * looks like a failure.
 *
 * The trainer area has no per-cohort URL segment. EnsureCohortScope reads `?cohort=`
 * and decides, from the enrolments table, which single cohort this request may act on
 * (BR-23), so the cohort roster is `trainer.participants?cohort=`, the attendance
 * board is `trainer.attendance?cohort=` and one submission is selected on the
 * submissions board with `trainer.submissions?cohort=&submission=`. There is no
 * `trainer.cohorts.show` and no `trainer.submissions.show`.
 *
 * @see CONSTITUTION.md Article 17 · PRD §9.9.7, §9.18
 */

use App\Models\ImpersonationSession;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->admin = makeAdmin();
    $this->participant = makeParticipant($this->cohort);
});

/*
|--------------------------------------------------------------------------
| Trainer
|--------------------------------------------------------------------------
*/

it('شاشة الدفعة للمدرب: الحالة العادية تعرض متدربي الدفعة', function (): void {
    $body = $this->actingAs($this->trainer)
        ->get(route('trainer.participants', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة الدفعة للمدرب: الحالة الفارغة حين لا متدرب ملتحق', function (): void {
    $emptyCohort = makeCohort();
    enroll($this->trainer, $emptyCohort, 'trainer');

    $body = $this->actingAs($this->trainer)
        ->get(route('trainer.participants', ['cohort' => $emptyCohort->id]))
        ->assertOk()
        ->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة حضور الدفعة للمدرب: الحالة الفارغة حين لا جلسات بعد', function (): void {
    $body = $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect($body)->toBeScreenState('empty');
});

it('شاشة حضور الدفعة للمدرب: الحالة العادية بعد انعقاد جلسة', function (): void {
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present');

    $body = $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شاشة تسليم للمدرب: الحالة العادية تعرض التسليم وملاحظته', function (): void {
    $assignment = makeAssignment($this->cohort, ['max_score' => 10]);
    $submission = makeSubmission($assignment, $this->participant, ['note' => 'CANARY-SUBMISSION-NOTE']);

    $body = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', [
            'cohort' => $this->cohort->id,
            'submission' => $submission->id,
        ]))
        ->assertOk()
        ->getContent();

    expect($body)->toBeScreenState('normal')
        ->and($body)->toContain('CANARY-SUBMISSION-NOTE');
});

it('شاشة تسليم للمدرب: حالة الخطأ لتسليم خارج دفعته صفحة عربية بلا تفاصيل تقنية', function (): void {
    $foreignCohort = makeCohort();
    $foreignAssignment = makeAssignment($foreignCohort, ['max_score' => 10]);
    $foreignSubmission = makeSubmission($foreignAssignment, makeParticipant($foreignCohort), [
        'note' => 'CANARY-FOREIGN-NOTE',
    ]);

    $response = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', [
            'cohort' => $foreignCohort->id,
            'submission' => $foreignSubmission->id,
        ]));

    $response->assertForbidden();

    expect($response->getContent())->not->toContain('CANARY-FOREIGN-NOTE')
        ->and($response->getContent())->not->toContain('vendor/laravel')
        ->and($response->getContent())->toContain('dir="rtl"');
});

/*
|--------------------------------------------------------------------------
| Administrator
|--------------------------------------------------------------------------
*/

it('لوحة المدير: الحالة العادية تعرض البطاقات الإحصائية', function (): void {
    $body = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

    expect($body)->toBeScreenState('normal');
});

it('شريط المعاينة ظاهر طوال جلسة المعاينة مع زر الإنهاء', function (): void {
    $this->actingAs($this->admin)->post(route('admin.users.preview', $this->participant));

    $body = $this->get(route('dashboard'))->assertOk()->getContent();

    expect($body)->toContain('data-impersonation-bar')
        ->and($body)->toContain(route('admin.impersonation.stop'));

    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(1);
});

it('شريط المعاينة لا يظهر في التصفح العادي', function (): void {
    $body = $this->actingAs($this->participant)->get(route('dashboard'))->assertOk()->getContent();

    expect($body)->not->toContain('data-impersonation-bar');
});

it('كل شاشات المدرب والمدير تحمل وسم الشاشة والاتجاه الصحيح', function (): void {
    $pages = [
        [$this->trainer, route('trainer.participants', ['cohort' => $this->cohort->id])],
        [$this->trainer, route('trainer.attendance', ['cohort' => $this->cohort->id])],
        [$this->admin, route('dashboard')],
    ];

    foreach ($pages as [$actor, $url]) {
        $body = $this->actingAs($actor)->get($url)->assertOk()->getContent();

        expect($body)->toContain('data-screen=')
            ->and($body)->toContain('dir="rtl"')
            ->and($body)->toContain('lang="ar"');
    }
});
