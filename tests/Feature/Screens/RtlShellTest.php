<?php

declare(strict_types=1);

/**
 * The dashboard rail belongs on the RIGHT of an Arabic interface.
 *
 * WHY THIS SUITE EXISTS
 * It shipped on the left, and stayed there, while three comments in two
 * stylesheets asserted the opposite:
 *
 *   "Sidebar sits on the RIGHT — it is the first grid column in RTL flow."
 *   "Column 1 of .shell in RTL flow, so the rail in column 2 sits on the RIGHT."
 *   "Slides in from the RIGHT (Article 16)."
 *
 * All three were written by someone reading `inline-end` as "the right". In an
 * RTL document inline-START is the right, inline-END is the left, and grid
 * column 1 is the right-hand column. A headless browser measured the result:
 * rail at x=0, main at x=264, drawer at x=0, active marker at left:-8px.
 *
 * A comment cannot be executed, so a comment cannot be a guard. These
 * assertions can. They deliberately read the DECLARATIONS rather than render a
 * page: the failure was in what the CSS said, and a rendering test needs a
 * browser this suite does not have.
 *
 * `tools/offline-checks/measure-rail-side.mjs` measures the real geometry in
 * Chromium when a browser is available; this file is the part that runs in CI.
 *
 * @see CONSTITUTION.md Article 16 · PRD §9.5.1 · D-43
 */

use Illuminate\Support\Facades\File;

/** One stylesheet, whitespace collapsed, so an assertion is not defeated by formatting. */
function styleSource(string $file): string
{
    $path = resource_path('css/'.$file);

    expect(File::exists($path))->toBeTrue($file.' is missing');

    return (string) preg_replace('/\s+/', ' ', (string) File::get($path));
}

it('D-43: الشريط الجانبي يأخذ العمود الأول — وهو اليمين في RTL', function (): void {
    $app = styleSource('app.css');
    $screens = styleSource('screens.css');

    // The rail's track must come first, and the rail must occupy it.
    expect($app)->toContain('grid-template-columns: var(--side-w) 1fr')
        ->and($app)->toContain('grid-template-columns: var(--side-w-collapsed) 1fr')
        // ...and the content takes the second column, which RTL puts on the left.
        ->and($screens)->toContain('grid-column: 2');

    // The reversed pair is what shipped. Neither may come back.
    expect($app)->not->toContain('grid-template-columns: 1fr var(--side-w)')
        ->and($app)->not->toContain('grid-template-columns: 1fr var(--side-w-collapsed)');
});

it('D-43: الدرج مثبَّت على الحافة نفسها التي عليها الشريط', function (): void {
    $app = styleSource('app.css');

    // The mobile drawer replaces the rail, so it opens on the same edge:
    // inline-start, which is the right in RTL.
    $drawer = null;

    if (preg_match('/\.drawer__panel \{(.*?)\}/', $app, $m) === 1) {
        $drawer = $m[1];
    }

    expect($drawer)->not->toBeNull('.drawer__panel rule not found')
        ->and($drawer)->toContain('inset-inline-start: 0')
        ->and($drawer)->not->toContain('inset-inline-end: 0');
});

it('D-43: علامة الصفحة النشطة على الحافة اليمنى للعنصر', function (): void {
    $app = styleSource('app.css');

    // Measured at left:-8px before the fix — the marker hung off the wrong side
    // of every rail item.
    expect($app)->toMatch('/\.side__b\[aria-current="page"\]::before \{[^}]*inset-inline-start:/');
});

it('D-43: لا خاصّية فيزيائية في قواعد القشرة', function (): void {
    // The whole family of bugs came from reasoning about sides by hand. A
    // physical left/right in the shell is that reasoning written down.
    $offenders = [];

    foreach (['app.css', 'screens.css'] as $file) {
        foreach (explode("\n", (string) File::get(resource_path('css/'.$file))) as $index => $line) {
            if (preg_match('/^\s*(left|right)\s*:/', $line) === 1) {
                $offenders[] = sprintf('%s:%d — %s', $file, $index + 1, trim($line));
            }
        }
    }

    expect($offenders)->toBe([]);
});
