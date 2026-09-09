<?php

declare(strict_types=1);

/**
 * The site header must be a banner, and the skip link must actually skip.
 *
 * WHY THIS SUITE EXISTS
 * `<main id="main">` opened before `@yield('content')`, and every public view
 * included the header from inside its own content section. So `<header>` was a
 * descendant of `<main>`, which strips it of its implicit `banner` role — no
 * public page had a banner landmark at all. Worse, "skip to content" moved
 * focus to `#main`, which still contained the five navigation links, both
 * calls to action and the menu button: the skip link landed the user back at
 * the top of the thing it exists to skip.
 *
 * Every one of the six public views repeated the mistake, including the three
 * added most recently — the pattern was copied from its neighbours, which is
 * how a convention outlives the reason for it. A test is the only thing that
 * makes a structural rule survive copy-paste.
 *
 * @see WCAG 2.1 — 1.3.1, 2.4.1 · CONSTITUTION.md Article 18 · D-47
 */

use Illuminate\Support\Facades\File;

/**
 * A template's source with its Blade comments removed.
 *
 * These tests reason about the ORDER of landmarks in the rendered document, so
 * they must not see markup that is never rendered. The comment above <main> in
 * the public layout explains this very fix and quotes "<main>" twice while doing
 * it — so a raw strpos() found the explanation before the element and reported
 * the fix as broken. The prose that documents a rule is not the rule.
 */
function renderedSource(string $view): string
{
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) File::get(resource_path($view)));
}

it('D-47: الرأس خارج main في التخطيط العام', function (): void {
    $layout = renderedSource('views/layouts/public.blade.php');

    $headerAt = strpos($layout, "@include('partials.public-header')");
    $mainAt = strpos($layout, '<main');

    expect($headerAt)->not->toBeFalse('the layout no longer includes the header')
        ->and($mainAt)->not->toBeFalse('no <main> in the public layout')
        ->and($headerAt)->toBeLessThan($mainAt);
});

it('D-47: لا صفحة عامّة تُدرج الرأس بنفسها', function (): void {
    // A view that includes it again puts a second header inside <main>, which
    // is exactly the state this fix removed.
    $offenders = [];

    foreach (File::files(resource_path('views/public')) as $file) {
        $source = (string) File::get($file->getPathname());

        if (str_contains($source, "@include('partials.public-header')")) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

it('D-47: رابط تخطّي المحتوى يشير إلى هدف موجود', function (): void {
    $layout = renderedSource('views/layouts/public.blade.php');
    $header = renderedSource('views/partials/public-header.blade.php');

    $both = $layout.$header;

    if (! str_contains($both, 'href="#main"')) {
        // No skip link is a separate finding; this test only guards the target
        // when one exists.
        expect(true)->toBeTrue();

        return;
    }

    expect($layout)->toContain('id="main"')
        // The target must be focusable, or the browser moves the viewport and
        // leaves the keyboard where it was.
        ->and($layout)->toMatch('/<main[^>]*tabindex="-1"/');
});
