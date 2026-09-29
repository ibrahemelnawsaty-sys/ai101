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
        ['white', 'bad-800', 4.5, 'destructive button at rest (D-127 — black on bad-800 was 3.43 on hover)'],
        ['white', 'bad-700', 4.5, 'destructive button on hover'],
        ['bad-700', 'n-000', 4.5, 'quiet destructive trigger (danger-ghost) on the light shell'],
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

it('D-123: شارات الأدوار الخمس تبلغ 4.5:1 — تظهر على زر الحساب في كل شاشة', function (): void {
    $t = tokenHexes();
    $css = (string) File::get(resource_path('css/components.css'));
    $failures = [];
    $seen = 0;

    // Read from the stylesheet itself, so the day a badge's pair changes the
    // new pair is what is measured. Small bold text: 4.5:1 (Article 18).
    // teal-700 on teal-100 measured 4.35: the trainee's badge since D-108,
    // which D-123 then put on every trainee's account button.
    foreach (['participant', 'trainer', 'coordinator', 'admin', 'system_admin'] as $role) {
        $rule = '/\.ui-badge--'.$role.',\s*\.ui-pill--'.$role.'\s*\{\s*background:\s*var\(--([a-z0-9-]+)\);\s*color:\s*var\(--([a-z0-9-]+)\);\s*\}/';

        if (preg_match($rule, $css, $m) !== 1) {
            $failures[] = $role.' — no badge rule in components.css';

            continue;
        }

        $seen++;
        [, $background, $foreground] = $m;

        if (! isset($t[$foreground], $t[$background])) {
            $failures[] = sprintf('%s: %s / %s — token missing from tokens.css', $role, $foreground, $background);

            continue;
        }

        $ratio = contrastRatio($t[$foreground], $t[$background]);

        if ($ratio < 4.5) {
            $failures[] = sprintf('%s: %s on %s = %.2f, needs 4.5', $role, $foreground, $background, $ratio);
        }
    }

    expect($seen)->toBe(5)
        ->and($failures)->toBe([]);
});

/**
 * A token's colour as the light shell resolves it: its own value in the
 * `[data-surface="light"]` block, else in the root, following var() chains;
 * an rgba wash is laid over white, the card it sits on.
 */
function lightShellHex(string $token): string
{
    $source = (string) File::get(resource_path('css/tokens.css'));
    preg_match('/\[data-surface="light"\]\s*\{(.*?)\n\}/s', $source, $light);

    foreach ([$light[1] ?? '', $source] as $scope) {
        if (preg_match('/--'.preg_quote($token, '/').':\s*([^;]+);/', $scope, $m) !== 1) {
            continue;
        }

        $value = trim($m[1]);

        if (preg_match('/^var\(--([a-z0-9-]+)\)$/', $value, $ref) === 1) {
            return lightShellHex($ref[1]);
        }

        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1) {
            return strtoupper($value);
        }

        if (preg_match('/^rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)$/', $value, $rgba) === 1) {
            $alpha = (float) $rgba[4];
            $over = static fn (int $channel): int => (int) round($channel * $alpha + 255 * (1 - $alpha));

            return sprintf('#%02X%02X%02X', $over((int) $rgba[1]), $over((int) $rgba[2]), $over((int) $rgba[3]));
        }
    }

    throw new RuntimeException('unresolved token --'.$token);
}

it('D-124: مراحل تذكرة الدعم الأربع تبلغ 4.5:1 على بطاقتها — «قيد المعالجة» كانت 4.14', function (): void {
    $css = (string) File::get(resource_path('css/components.css'));
    $failures = [];

    foreach (App\Enums\SupportTicketStatus::cases() as $status) {
        $variant = $status->variant();
        $rule = '/\.ui-badge--'.$variant.',\s*\.ui-pill--'.$variant.'\s*\{\s*background:\s*var\(--([a-z0-9-]+)\);\s*color:\s*var\(--([a-z0-9-]+)\);\s*\}/';

        if (preg_match($rule, $css, $m) !== 1) {
            $failures[] = $status->value.' — no pill rule for '.$variant;

            continue;
        }

        $ratio = contrastRatio(lightShellHex($m[2]), lightShellHex($m[1]));

        if ($ratio < 4.5) {
            $failures[] = sprintf('%s (%s): %.2f, needs 4.5', $status->value, $variant, $ratio);
        }
    }

    expect($failures)->toBe([]);
});

it('D-139: «الحكم» على بطاقة الدرجة الكلية — الأبيض على غشاء الرمز الذي تستعمله القاعدة فعلًا يبلغ 4.5:1 فوق أفتح نقطة في التدرّج', function (): void {
    // The verdict pill sat on the dark grades card with its light-surface tint:
    // a warm brown on violet, unreadable. On that card it is white on a white
    // veil; the words and the icon say pass or not yet, colour says nothing.
    // The pair is read from the STYLESHEET and the TOKEN FILE, not restated here:
    // deleting the rule, or thinning the veil, must fail this.
    $screens = (string) File::get(resource_path('css/screens.css'));
    $tokenFile = (string) File::get(resource_path('css/tokens.css'));

    expect(preg_match('/\.gtotal \.ui-pill\s*\{([^}]*)\}/', $screens, $rule))->toBe(1);
    expect(preg_match('/background:\s*var\(--(ln-\d+)\)/', $rule[1], $veilToken))->toBe(1);
    expect($rule[1])->toContain('color: var(--white)');

    expect(preg_match('/--'.preg_quote($veilToken[1], '/').':\s*rgba\(255,\s*255,\s*255,\s*([0-9.]+)\)/', $tokenFile, $alphaMatch))->toBe(1);
    $alpha = (float) $alphaMatch[1];

    $tokens = tokenHexes();
    $veil = static function (string $under) use ($alpha): string {
        $over = static fn (int $c, int $u): int => (int) round($alpha * $c + (1 - $alpha) * $u);
        $rgb = sscanf(ltrim($under, '#'), '%02x%02x%02x');

        return sprintf('#%02x%02x%02x', $over(255, $rgb[0]), $over(255, $rgb[1]), $over(255, $rgb[2]));
    };

    // The card's gradient runs violet-700 → violet-900 (see `.gtotal`).
    expect($screens)->toMatch('/\.gtotal\s*\{[^}]*linear-gradient\([^)]*var\(--violet-700\)[^)]*var\(--violet-900\)/s');

    foreach (['violet-700', 'violet-900'] as $end) {
        expect(contrastRatio('#FFFFFF', $veil($tokens[$end])))->toBeGreaterThanOrEqual(4.5, $end);
    }
});
