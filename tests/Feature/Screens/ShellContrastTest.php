<?php

declare(strict_types=1);

/**
 * A shell must declare which shell it is, and a component must not decide for it.
 *
 * WHY THIS SUITE EXISTS
 * The sign-in and registration screens shipped WHITE TEXT ON A WHITE CARD —
 * measured contrast 1.00:1 — and stayed that way on the live host. Neither the
 * twelve gates nor any screen test noticed, because both halves were
 * individually correct:
 *
 *   · tokens.css section 16 says the light shell is applied by
 *     <html data-surface="light"> on "dashboard, auth, verify and print" views
 *   · layouts/app.blade.php sets that attribute
 *   · layouts/auth.blade.php never did
 *   · .authcard painted `background: var(--n-000)` — a raw primitive — so the
 *     card went white while every text role still resolved from the dark root
 *
 * No single file was wrong. The CONTRACT between them was, and nothing checked
 * a contract. These two tests do.
 *
 * @see CONSTITUTION.md Article 6, Article 14, Article 18 · D-41
 */

use Illuminate\Support\Facades\File;

it('D-41: كل تخطيط يُعلن قشرته صراحةً', function (): void {
    // A layout that paints light surfaces must say so on <html>, or its text
    // roles keep resolving from the dark root. The public shell is the only one
    // that is dark by design, and it says so too.
    $expected = [
        'app.blade.php' => 'light',
        'auth.blade.php' => 'light',
        'public.blade.php' => 'dark',
        'bare.blade.php' => 'dark',
    ];

    $offenders = [];

    foreach ($expected as $file => $shell) {
        $path = resource_path('views/layouts/'.$file);

        if (! File::exists($path)) {
            continue;
        }

        $source = (string) File::get($path);

        if (preg_match('/<html\b[^>]*>/s', $source, $html) !== 1) {
            $offenders[] = $file.' — no <html> element found';

            continue;
        }

        $declaresLight = str_contains($html[0], 'data-surface="light"');

        if ($shell === 'light' && ! $declaresLight) {
            $offenders[] = $file.' — paints a light shell but never sets data-surface="light"';
        }

        if ($shell === 'dark' && $declaresLight) {
            $offenders[] = $file.' — is the dark shell but claims data-surface="light"';
        }

        // The browser-chrome hint must agree with the shell it describes.
        if (preg_match('/<meta name="color-scheme" content="([a-z]+)">/', $source, $scheme) === 1
            && $scheme[1] !== $shell) {
            $offenders[] = sprintf('%s — color-scheme says "%s" but the shell is "%s"', $file, $scheme[1], $shell);
        }
    }

    expect($offenders)->toBe([]);
});

it('D-41: لا مكوّن يرسم لونًا بدائيًّا بدل الدور الدلالي', function (): void {
    // `background: var(--n-000)` is a component deciding it is white in every
    // shell — including one whose text is also white. Surfaces, borders and text
    // must come from the semantic layer so that re-pointing a shell re-points
    // the component with it (art. 6).
    //
    // The token file itself is exempt: defining the roles is its whole job.
    $primitives = '/(background|border|color)\s*:[^;]*var\(--(n-\d{3}|dk-\d{1,2})\)/';

    $offenders = [];

    foreach (File::allFiles(resource_path('css')) as $file) {
        if ($file->getFilename() === 'tokens.css') {
            continue;
        }

        $source = (string) File::get($file->getPathname());
        $lines = explode("\n", $source);

        foreach ($lines as $index => $line) {
            // A primitive is legitimate INSIDE a shell definition, where the
            // point is to bind a role to a value.
            if (str_contains($line, '--surface-') || str_contains($line, '--text-') || str_contains($line, '--border-')) {
                continue;
            }

            if (preg_match($primitives, $line) === 1) {
                $offenders[] = sprintf('%s:%d — %s', $file->getFilename(), $index + 1, trim($line));
            }
        }
    }

    // A ratchet, not a clean sheet. 92 rules carry this disease today — the
    // number was MEASURED, not guessed, and .authcard was one of them. Pinning
    // it means a new one fails the build and every fix tightens the pin.
    // Lower this number as the debt is paid; never raise it.
    expect(count($offenders))->toBeLessThanOrEqual(92, implode("\n", array_slice($offenders, 0, 30)));
});
