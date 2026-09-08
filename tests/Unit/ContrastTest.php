<?php

declare(strict_types=1);

/**
 * The colour pairs the interface actually uses must meet WCAG 2.1 AA.
 *
 * WHY THIS SUITE EXISTS
 * `D-23` recorded, in September, that the palette in the requirements document
 * fails its own accessibility criterion in eight places. Half of it was fixed.
 * The other half stayed, and six pairs were still shipping months later:
 *
 *     --text-faint on the dark page   3.76   needs 4.5
 *     white on --bad                  4.38   needs 4.5
 *     white on --warn                 3.34   needs 4.5
 *     white on --teal-500             2.86   needs 4.5
 *     --n-200 as a field border       1.27   needs 3.0
 *     --n-400 as a field border       2.78   needs 3.0
 *
 * Nothing measured them. `gate:tokens` checks that a colour comes from the
 * token file — never that the pair is legible. So a compliant token could sit
 * on a compliant token and produce an illegible screen, and every gate stayed
 * green.
 *
 * The white-on-teal pair is the one CLAUDE.md names by hand, in prose, as
 * failing. It shipped anyway. Prose is not a check.
 *
 * @see CONSTITUTION.md Article 14, Article 18 · PRD §17 · D-23, D-48
 */

use Illuminate\Support\Facades\File;

/** Relative luminance, WCAG 2.1 formula. */
function luminance(string $hex): float
{
    $hex = ltrim($hex, '#');

    $channel = static function (int $value): float {
        $c = $value / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
        + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
        + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
}

function contrastRatio(string $foreground, string $background): float
{
    $a = luminance($foreground);
    $b = luminance($background);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/** Every `--name: #rrggbb` literal declared in the token file. */
function tokenHexes(): array
{
    $source = (string) File::get(resource_path('css/tokens.css'));

    preg_match_all('/--([a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})\b/', $source, $matches, PREG_SET_ORDER);

    $tokens = [];

    foreach ($matches as $match) {
        $tokens[$match[1]] = strtoupper($match[2]);
    }

    return $tokens;
}

it('D-48: كل مزاوجة لون مستعملة تحقّق معيار التباين', function (): void {
    $t = tokenHexes();

    // Pairs that the compiled interface actually renders. Each is named after
    // where it appears, so a failure says which screen to look at.
    $pairs = [
        // [foreground, background, minimum, where]
        ['dk-8', 'ink', 4.5, 'faint copy on the dark page (copyright line, legal footer links)'],
        ['white', 'violet-500', 4.5, 'primary button'],
        ['black', 'bad', 4.5, 'destructive button'],
        ['black', 'warn', 4.5, 'impersonation bar — the one banner that must never be missed'],
        ['black', 'teal-500', 4.5, 'completed journey step — the pairing CLAUDE.md names by hand'],
        ['n-900', 'n-000', 4.5, 'body copy in the light shell'],
        ['n-600', 'n-000', 4.5, 'muted copy in the light shell'],
    ];

    $failures = [];

    foreach ($pairs as [$fg, $bg, $min, $where]) {
        if (! isset($t[$fg], $t[$bg])) {
            $failures[] = sprintf('%s / %s — token missing from tokens.css', $fg, $bg);

            continue;
        }

        $ratio = contrastRatio($t[$fg], $t[$bg]);

        if ($ratio < $min) {
            $failures[] = sprintf(
                '%s on %s = %.2f, needs %.1f — %s',
                $fg,
                $bg,
                $ratio,
                $min,
                $where,
            );
        }
    }

    expect($failures)->toBe([]);
});

it('D-48: حدود الحقول تحقّق 3:1 على كل سطح فاتح تجلس عليه', function (): void {
    // WCAG 1.4.11: the boundary of a control needs 3:1, not 4.5. --n-200 was
    // carrying it at 1.27 — a border nobody with low vision could find.
    $t = tokenHexes();
    $failures = [];

    foreach (['n-000', 'n-100', 'n-150'] as $surface) {
        if (! isset($t['n-450'], $t[$surface])) {
            $failures[] = 'token missing: n-450 or '.$surface;

            continue;
        }

        $ratio = contrastRatio($t['n-450'], $t[$surface]);

        if ($ratio < 3.0) {
            $failures[] = sprintf('field border n-450 on %s = %.2f, needs 3.0', $surface, $ratio);
        }
    }

    expect($failures)->toBe([]);
});

it('المادة 14: لا أصفر ولا ذهبي في ملف الرموز', function (): void {
    // Hue 40°–70° with real saturation is yellow or gold, whatever it is called.
    $offenders = [];

    foreach (tokenHexes() as $name => $hex) {
        $r = (int) hexdec(substr($hex, 1, 2));
        $g = (int) hexdec(substr($hex, 3, 2));
        $b = (int) hexdec(substr($hex, 5, 2));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        if ($max === 0 || ($max - $min) / $max < 0.25) {
            continue; // grey enough to have no hue worth naming
        }

        $hue = match (true) {
            $max === $r => 60 * fmod(($g - $b) / ($max - $min), 6),
            $max === $g => 60 * ((($b - $r) / ($max - $min)) + 2),
            default => 60 * ((($r - $g) / ($max - $min)) + 4),
        };

        if ($hue < 0) {
            $hue += 360;
        }

        // --warn is burnt orange at hue 33 and is explicitly allowed.
        if ($hue >= 40 && $hue <= 70) {
            $offenders[] = sprintf('--%s: %s is at hue %.0f', $name, $hex, $hue);
        }
    }

    expect($offenders)->toBe([]);
});
