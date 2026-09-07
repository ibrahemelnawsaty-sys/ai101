<?php

declare(strict_types=1);

/**
 * Certificate eligibility: two conditions, applied together, with no compensation
 * between them. Perfect attendance does not buy a failing grade, and a perfect grade
 * does not buy missed sessions.
 *
 * @see BR-26 · D-26 · PRD §9.17 · PROJECT-CONTRACT.md §8 · CONSTITUTION.md Article 20
 */

use App\Support\AttendanceCounting;

beforeEach(function () {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60, 'min_attendance_rate' => 75]);
    $this->participant = makeParticipant($this->cohort);
    $this->eligibility = certificateEligibility();
});

/*
|--------------------------------------------------------------------------
| Attendance rate
|--------------------------------------------------------------------------
*/

it('نسبة الحضور صفر قبل انعقاد أي جلسة', function () {
    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(0.0);
});

it('نسبة الحضور مئة بالمئة لمن حضر كل الجلسات', function () {
    attendSessions($this->cohort, $this->participant, 8, 8);

    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(100.0);
});

it('BR-02, BR-03: الحضور المتأخر يُحتسب حضورًا في النسبة', function () {
    attendSessions($this->cohort, $this->participant, 4, 4, 'late');

    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(100.0);
});

it('الغياب يخفض النسبة بمقدار حصته', function () {
    attendSessions($this->cohort, $this->participant, 8, 6);

    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(75.0);
});

it('BR-22: نسبة الحضور لا تتأثر بسجلات متدرب آخر', function () {
    $other = makeParticipant($this->cohort);
    $day = riyadhAt('2026-10-05 18:00:00');

    // Four shared sessions: one trainee attended all of them, the other none.
    for ($i = 0; $i < 4; $i++) {
        $start = $day->addDays($i);
        $session = makeSessionAt($start, $start->addHours(3), ['cohort_id' => $this->cohort->id]);

        makeAttendance($session, $this->participant, 'present');
        makeAttendance($session, $other, 'absent');
    }

    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(100.0)
        ->and($this->eligibility->attendanceRate($other, $this->cohort))->toBe(0.0);
});

