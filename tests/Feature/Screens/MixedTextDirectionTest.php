<?php

declare(strict_types=1);

/**
 * Phase 3 (3-أ) — Arabic text that carries numbers must be read in Arabic order.
 *
 * WHY THIS SUITE EXISTS
 * `.u-num` forces left-to-right and isolates: exactly right for a serial
 * (`AB12-CD34`), a phone number or a percentage, whose pieces must never be
 * reordered around a hyphen. It was also put on the platform's DATES, TIMES and
 * FILE SIZES — Arabic sentences with digits in them. Inside a forced
 * left-to-right box "23 سبتمبر 2026" is laid out 23 · سبتمبر · 2026 from the
 * left, which the Arabic reader, reading from the right, takes as
 * "2026 سبتمبر 23"; "5:00 مساءً" reads "مساءً 5:00" and "556.6 كيلوبايت" reads
 * "كيلوبايت 556.6". Found by looking at real pages in a real browser (390 and
 * 1280 px) on every role's screens — 56 places — and by a test of the same
 * sentence nobody could write, because a PHP test cannot see bidi.
 *
 * So the tripwires are written on the SOURCE: what may carry `u-num`, and what
 * the score pair must look like. The browser pass is the proof; these fail the
 * day the same mistake comes back.
 *
 * @see PRD §5.4, §13 · CLAUDE.md «الأرقام لاتينية · التواريخ · الأوقات 12 ساعة» · D-139
 */

/** @return list<string> every Blade view of the platform */
function allBladeViews(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function viewName(string $path): string
{
    return str_replace(resource_path('views').'/', '', $path);
}

it('D-139: عنصر u-num لا يحمل ما يطبعه لنا نصٌّ عربي — التواريخ والأوقات والأحجام وعبارات العدد تُقرأ معكوسة فيه', function (): void {
    $offenders = [];

    foreach (allBladeViews() as $file) {
        $html = (string) file_get_contents($file);

        preg_match_all('/<(\w+)\b[^>]*\bclass="[^"]*\bu-num\b[^"]*"[^>]*>(.*?)<\/\1>/s', $html, $hits, PREG_SET_ORDER);

        foreach ($hits as $hit) {
            // `isoUtc` is the machine attribute (`datetime=`), never displayed.
            if (preg_match('/Dates::(?!isoUtc)|Present::fileSize|fileSize\(|sizeLabel|trans_choice\(|::relative\(|@lang|\b__\(/', $hit[2]) === 1) {
                $offenders[] = viewName($file).' → '.mb_substr(preg_replace('/\s+/', ' ', trim($hit[2])) ?? '', 0, 80);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('D-139: الدرجة «3.7 / 12» وحدة واحدة معزولة — لا عنصران متجاوران يُرسم بينهما «/ 123.7»', function (): void {
    // Two neighbouring isolates in a right-to-left line stand right-to-left: the
    // score on the right, "/ 12" to its left, and the eye reads "/ 123.7". The
    // pair is ONE left-to-right unit, or it is not a fraction.
    $offenders = [];

    foreach (allBladeViews() as $file) {
        $html = (string) file_get_contents($file);

        // …a `u-num` number followed by a bare <small> ("/ 12" or "%") is the same
        // pair split in two, whichever way it is written.
        if (preg_match('/<small class="u-num">\s*\/\s*\{\{/', $html) === 1
            || preg_match('/<span class="u-num"[^>]*>[^<]*<\/span>\s*<small>/', $html) === 1
            // …or two isolated numbers with a bare slash between them.
            || preg_match('/<\/span>\s*\/\s*<span class="u-num"/', $html) === 1) {
            $offenders[] = viewName($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('D-139: u-when للنص العربي الذي فيه أرقام — يعزل ولا يفرض اتجاهًا؛ وu-num يبقى يفرض لليتيني وحده', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match('/\.u-when\s*\{([^}]*)\}/', $css, $when);
    preg_match('/\.u-num\s*\{([^}]*)\}/', $css, $num);

    expect($when)->not->toBeEmpty()
        ->and($when[1])->toContain('unicode-bidi: isolate')
        // Arabic reads from the right: the box inherits that, it never overrides it.
        ->and($when[1])->not->toContain('direction: ltr')
        ->and($num[1])->toContain('direction: ltr')->toContain('unicode-bidi: isolate');
});
