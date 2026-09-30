<?php

declare(strict_types=1);

/**
 * Phase 5 — «why not eligible» worded for the staff who read it.
 *
 * CertificateEligibility words its reasons to the participant («your attendance is
 * 33%»). The administrator's list of who falls short prints them beside a name, and
 * the trainer's at-risk list does too, where «your» is nobody's. The staff screens
 * word the same reasons from a parallel set (StaffReasons); the conditions, their
 * order and every number stay the service's.
 *
 * @see BR-26 · PRD §9.17 · D-147
 */

use App\Presenters\Support\StaffReasons;
use App\Services\Certificates\CertificateEligibility;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60, 'min_attendance_rate' => 75, 'status' => 'completed']);
    $this->admin = makeAdmin();
    $this->person = makeParticipant($this->cohort);

    attendSessions($this->cohort, $this->person, 8, 2);
    awardFinalScore($this->cohort, $this->person, 30.0);
});

it('BR-26: صياغة الطاقم تحمل الشروط والأرقام نفسها التي تحملها صياغة المتدرب وبالترتيب نفسه', function (): void {
    $eligibility = app(CertificateEligibility::class);

    $staff = StaffReasons::of($eligibility, $this->person, $this->cohort);
    $participant = $eligibility->reasons($this->person, $this->cohort);

    expect(array_keys($staff))->toBe(array_keys($participant))
        ->and(array_keys($staff))->toBe(['attendance', 'score']);

    foreach ($participant as $condition => $line) {
        preg_match_all('/\d+(?:\.\d+)?/', $line, $expected);
        preg_match_all('/\d+(?:\.\d+)?/', $staff[$condition], $actual);

        expect($actual[0])->toBe($expected[0])
            ->and($staff[$condition])->not->toBe($line);
    }
});

it('BR-26: لا كلمة «حضورك» ولا «درجتك» في صياغة الطاقم', function (): void {
    $lines = implode(' ', StaffReasons::of(app(CertificateEligibility::class), $this->person, $this->cohort));

    expect($lines)->not->toContain('حضورك')
        ->and($lines)->not->toContain('درجتك')
        ->and($lines)->not->toContain('مشروعك');
});

it('BR-26: قائمة غير المستوفين عند المشرف تعرض الصياغة المحايدة لا صياغة المتدرب', function (): void {
    $html = $this->actingAs($this->admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('نسبة حضورك')
        ->and($html)->not->toContain('درجتك النهائية')
        ->and($html)->toContain('نسبة الحضور');
});

it('BR-26: قائمة المعرَّضين للخطر عند المدرب تعرض الصياغة المحايدة كذلك', function (): void {
    $trainer = makeTrainer($this->cohort);

    $html = $this->actingAs($trainer)
        ->get(route('trainer.reports', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('نسبة حضورك')
        ->and($html)->not->toContain('درجتك النهائية');
});

it('BR-26: صفحة شهادة المتدرب نفسها تبقى بصوته — «حضورك»', function (): void {
    $html = $this->actingAs($this->person)
        ->get(route('certificate'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('حضورك');
});
