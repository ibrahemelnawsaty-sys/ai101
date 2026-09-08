<?php

declare(strict_types=1);

/**
 * Display formatting for Asia/Riyadh: «الأحد 12 أكتوبر 2026» and «6:00 مساءً».
 *
 * The formatter is App\Services\Time\RiyadhFormatter (PROJECT-CONTRACT.md §2, the
 * App\Services\Time\* namespace), and its methods are INSTANCE methods, not static
 * ones: date(), shortDate(), time(), dateTime(), timeRange(), and the rest. The suite
 * resolves it from the container through the riyadhFormatter() helper in tests/Pest.php,
 * exactly as a controller would, so a constructor dependency added later does not
 * silently break every assertion here.
 *
 * Assertions here are structural, never literal Arabic strings. The day and month
 * names live in lang/ar (Constitution, Article 15); a test that pinned them as string
 * literals would both duplicate the single source and put Arabic in a .php file.
 *
 * @see BR-07, BR-36 · PRD §13.4 · CONSTITUTION.md Articles 11, 15
 */

use Carbon\CarbonImmutable;

/** Arabic-Indic digits, which must never appear (Constitution, Article 15). */
const ARABIC_INDIC_DIGITS = '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u';

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 18:00:00'));
});

/*
|--------------------------------------------------------------------------
| Dates
|--------------------------------------------------------------------------
*/

it('يعرض التاريخ بأربعة أجزاء: اليوم ثم الرقم ثم الشهر ثم السنة', function (): void {
    $parts = preg_split('/\s+/u', trim(riyadhFormatter()->date(riyadhAt('2026-10-12 18:00:00'))));

    expect($parts)->toHaveCount(4)
        ->and($parts[0])->toMatch('/^\p{Arabic}+$/u')   // day name
        ->and($parts[1])->toBe('12')                     // day number, Latin
        ->and($parts[2])->toMatch('/^\p{Arabic}+$/u')   // month name
        ->and($parts[3])->toBe('2026');                  // year, Latin, no separator
});

it('السنة تُعرض بلا فاصلة آلاف — العطل الذي شُحن مرة', function (): void {
    $formatted = riyadhFormatter()->date(riyadhAt('2026-10-12 18:00:00'));

    expect($formatted)->not->toContain('2,026')
        ->and($formatted)->toUseLatinNumerals();
});

it('الأرقام لاتينية ولا يظهر أي رقم هندي', function (): void {
    $formatted = riyadhFormatter()->dateTime(riyadhAt('2026-10-12 18:00:00'));

    expect($formatted)->not->toMatch(ARABIC_INDIC_DIGITS);
});

it('اسم اليوم يتغير بتغير اليوم ويتكرر كل سبعة أيام', function (): void {
    $day = static fn (string $date): string => preg_split('/\s+/u', riyadhFormatter()->date(riyadhAt($date.' 12:00:00')))[0];

    expect($day('2026-10-12'))->not->toBe($day('2026-10-13'))
        ->and($day('2026-10-12'))->toBe($day('2026-10-19'));
});

it('لكل شهر من الشهور الاثني عشر اسم مختلف', function (): void {
    $months = [];

    for ($month = 1; $month <= 12; $month++) {
        $months[] = preg_split('/\s+/u', riyadhFormatter()->date(riyadhAt(sprintf('2026-%02d-15 12:00:00', $month))))[2];
    }

    expect(array_unique($months))->toHaveCount(12);
});

it('لا يضع صفرًا بادئًا في رقم اليوم', function (): void {
    $parts = preg_split('/\s+/u', riyadhFormatter()->date(riyadhAt('2026-10-05 12:00:00')));

    expect($parts[1])->toBe('5');
});

/*
|--------------------------------------------------------------------------
| Times — 12-hour, with a meridiem word
|--------------------------------------------------------------------------
*/

it('يعرض الوقت بنظام اثنتي عشرة ساعة مع كلمة الفترة', function (string $wallClock, string $expectedClock): void {
    $formatted = riyadhFormatter()->time(riyadhAt($wallClock));
    $parts = preg_split('/\s+/u', trim($formatted));

    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toBe($expectedClock)
        ->and($parts[1])->toMatch('/^\p{Arabic}+$/u');
})->with([
    'منتصف الليل' => ['2026-10-12 00:30:00', '12:30'],
    'صباحًا' => ['2026-10-12 09:05:00', '9:05'],
    'الظهيرة' => ['2026-10-12 12:00:00', '12:00'],
    'مساءً' => ['2026-10-12 18:00:00', '6:00'],
    'آخر دقيقة في اليوم' => ['2026-10-12 23:59:00', '11:59'],
]);

it('كلمة الفترة تختلف بين الصباح والمساء', function (): void {
    $meridiem = static fn (string $wallClock): string => preg_split('/\s+/u', riyadhFormatter()->time(riyadhAt($wallClock)))[1];

    expect($meridiem('2026-10-12 09:00:00'))->not->toBe($meridiem('2026-10-12 21:00:00'));
});

it('لا يضع صفرًا بادئًا في الساعة', function (): void {
    expect(riyadhFormatter()->time(riyadhAt('2026-10-12 06:05:00')))->toStartWith('6:05');
});

/*
|--------------------------------------------------------------------------
| The timezone is Riyadh, always, whatever the instant carries
|--------------------------------------------------------------------------
*/

it('BR-07: يحوّل لحظة UTC إلى توقيت الرياض قبل العرض', function (): void {
    $utc = CarbonImmutable::parse('2026-10-12 15:00:00', 'UTC');

    expect(riyadhFormatter()->time($utc))->toStartWith('6:00');
});

it('BR-07: يعرض بتوقيت الرياض حتى لو حملت اللحظة منطقة زمنية أخرى', function (): void {
    $tokyo = CarbonImmutable::parse('2026-10-13 00:00:00', 'Asia/Tokyo');

    expect(riyadhFormatter()->time($tokyo))->toStartWith('6:00')
        ->and(preg_split('/\s+/u', riyadhFormatter()->date($tokyo))[1])->toBe('12');
});

it('لحظة بعد منتصف ليل الرياض تُعرض بتاريخ اليوم التالي', function (): void {
    $utc = CarbonImmutable::parse('2026-10-12 21:30:00', 'UTC'); // 00:30 Riyadh, 13 October

    $parts = preg_split('/\s+/u', riyadhFormatter()->date($utc));

    expect($parts[1])->toBe('13')
        ->and(riyadhFormatter()->time($utc))->toStartWith('12:30');
});

/*
|--------------------------------------------------------------------------
| The combined form
|--------------------------------------------------------------------------
*/

it('الصيغة المجمّعة تحتوي التاريخ والوقت كليهما', function (): void {
    $instant = riyadhAt('2026-10-12 18:00:00');

    $combined = riyadhFormatter()->dateTime($instant);

    expect($combined)->toContain(riyadhFormatter()->date($instant))
        ->and($combined)->toContain(riyadhFormatter()->time($instant));
});
