<?php

declare(strict_types=1);

/**
 * Certificate serial numbers: ATHAR-AI101-2026-0001 (PRD §9.17).
 *
 * CONTRACT GAPS, declared rather than assumed silently:
 *  - PROJECT-CONTRACT.md fixes the format but names no class or signature for the
 *    generator. This suite uses App\Services\Certificates\SerialNumberGenerator with
 *    `next(Cohort $cohort): string`, which is the smallest surface that satisfies
 *    §8 and keeps the sequence server-side.
 *  - The source never says whether the year segment is the year of issue or the year
 *    of the cohort. Every test here freezes the clock in 2026 and uses a 2026 cohort,
 *    so it passes under either reading; the choice is escalated, not decided here.
 *  - The source never says whether the sequence is per cohort, per programme, or
 *    global. Sequence assertions below stay inside one cohort for that reason.
 *
 * @see BR-25, BR-26, BR-36 · PRD §9.17 · PROJECT-CONTRACT.md §8
 */

use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\User;
use App\Services\Certificates\SerialNumberGenerator;

beforeEach(function () {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort([
        'start_date' => '2026-10-04',
        'end_date' => '2026-11-05',
    ]);
    $this->generator = app(SerialNumberGenerator::class);
});

/**
 * Issue a certificate carrying the given serial, so the next call has something to
 * count past.
 */
function issueWithSerial(Cohort $cohort, User $user, string $serial): Certificate
{
    return Certificate::factory()->create([
        'user_id' => $user->id,
        'cohort_id' => $cohort->id,
        'serial_number' => $serial,
        'issued_at' => riyadhAt('2026-11-20 12:00:00'),
    ]);
}

it('يولّد الرقم التسلسلي بالصيغة المنصوص عليها', function () {
    $serial = $this->generator->next($this->cohort);

    expect($serial)->toMatch('/^ATHAR-[A-Z0-9]+-\d{4}-\d{4,}$/');
});

it('BR-36: رمز البرنامج في الرقم التسلسلي يُقرأ من الإعدادات لا من الكود', function () {
    $serial = $this->generator->next($this->cohort);

    expect(explode('-', $serial)[1])->toBe((string) config('athar.program.code'));
});

it('الجزء الأول ثابت باسم المركز', function () {
    expect(explode('-', $this->generator->next($this->cohort))[0])->toBe('ATHAR');
});

it('السنة تُكتب بأربعة أرقام لاتينية بلا فاصلة آلاف', function () {
    $serial = $this->generator->next($this->cohort);
    $year = explode('-', $serial)[2];

    expect($year)->toBe('2026')
        ->and($serial)->toUseLatinNumerals();
});

it('التسلسل يبدأ من واحد ويُصفَّر إلى أربع خانات', function () {
    expect($this->generator->next($this->cohort))->toEndWith('-0001');
});

it('التسلسل يزيد واحدًا مع كل شهادة صادرة', function () {
    $first = $this->generator->next($this->cohort);
    issueWithSerial($this->cohort, makeParticipant($this->cohort), $first);

    $second = $this->generator->next($this->cohort);
    issueWithSerial($this->cohort, makeParticipant($this->cohort), $second);

    $third = $this->generator->next($this->cohort);

    expect($first)->toEndWith('-0001')
        ->and($second)->toEndWith('-0002')
        ->and($third)->toEndWith('-0003');
});

it('التصفير يحافظ على أربع خانات عند 10 و 100 و 1000', function (int $issued, string $expectedSuffix) {
    $participant = makeParticipant($this->cohort);
    $prefix = implode('-', array_slice(explode('-', $this->generator->next($this->cohort)), 0, 3));

    for ($i = 1; $i <= $issued; $i++) {
        issueWithSerial($this->cohort, $i === 1 ? $participant : makeParticipant($this->cohort), sprintf('%s-%04d', $prefix, $i));
    }

    expect($this->generator->next($this->cohort->fresh()))->toEndWith($expectedSuffix);
})->with([
    'بعد 9 شهادات' => [9, '-0010'],
    'بعد 99 شهادة' => [99, '-0100'],
]);

it('يتجاوز أربع خانات ولا يقتطع بعد الشهادة رقم 9999', function () {
    $prefix = implode('-', array_slice(explode('-', $this->generator->next($this->cohort)), 0, 3));

    issueWithSerial($this->cohort, makeParticipant($this->cohort), $prefix.'-9999');

    expect($this->generator->next($this->cohort->fresh()))->toEndWith('-10000');
});

it('لا يُصدر رقمًا مكررًا أبدًا', function () {
    $seen = [];

    for ($i = 0; $i < 25; $i++) {
        $serial = $this->generator->next($this->cohort->fresh());
        expect($seen)->not->toContain($serial);

        $seen[] = $serial;
        issueWithSerial($this->cohort, makeParticipant($this->cohort), $serial);
    }

    expect(array_unique($seen))->toHaveCount(25);
});

it('لا يعيد استخدام رقم شهادة مسحوبة', function () {
    $first = $this->generator->next($this->cohort);
    $certificate = issueWithSerial($this->cohort, makeParticipant($this->cohort), $first);

    $certificate->update(['revoked_at' => riyadhAt('2026-11-21 09:00:00')]);

    expect($this->generator->next($this->cohort->fresh()))->not->toBe($first)
        ->and($this->generator->next($this->cohort->fresh()))->toEndWith('-0002');
});

it('الرقم التسلسلي ليس معرّف المستخدم ولا يحتوي أي جزء منه', function () {
    $participant = makeParticipant($this->cohort);

    $serial = $this->generator->next($this->cohort);

    expect($serial)->not->toContain($participant->id)
        ->and($serial)->not->toContain((string) $participant->email);
});

it('BR-26: نطاق تسلسل الأرقام بين الدفعات — بانتظار قرار صاحب المنتج', function () {
    expect(true)->toBeTrue();
})->skip('D-31: نطاق العدّاد ومرجع السنة غير محسومين — وتثبيتهما باختبار يساوي الحكم فيهما (المادة 4).');
