<?php

declare(strict_types=1);

/**
 * Phase 4 (4-ج) — the trainer's tables on a phone.
 *
 * Eight trainer screens drew a table of five to eight columns that scrolled
 * sideways inside its box on a 390px screen (1107px, 914px, 828px…), hiding the
 * status and the action column. Each is now a stack of cards, the way the
 * participant's three were in Phase 3 (D-139): one card per row, every cell
 * carrying its own column's name, the header row kept for assistive technology.
 *
 * These read the SOURCE, because what can rot is a table that was added or edited
 * without its roles and its cell names; the pixels are measured in a browser and
 * recorded in D-143.
 *
 * @see PRD §9.9, §9.11, §9.15 · CONSTITUTION Articles 15, 17, 18 · D-139, D-143
 */
const TRAINER_STACKED_VIEWS = [
    'trainer/sessions', 'trainer/assignments', 'trainer/submissions', 'trainer/participants',
    'trainer/resources', 'trainer/reports', 'trainer/final-project', 'trainer/attendance',
];

/** @return list<string> every `<table class="atable atable--stack …">…</table>` of a view */
function stackedTablesOf(string $view): array
{
    $html = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
    preg_match_all('/<table class="atable atable--stack.*?<\/table>/s', $html, $tables);

    return $tables[0];
}

it('D-143: كل جدول مدرّب بأعمدة عريضة صار بطاقات على الجوال', function (): void {
    foreach (TRAINER_STACKED_VIEWS as $view) {
        expect(stackedTablesOf($view))->not->toBeEmpty($view);
    }

    // …and none of them is left as a bare table with a header and no cards.
    foreach (TRAINER_STACKED_VIEWS as $view) {
        $html = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
        preg_match_all('/<table class="atable"(?:(?!<\/table>).)*<thead/s', $html, $bare);

        expect($bare[0])->toBe([], "{$view}: a table with a header but no `atable--stack`");
    }
});

it('D-143: كل صف وخلية ورأس في الجداول المكدّسة يحمل دوره، وبلا دور خارجها', function (): void {
    foreach (TRAINER_STACKED_VIEWS as $view) {
        foreach (stackedTablesOf($view) as $table) {
            expect(preg_match_all('/<tr(?![^>]*\brole=)/', $table))->toBe(0, "{$view}: <tr> without role")
                ->and(preg_match_all('/<t[dh](?![^>]*\brole=)/', $table))->toBe(0, "{$view}: <td>/<th> without role");
        }

        $html = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
        $outside = preg_replace('/<table class="atable atable--stack.*?<\/table>/s', '', $html) ?? '';

        expect(preg_match('/<(?:tr|td|th|thead|tbody)\b[^>]*\brole=/', $outside))->toBe(0, "{$view}: role outside the stack tables");
    }
});

it('D-143: كل خلية بطاقة تحمل اسم عمودها، ما عدا خلية الإجراءات الأخيرة وخلية الملاحظة', function (): void {
    foreach (TRAINER_STACKED_VIEWS as $view) {
        foreach (stackedTablesOf($view) as $table) {
            preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $table, $rows);

            foreach ($rows[1] as $row) {
                preg_match_all('/<td\b([^>]*)>/', $row, $cells, PREG_SET_ORDER);

                foreach ($cells as $i => $cell) {
                    $isLast = $i === count($cells) - 1;

                    if (str_contains($cell[1], 'data-label=') || str_contains($cell[1], 'colspan=') || $isLast) {
                        continue;
                    }

                    expect(false)->toBeTrue("{$view}: a card cell with no data-label: <td{$cell[1]}>");
                }
            }
        }
    }

    expect(true)->toBeTrue();
});

it('D-143: الرقم اللاتيني داخل خلية بطاقة في عنصر داخلي لا على الخلية — `.u-num` على الخلية يقلب موضع الاسم والقيمة', function (): void {
    foreach (TRAINER_STACKED_VIEWS as $view) {
        foreach (stackedTablesOf($view) as $table) {
            expect(preg_match('/<td\b[^>]*class="[^"]*\bu-num\b/', $table))->toBe(0, "{$view}: u-num on a <td>");
        }
    }
});

it('D-143: ترويسة الصف كلمات لا رمز — تلتفّ، ولا تحدّد عرض الجدول كله', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    expect($css)->toMatch('/\.atable tbody th\[scope="row"\]\s*\{\s*white-space:\s*normal;/');
});

it('D-143: رابط الملف في الجدول هدف لمس حقيقي لا سطر 21px', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    expect($css)->toMatch('/a\.cellpair--inline\s*\{\s*min-block-size:\s*var\(--touch\);/');
});

it('D-143: رأس البطاقة بلا شريط رمادي — تعبئة رأس الصف في الجدول العريض لا تدخل البطاقة', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/@media \(max-width: 1199px\) \{\s*\/\* atable--stack.*?\n\}/s', $css, $block);

    expect($block)->not->toBeEmpty()
        ->and($block[0])->toMatch('/\.atable--stack \.atable__lead\s*\{[^}]*background:\s*transparent/s');
});

it('D-143: مصفوفة الحضور تبقى شبكة — عمود الأسماء لاصق، التواريخ تلتفّ، والمنطقة قابلة للتركيز والتمرير بلوحة المفاتيح', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));
    $view = (string) file_get_contents(resource_path('views/trainer/attendance.blade.php'));

    expect($css)->toMatch('/\.mtable thead th\s*\{[^}]*white-space:\s*normal/s')
        ->and($css)->toMatch('/\.mtable tbody th\[scope="row"\]\s*\{\s*position:\s*sticky|\.mtable thead th:first-child,\s*\.mtable tbody th\[scope="row"\]\s*\{[^}]*position:\s*sticky/s')
        ->and($view)->toMatch('/<div class="tscroll" role="region" tabindex="0" aria-label="[^"]+">\s*<table class="atable mtable">/');
});

it('D-143: عنوان الصف في القوائم يأخذ سطرين قبل أن يُقصّ، وكل سطر وصف سطر مستقل', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // «عرض المشاريع النهائية وتكريم المتدربين وت…» hid which session it was.
    expect($css)->toMatch('/\.row__m b\s*\{[^}]*-webkit-line-clamp:\s*2;/s')
        ->and($css)->not->toMatch('/\.row__m b\s*\{[^}]*white-space:\s*nowrap/s')
        ->and($css)->toMatch('/\.row__m > span\s*\{\s*display:\s*block;/');
});

it('D-143: رابط ملف التسليم في لوحة التقييم هدف لمس 44px لا سطر 28px', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    expect($css)->toMatch('/\.filelist a\s*\{[^}]*min-block-size:\s*var\(--touch\)/');
});

it('D-143: أزرار إجراءات الصف تلتفّ داخل خلية الجدول وتستقر عند طرف البطاقة', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));
    $view = (string) file_get_contents(resource_path('views/trainer/sessions.blade.php'));

    expect($css)->toMatch('/\.row__acts--wrap\s*\{[^}]*flex-wrap:\s*wrap/s')
        ->and($css)->toMatch('/\.atable--stack td > \.row__acts\s*\{\s*margin-inline-start:\s*auto;/')
        ->and($view)->toContain('row__acts row__acts--wrap');
});
