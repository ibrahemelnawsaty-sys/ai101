<?php

declare(strict_types=1);

/**
 * The single source of time. Everything downstream — attendance windows, deadlines,
 * certificate dates — is only as trustworthy as this class.
 *
 * @see BR-07 · PRD §9.9.4 · PROJECT-CONTRACT.md §5 · CONSTITUTION.md Article 11
 */

use App\Services\Time\Clock;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    Clock::reset();
});

it('BR-07: يعيد الوقت بتوقيت UTC دائمًا', function (): void {
    freezeAt(riyadhAt('2026-10-12 18:00:00'));

    expect(Clock::now()->getTimezone()->getName())->toBe('UTC')
        ->and(Clock::now()->format('Y-m-d H:i:s'))->toBe('2026-10-12 15:00:00');
});

it('BR-07: يعيد وقت العرض بتوقيت الرياض', function (): void {
    freezeAt(riyadhAt('2026-10-12 18:00:00'));

    expect(Clock::riyadh()->getTimezone()->getName())->toBe('Asia/Riyadh')
        ->and(Clock::riyadh()->format('Y-m-d H:i:s'))->toBe('2026-10-12 18:00:00');
});

it('BR-07: الرياض تسبق UTC بثلاث ساعات في كل شهور السنة — لا توقيت صيفي', function (): void {
    foreach (['2026-01-15 12:00:00', '2026-04-15 12:00:00', '2026-07-15 12:00:00', '2026-10-15 12:00:00'] as $wallClock) {
        freezeAt(riyadhAt($wallClock));

        expect(Clock::riyadh()->getOffset())->toBe(3 * 3600)
            ->and(Clock::riyadh()->format('H:i:s'))->toBe('12:00:00');
    }
});

it('يحوّل أي لحظة إلى توقيت الرياض دون تغيير اللحظة نفسها', function (): void {
    $instant = CarbonImmutable::parse('2026-10-12 15:00:00', 'UTC');

    $converted = Clock::toRiyadh($instant);

    expect($converted->getTimezone()->getName())->toBe('Asia/Riyadh')
        ->and($converted->format('H:i:s'))->toBe('18:00:00')
        ->and($converted->equalTo($instant))->toBeTrue();
});

it('يحوّل لحظة قادمة بمنطقة زمنية ثالثة إلى الرياض بشكل صحيح', function (): void {
    $tokyo = CarbonImmutable::parse('2026-10-13 00:00:00', 'Asia/Tokyo');

    expect(Clock::toRiyadh($tokyo)->format('Y-m-d H:i:s'))->toBe('2026-10-12 18:00:00');
});

it('يعيد النوع غير القابل للتعديل في كل الحالات', function (): void {
    freezeAt(riyadhAt('2026-10-12 18:00:00'));

    expect(Clock::now())->toBeInstanceOf(CarbonImmutable::class)
        ->and(Clock::riyadh())->toBeInstanceOf(CarbonImmutable::class)
        ->and(Clock::toRiyadh(Clock::now()))->toBeInstanceOf(CarbonImmutable::class);
});

it('التجميد يثبّت اللحظة فلا تتقدّم بين نداءين', function (): void {
    freezeAt(riyadhAt('2026-10-12 18:00:00'));

    $first = Clock::now();
    usleep(2000);
    $second = Clock::now();

    expect($first->equalTo($second))->toBeTrue();
});

it('إعادة الضبط تحرر الساعة فتعود إلى وقت النظام', function (): void {
    // Midnight on 1 January in Riyadh is 21:00 on 31 December in UTC, so the
    // two clocks disagree about the year at this instant. That disagreement is
    // the whole point of the class, so the test pins both sides of it rather
    // than picking an hour where the distinction would have been invisible.
    freezeAt(riyadhAt('2020-01-01 00:00:00'));
    expect(Clock::riyadh()->year)->toBe(2020)
        ->and(Clock::now()->year)->toBe(2019);

    Clock::reset();

    expect(Clock::now()->year)->toBeGreaterThan(2020);
});

it('تمرير قيمة فارغة إلى التزييف يعادل إعادة الضبط', function (): void {
    freezeAt(riyadhAt('2020-01-01 00:00:00'));

    Clock::fake(null);

    expect(Clock::now()->year)->toBeGreaterThan(2020);
});

it('يقبل تزييف الساعة بأي نوع يحقق DateTimeInterface', function (): void {
    Clock::fake(new DateTimeImmutable('2026-10-12 15:00:00', new DateTimeZone('UTC')));

    expect(Clock::now()->format('Y-m-d H:i:s'))->toBe('2026-10-12 15:00:00');
});