it('BR-26: حد نسبة الحضور يُقرأ من الدفعة ويُختبر عند الحد بالضبط', function () {
    // 3 of 5 sessions is exactly 60%.
    attendSessions($this->cohort, $this->participant, 5, 3);
    $this->cohort->update(['min_attendance_rate' => 60]);

    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(60.0)
        ->and($this->eligibility->meetsAttendance($this->participant, $this->cohort))->toBeTrue();

    $this->cohort->update(['min_attendance_rate' => 61]);

    expect($this->eligibility->meetsAttendance($this->participant->fresh(), $this->cohort->fresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Score condition
|--------------------------------------------------------------------------
*/

it('BR-26: حد الدرجة يُقرأ من الدفعة ويُختبر عند الحد بالضبط', function () {
    awardFinalScore($this->cohort, $this->participant, 60.0);

    expect($this->eligibility->meetsScore($this->participant, $this->cohort))->toBeTrue();

    $this->cohort->update(['pass_score' => 61]);

    expect($this->eligibility->meetsScore($this->participant->fresh(), $this->cohort->fresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No compensation — the point of the whole class
|--------------------------------------------------------------------------
*/

it('BR-26: حضور كامل ودرجة راسبة لا يمنحان الشهادة', function () {
    attendSessions($this->cohort, $this->participant, 8, 8);
    awardFinalScore($this->cohort, $this->participant, 30.0);

    expect($this->eligibility->meetsAttendance($this->participant, $this->cohort))->toBeTrue()
        ->and($this->eligibility->meetsScore($this->participant, $this->cohort))->toBeFalse()
        ->and($this->eligibility->isEligible($this->participant, $this->cohort))->toBeFalse();
});

it('BR-26: درجة كاملة وحضور ناقص لا يمنحان الشهادة', function () {
    attendSessions($this->cohort, $this->participant, 8, 2);
    awardFinalScore($this->cohort, $this->participant, 100.0);

    expect($this->eligibility->meetsScore($this->participant, $this->cohort))->toBeTrue()
        ->and($this->eligibility->meetsAttendance($this->participant, $this->cohort))->toBeFalse()
        ->and($this->eligibility->isEligible($this->participant, $this->cohort))->toBeFalse();
});

it('BR-26: الشرطان معًا يمنحان الشهادة', function () {
    attendSessions($this->cohort, $this->participant, 8, 6);
    awardFinalScore($this->cohort, $this->participant, 60.0);

    expect($this->eligibility->isEligible($this->participant, $this->cohort))->toBeTrue()
        ->and($this->eligibility->reasons($this->participant, $this->cohort))->toBeEmpty();
});

it('BR-26: سقوط الشرطين معًا يُنتج سببين لا سببًا واحدًا', function () {
    attendSessions($this->cohort, $this->participant, 8, 2);
    awardFinalScore($this->cohort, $this->participant, 20.0);

    $reasons = $this->eligibility->reasons($this->participant, $this->cohort);

    expect($this->eligibility->isEligible($this->participant, $this->cohort))->toBeFalse()
        ->and($reasons)->toHaveCount(2)
        ->and(array_keys($reasons))->toContain('attendance')
        ->and(array_keys($reasons))->toContain('score');
});

it('سبب عدم الاستحقاق نص مترجم بأرقام لاتينية لا مفتاح ترجمة خام', function () {
    attendSessions($this->cohort, $this->participant, 8, 2);
    awardFinalScore($this->cohort, $this->participant, 90.0);

    $reasons = $this->eligibility->reasons($this->participant, $this->cohort);

    expect($reasons)->toHaveCount(1)
        ->and(array_keys($reasons))->toContain('attendance');

    foreach ($reasons as $reason) {
        expect($reason)->toBeString()
            ->and(trim($reason))->not->toBeEmpty()
            // A raw, unresolved translation key would look like "certificates.reasons.x".
            ->and($reason)->not->toMatch('/^[a-z_]+(\.[a-z_]+)+$/')
            ->and($reason)->toUseLatinNumerals();
    }
});

/*
|--------------------------------------------------------------------------
| Escalated, not assumed
|--------------------------------------------------------------------------
*/

/*
 * PRD §9.9.5 defines `excused` and `incomplete` as statuses but never states how
 * they weigh in the attendance rate. Constitution Article 4 forbids assuming a
 * rule in the attendance domain, so the question is escalated as D-26 and NOT
 * answered here.
 *
 * What these tests do is different from answering it: they pin the one reading
 * the repository currently holds, so that the seeder, the service and the screens
 * cannot quietly drift apart while the ruling is pending, and so that the day
 * D-26 is decided the wrong way, these two tests fail loudly instead of the
 * change slipping through. A skipped test pinned nothing.
 */

it('BR-26, D-26: الغياب بعذر يُحتسب حضورًا — القراءة المعلَّقة، لا حكمًا نهائيًّا', function () {
    $day = riyadhAt('2026-10-05 18:00:00');

    for ($i = 0; $i < 4; $i++) {
        $start = $day->addDays($i);
        $session = makeSessionAt($start, $start->addHours(3), ['cohort_id' => $this->cohort->id]);

        makeAttendance($session, $this->participant, $i < 2 ? 'present' : 'excused');
    }

    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(100.0);
});

it('BR-26, D-26: الحضور غير المكتمل لا يُحتسب — القراءة المعلَّقة، لا حكمًا نهائيًّا', function () {
    $day = riyadhAt('2026-10-05 18:00:00');

    for ($i = 0; $i < 4; $i++) {
        $start = $day->addDays($i);
        $session = makeSessionAt($start, $start->addHours(3), ['cohort_id' => $this->cohort->id]);

        makeAttendance($session, $this->participant, $i < 2 ? 'present' : 'incomplete');
    }

    expect($this->eligibility->attendanceRate($this->participant, $this->cohort))->toBe(50.0);
});

it('BR-26, D-26: مجموعة الاحتساب مصدرها الوحيد AttendanceCounting ويغلبها الإعداد', function () {
    expect(AttendanceCounting::countedAsAttendedValues())
        ->toBe(['present', 'late', 'excused']);

    // Approving D-26 the other way is a configuration change, not a code change.
    config(['athar.attendance.counted_as_attended' => ['present', 'late']]);

    expect(AttendanceCounting::countedAsAttendedValues())->toBe(['present', 'late']);
});
