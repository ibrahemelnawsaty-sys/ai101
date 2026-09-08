<?php

declare(strict_types=1);

/**
 * The cross-cutting half of Article 17: the rules that no single screen test can
 * prove on its own.
 *
 *  - every screen has a loading skeleton, and it is shaped like the content;
 *  - every empty state carries copy written for that one screen, not a shared
 *    placeholder — asserted by requiring all empty copy to be distinct;
 *  - every error message is Arabic prose, with no technical vocabulary and no blame;
 *  - no screen renders a raw, unresolved translation key.
 *
 * @see CONSTITUTION.md Articles 15, 17 · PRD §11
 */

use Illuminate\Support\Facades\View;

/**
 * Every screen in the participant and public areas, by its skeleton name.
 *
 * @return list<string>
 */
function allScreenNames(): array
{
    return [
        'landing',
        'dashboard',
        'journey',
        'schedule',
        'attendance',
        'live',
        'assignments',
        'resources',
        'messages',
        'final-project',
        'grades',
        'certificate',
        'card',
        'profile',
        'notifications',
    ];
}

it('لكل شاشة هيكل تحميل موجود فعلًا', function (): void {
    $missing = array_values(array_filter(
        allScreenNames(),
        fn (string $screen): bool => ! View::exists('partials.skeletons.'.$screen),
    ));

    expect($missing)->toBe([]);
});

it('كل هيكل تحميل يحمل وسم حالة التحميل', function (string $screen): void {
    expect(renderSkeleton($screen))->toBeScreenState('loading');
})->with(allScreenNames());

it('هيكل التحميل بشكل المحتوى لا دوّامة فارغة', function (string $screen): void {
    $markup = renderSkeleton($screen);

    // A skeleton shaped like its content has several placeholder blocks. A single
    // spinner, or an empty div, is exactly what Article 17 forbids.
    expect(substr_count($markup, 'data-skeleton-block'))->toBeGreaterThanOrEqual(2)
        ->and($markup)->not->toContain('data-spinner');
})->with(allScreenNames());

it('نص كل حالة فارغة خاص بشاشته ولا يتكرر بين شاشتين', function (): void {
    $keys = [];

    foreach (allScreenNames() as $screen) {
        $key = 'empty.'.$screen.'.title';

        expect(__($key))->not->toBe($key, "Missing empty-state title for screen [{$screen}] at lang key [{$key}]");

        $keys[$screen] = __($key);
    }

    expect(array_unique($keys))->toHaveCount(count($keys));
});

it('لكل حالة فارغة عنوان وشرح وزر إجراء', function (string $screen): void {
    foreach (['title', 'body', 'action'] as $part) {
        $key = 'empty.'.$screen.'.'.$part;

        expect(__($key))->not->toBe($key, "Empty state for [{$screen}] is missing its [{$part}] copy");
    }
})->with(allScreenNames());

it('رسائل الخطأ عربية تشرح ما حدث وما الحل بلا مصطلح تقني', function (): void {
    $forbidden = ['exception', 'stack', 'sql', 'query', 'null', 'undefined', 'error 500', 'server error'];

    $messages = collect(trans('errors'))->flatten()->filter(fn ($value): bool => is_string($value));

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        foreach ($forbidden as $term) {
            expect(mb_strtolower($message))->not->toContain($term);
        }

        expect($message)->toUseLatinNumerals();
    }
});

it('لا تظهر مفاتيح ترجمة خام في أي شاشة عامة', function (): void {
    $cohort = makeCohort();
    $participant = makeParticipant($cohort);

    foreach ([route('home'), route('terms'), route('privacy')] as $url) {
        assertNoRawTranslationKeys($this->get($url)->getContent());
    }

    foreach ([route('dashboard'), route('grades'), route('notifications')] as $url) {
        assertNoRawTranslationKeys($this->actingAs($participant)->get($url)->getContent());
    }
});

/**
 * A rendered page must never contain something that looks like `attendance.window.open`
 * sitting in the text: that is a translation key that failed to resolve.
 */
function assertNoRawTranslationKeys(string $html): void
{
    $stripped = strip_tags($html);

    // An e-mail address is not a translation key, and both halves of one look
    // exactly like a dotted key: `karam.omar` and `example.org`. The dashboard
    // header prints the signed-in account's own address because PRD §9.5.2
    // requires it ("اسم المستخدم وبريده في الأعلى"), so addresses are removed
    // before the search rather than the search being relaxed — the same
    // exemption the .sa/.com/.test list below already makes for bare domains.
    $stripped = (string) preg_replace('/[\w.+-]+@[\w.-]+/u', ' ', $stripped);

    preg_match_all('/(?<![\w\/.-])[a-z][a-z_]*(?:\.[a-z][a-z_]*){1,3}(?![\w\/.-])/', $stripped, $matches);

    // The platform's own hostname is printed in the footer and in verification
    // links, and it is dotted like a key. It is a configured fact (BR-36), so
    // it is exempted by reading the config rather than by growing the TLD list
    // below every time the domain changes.
    $ownDomain = (string) config('athar.domain', '');

    $suspects = array_values(array_filter(
        array_unique($matches[0]),
        fn (string $candidate): bool => $candidate !== $ownDomain
            && ! str_ends_with($candidate, '.sa')
            && ! str_ends_with($candidate, '.com')
            && ! str_ends_with($candidate, '.test'),
    ));

    expect($suspects)->toBe([]);
}
