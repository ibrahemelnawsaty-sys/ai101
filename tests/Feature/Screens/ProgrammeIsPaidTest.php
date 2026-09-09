<?php

declare(strict_types=1);

/**
 * The platform never tells anyone the programme is free.
 *
 * WHY THIS SUITE EXISTS
 * `PublicLayoutComposer` built the Schema.org graph with `isAccessibleForFree`
 * hardcoded to `true`, and it was LIVE:
 *
 *     curl https://ai.wareed.vip/ | grep isAccessibleForFree
 *     "isAccessibleForFree":true
 *
 * The centre sells this programme — the official guide prices it at 1,499 SAR
 * after a discount from 2,950, VAT included. So the one machine-readable claim
 * on the page, the one a search engine repeats verbatim in its results, said the
 * opposite of the truth about money.
 *
 * A human reading the page could never have caught it: the string appears
 * nowhere in the visible text. It is exactly the class of defect that needs a
 * test rather than a reviewer.
 *
 * @see BR-36 · PRD §9.1.3 · CONSTITUTION.md Article 7, Article 12
 */

use App\Enums\CohortStatus;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-08-20 12:00:00'));

    makeCohort([
        'status' => CohortStatus::Open->value,
        'capacity' => 30,
        'registration_closes_at' => riyadhAt('2026-09-04 23:59:00'),
    ]);
});

it('BR-36: الصفحة لا تُصرّح للزواحف بأن البرنامج مجاني', function (): void {
    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('"isAccessibleForFree":false')
        ->and($body)->not->toContain('"isAccessibleForFree":true');
});

it('BR-36: كل صفحة عامة تحمل الإعلان نفسه، لا الهبوط وحدها', function (): void {
    // The graph is composed once for the whole public shell, so a page that
    // escaped the fix would be a second, quieter false claim.
    foreach (['home', 'programs', 'about', 'contact', 'terms', 'privacy'] as $name) {
        $body = $this->get(route($name))->assertOk()->getContent();

        expect($body)->not->toContain('"isAccessibleForFree":true');
    }
});

it('BR-36: القيمة تأتي من الإعداد لا من ثابت في الشيفرة', function (): void {
    // The point of moving it out of the composer: the centre can correct it
    // without a code change. If it were still a literal, this would not move.
    config()->set('athar.program.is_free', true);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('"isAccessibleForFree":true');
});

it('المادة 7: لا نصّ معروض للزائر يَعِد بأن البرنامج مجاني', function (): void {
    // The Schema.org fix closed the claim MACHINES read and left the one PEOPLE
    // read: `landing.final.register` said "سجّل الآن — مجانًا" on the button at
    // the foot of the page, in both locales. Pinning one key would miss the next
    // one, so this sweeps every interface string on the public path.
    $offenders = [];

    foreach (['ar', 'en'] as $locale) {
        $strings = require lang_path($locale.'/landing.php');

        array_walk_recursive($strings, function (mixed $value, string $key) use (&$offenders, $locale): void {
            if (! is_string($value)) {
                return;
            }

            // "مجاني" is legitimate about a PERK — the guide gives a free year of
            // hosting and a free consultation. It is false about the programme,
            // and a call to action is where that difference stops being subtle.
            if (preg_match('/(مجان|\bfree\b)/iu', $value) === 1) {
                $offenders[] = "{$locale}.{$key}: {$value}";
            }
        });
    }

    expect($offenders)->toBe([]);
});

it('المادة 12: الافتراضي عند غياب المتغيّر هو «ليس مجانيًا»', function (): void {
    // Fail safe in the direction that cannot mislead: an unset variable may
    // understate a discount, but must never advertise a paid programme as free.
    expect(config('athar.program.is_free'))->toBeFalse();
});
