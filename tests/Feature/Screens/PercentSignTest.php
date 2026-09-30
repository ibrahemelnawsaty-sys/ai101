<?php

declare(strict_types=1);

/**
 * D-141 — a percentage reads «75%» everywhere, in a sentence as on a stat card.
 *
 * A Latin digit that follows an Arabic letter is treated by the bidi algorithm as
 * an Arabic-Indic number (rule W2), so the `%` after it no longer attaches and the
 * sentence draws «%75» — while a stat card that isolates its own number draws
 * «75%». Two shapes for one thing on the same screen. The owner chose «75%»
 * (D-141, option أ). A left-to-right mark (U+200E) right before the number gives
 * the digit a strong left-to-right neighbour, so the rule no longer applies: no
 * markup, no caller change, and — unlike the isolate characters — nothing an
 * e-mail client or the PDF renderer draws as an empty box.
 *
 * @see PRD §5.4, §13 · CLAUDE.md «الأرقام لاتينية» · D-141
 */
const LRM = "\u{200E}";

it('D-141: كل نسبة في lang/ar — «:x%» أو «75%» — يسبقها علامة اليسار لليمين U+200E', function (): void {
    $offenders = [];

    foreach (glob(base_path('lang/ar/*.php')) ?: [] as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match_all('/(?<![\x{200E}\d.])(?::\w+|\d+(?:\.\d+)?)%/u', $line, $hits) > 0) {
                // A key that IS only the number-and-sign has no Arabic before it to
                // confuse ('percent' => ':value%'): the mark is still harmless there.
                $offenders[] = basename($file).':'.($number + 1).' → '.implode(' ', $hits[0]);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('D-141: الجملة المعروضة تحمل العلامة قبل الرقم فعلًا', function (): void {
    expect(__('certificates.min_attendance', ['rate' => 75]))->toBe('الحد الأدنى '.LRM.'75%')
        ->and(__('attendance.near_minimum_body', ['rate' => 75]))->toContain(LRM.'75%');
});
