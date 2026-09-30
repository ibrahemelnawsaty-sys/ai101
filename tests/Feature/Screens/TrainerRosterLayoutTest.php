<?php

declare(strict_types=1);

/**
 * Phase 4 (4-أ) — the attendance roster as a working surface.
 *
 * Fifty-seven participants made a screen with a checkbox per row that were 24px
 * wide, a caption sentence beside every one of them, a "bulk" form that could not
 * submit, and its only action sitting a page and a half below the rows it acted
 * on. What is pinned here is presentation and wiring: which class draws what, that
 * each box is its own control, and that the script only COUNTS ticked boxes —
 * what is submitted, and every rule behind it, is the server's (Article 5) and is
 * tested in RosterBulkFormTest and AttendanceRulesTest.
 *
 * @see BR-10, BR-27 · PRD §9.9.7 · CONSTITUTION Articles 5, 15, 17, 18 · D-143
 */

use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    $this->cohort = makeCohort(['status' => 'running']);
    $this->trainer = makeTrainer($this->cohort);
    $this->people = [makeParticipant($this->cohort), makeParticipant($this->cohort), makeParticipant($this->cohort)];
    $this->session = sessionInCohort($this->cohort, riyadhAt('2026-10-05 18:00:00'), riyadhAt('2026-10-05 21:00:00'), ['title' => 'Session A']);
});

function rosterHtml(object $test): string
{
    freezeAt(riyadhAt('2026-10-05 19:00:00'));
    Auth::forgetGuards();

    return $test->actingAs($test->trainer)
        ->get(route('trainer.attendance', ['session' => $test->session->id]))
        ->assertOk()
        ->getContent();
}

it('D-143: كل مربّع في الكشف عنصر مستقل — معرّف فريد وتسمية تشير إليه لا إلى أول مربّع', function (): void {
    $html = rosterHtml($this);

    preg_match_all('/<input[^>]*type="checkbox"[^>]*name="user_id\[\]"[^>]*>/', $html, $boxes);
    preg_match_all('/id="(c-user-id-[^"]+)"/', implode('', $boxes[0]), $ids);

    expect($boxes[0])->toHaveCount(3)
        ->and(array_unique($ids[1]))->toHaveCount(3);

    foreach ($ids[1] as $id) {
        expect($html)->toContain('for="'.$id.'"');
    }
});

it('D-143: لا سمة label-hidden تتسرّب إلى الصفحة ولا جملة مرئية بجوار كل مربّع', function (): void {
    $html = rosterHtml($this);

    expect($html)->not->toContain('label-hidden')
        ->and($html)->toContain('<span class="ui-check__title ui-sr">')
        ->and($html)->toContain('ui-check--bare');
});

it('D-143: مربّع «تحديد الكل» بلا اسم فلا يدخل ما يُرسَل، وشريط الإجراء وبطاقة الكشف يحملان أصنافهما', function (): void {
    $html = rosterHtml($this);

    preg_match('/<div class="selectbar">.*?<\/div>/s', $html, $bar);

    expect($bar)->not->toBeEmpty()
        ->and($bar[0])->toContain((string) __('trainer.attendance.select_all'))
        ->and($bar[0])->not->toContain('name=')
        ->and($html)->toContain('atharBulkSelect')
        ->and($html)->toContain('class="bulkbar"')
        ->and($html)->toContain('ui-card--sticky-bar')
        ->and($html)->toContain('atable atable--stack atable--roster');
});

it('D-143: خلايا الكشف الأربع التي يحدّثها الاستطلاع تحتفظ بـ data-cell وتسمية عمودها معًا', function (): void {
    $html = rosterHtml($this);

    foreach (['in', 'out', 'status', 'edit'] as $cell) {
        expect(preg_match('/<td[^>]*data-label="[^"]+"[^>]*data-cell="'.$cell.'"/', $html))->toBe(1, $cell);
    }
});

it('D-143: كل صف وخلية في جدول الكشف تحمل دورها، وأدوار الجدول لا توجد خارجه', function (): void {
    $html = (string) file_get_contents(resource_path('views/trainer/attendance.blade.php'));

    preg_match_all('/<table class="atable atable--stack.*?<\/table>/s', $html, $tables);

    // The roster, the excuse queue and the recorded sessions — each one whole.
    expect($tables[0])->toHaveCount(3);

    foreach ($tables[0] as $table) {
        expect(preg_match_all('/<tr(?![^>]*\brole=)/', $table))->toBe(0)
            ->and(preg_match_all('/<t[dh](?![^>]*\brole=)/', $table))->toBe(0);
    }

    $outside = preg_replace('/<table class="atable atable--stack.*?<\/table>/s', '', $html) ?? '';

    expect(preg_match('/<(?:tr|td|th|thead|tbody)\b[^>]*\brole=/', $outside))->toBe(0);
});

it('D-143: الشريط اللاصق يلصق فعلًا — البطاقة تقصّ ولا تخفي، وإلا صارت حاوية تمرير ولم يجد الشريط ما يلتصق به', function (): void {
    $components = (string) file_get_contents(resource_path('css/components.css'));
    $screens = (string) file_get_contents(resource_path('css/screens.css'));

    expect($components)->toMatch('/\.ui-card--sticky-bar\s*\{\s*overflow:\s*hidden;\s*overflow:\s*clip;/')
        ->and($screens)->toMatch('/\.bulkbar\s*\{[^}]*position:\s*sticky;[^}]*inset-block-end:\s*0;/s')
        ->and($screens)->toContain('.bulkbar.is-idle { display: none; }');
});

it('D-143: المربّع بلا نص هدف لمس 44px في المحورين — التسمية هي مساحة النقر', function (): void {
    $components = (string) file_get_contents(resource_path('css/components.css'));

    expect($components)->toMatch('/\.ui-check--bare\s*\{[^}]*min-inline-size:\s*var\(--touch\);/');
});

it('D-143: التركيز بلوحة المفاتيح لا يختفي تحت الشريط ولا تحت الترويسة — ارتفاعاهما في حشوة التمرير', function (): void {
    $screens = (string) file_get_contents(resource_path('css/screens.css'));
    $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
    $js = (string) file_get_contents(resource_path('js/app.js'));

    // The bar's height (plus the ring's extent while it is on) is reserved at the END…
    expect($screens)->toContain('scroll-padding-block-end: calc(var(--bulkbar-h) + var(--bulkbar-on) * var(--s2))')
        // …and the fixed header (and the preview bar above it) at the START, going backwards.
        ->and($screens)->toContain('scroll-padding-block-start: calc(var(--header-h) + var(--impbar-h) + var(--s2))')
        // Both properties have a length default, so `calc()` is never given a bare 0.
        ->and($tokens)->toMatch('/--bulkbar-h:\s*0px;/')
        ->and($js)->toContain("setProperty('--bulkbar-h'")
        ->and($js)->toContain("setProperty('--bulkbar-on'")
        ->and($js)->toContain("Alpine.data('atharBulkSelect'");
});

it('D-143: تحت 1200px الشريط ثابت لا لاصق — الشريط اللاصق لا يخرج من نموذجه فيغطّي «تحديد الكل» نفسه', function (): void {
    $screens = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/@media \(max-width: 1199px\) \{\s*\.bulkform.*?\n\}/s', $screens, $block);

    expect($block)->not->toBeEmpty()
        ->and($block[0])->toMatch('/\.bulkbar\s*\{[^}]*position:\s*fixed;/s')
        // The last row is not left under it…
        ->and($block[0])->toContain('.bulkform { padding-block-end: var(--bulkbar-h); }')
        // …it is one line until it is opened, and only when script is there to open it.
        ->and($block[0])->toContain('.bulkbar.is-collapsible:not(.is-open) .bulkbar__fields')
        ->and($block[0])->toContain('.bulkbar.is-collapsible .bulkbar__toggle { display: inline-flex; }');
});

it('D-143: العدّاد يُنطَق من منطقة حيّة دائمة في الصفحة، لا من الشريط المخفي إلى أن يُحدَّد أول صف', function (): void {
    $html = rosterHtml($this);

    preg_match('/<div class="selectbar">.*?<\/form>/s', $html, $form);
    preg_match('/<div class="bulkbar".*?<\/form>/s', $html, $bar);

    expect($form[0] ?? '')->toContain('role="status" aria-live="polite" aria-atomic="true"')
        ->and($form[0] ?? '')->toContain((string) __('trainer.attendance.selected_spoken', ['picked' => 0, 'total' => 3]))
        // The bar itself announces nothing (it is display:none until something is ticked).
        ->and($bar[0] ?? '')->not->toContain('aria-live')
        ->and($bar[0] ?? '')->toContain('aria-controls="bulk-fields"')
        ->and($bar[0] ?? '')->toContain('id="bulk-fields"');
});

it('D-143: مربّع صف البطاقة على جهة «تحديد الكل» نفسها، وحشوة الترويسة بانتقاء يغلب قاعدة البطاقة', function (): void {
    $screens = (string) file_get_contents(resource_path('css/screens.css'));

    expect($screens)->toMatch('/\.atable--roster \.atable__pick\s*\{[^}]*inset-inline-start:\s*0;/s')
        ->and($screens)->not->toMatch('/\.atable--roster \.atable__pick\s*\{[^}]*inset-inline-end/s')
        // `.atable--stack th[scope="row"]` sets `padding: var(--s2) 0`; the roster's reserved
        // room for the box only wins with the same weight (it computed to 0px before).
        ->and($screens)->toMatch('/\.atable--roster\.atable--stack th\[scope="row"\]\s*\{[^}]*padding-inline-start:\s*var\(--touch\)/s');
});

it('D-143: كل لوحة تفتحها روابط المدرب تعلن نفسها ليُؤتى بها إلى الشاشة، والسكربت يفعل ذلك', function (): void {
    $panels = [
        'assignments' => 1, 'attendance' => 2, 'final-project' => 1, 'participants' => 1,
        'sessions' => 2, 'submissions' => 1,
    ];

    foreach ($panels as $view => $count) {
        $html = (string) file_get_contents(resource_path("views/trainer/{$view}.blade.php"));

        expect(substr_count($html, 'data-open-panel tabindex="-1"'))->toBe($count, $view);
    }

    $js = (string) file_get_contents(resource_path('js/app.js'));

    expect($js)->toContain("document.querySelector('[data-open-panel]')")
        ->and($js)->toContain('panel.focus({ preventScroll: true })');
});

it('D-143: حقل في شريط أدوات يتقلّص بدل أن يدفع الصفحة — اسم جلسة طويل أزاح الصفحة كلها 25px على الجوال', function (): void {
    $screens = (string) file_get_contents(resource_path('css/screens.css'));

    expect($screens)->toMatch('/\.toolbar > \.ui-field[^{]*\{[^}]*min-inline-size:\s*0;[^}]*max-inline-size:\s*100%/');
});

it('D-143: نموذج الكشف يعرض خطأ اختيار المتدربين — كان يعود إلى نفسه بلا كلمة', function (): void {
    $html = (string) file_get_contents(resource_path('views/trainer/attendance.blade.php'));

    expect($html)->toContain("@error('user_id')")
        ->and($html)->toContain("@error('user_id.*')");
});

it('D-143: لمصفوفة الحضور مفتاح مرئي للحروف، مبنيّ من المصدر نفسه الذي يرسم الخلايا', function (): void {
    $legend = App\Presenters\Trainer\MatrixCell::legend();

    // One entry per status the grid can draw, plus «not recorded».
    expect($legend)->toHaveCount(count(App\Enums\AttendanceStatus::cases()) + 1);

    foreach (App\Enums\AttendanceStatus::cases() as $i => $status) {
        expect($legend[$i]['code'])->toBe((string) __('attendance.matrix.short.'.$status->value))
            ->and($legend[$i]['label'])->toBe($status->label());
    }

    $html = rosterHtml($this);

    expect($html)->toContain('class="mlegend"');

    foreach ($legend as $item) {
        expect($html)->toContain('mcell mcell--'.$item['variant'])
            ->and($html)->toContain($item['label']);
    }
});

it('D-143: كل نغمة تُنتجها خلية المصفوفة لها قاعدة لون — لا حرف يبقى أسود لأن اسم صنفه لم يطابق', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    foreach (array_unique(array_column(App\Presenters\Trainer\MatrixCell::legend(), 'variant')) as $variant) {
        // Named inside the grid too: `.mtable td` outweighs a bare class, and the tone
        // then changes nothing (it did not, for as long as the class names disagreed).
        expect($css)->toMatch('/\.mtable \.mcell--'.preg_quote($variant, '/').'[^{]*\{\s*color:/');
    }
});

it('D-143: عمود أسماء المصفوفة اللاصق حدّه على جهته الصحيحة في RTL وفي LTR، وحلقة تركيز المنطقة داخلها', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    expect($css)->toContain('box-shadow: calc(var(--bw-hairline) * -1) 0 0 var(--border-1)')
        ->and($css)->toMatch('/\[dir="ltr"\] \.mtable thead th:first-child[^{]*\{\s*box-shadow:\s*var\(--bw-hairline\) 0 0/')
        ->and($css)->toMatch('/\.tscroll\[role="region"\]:focus-visible\s*\{\s*outline-offset:\s*calc\(var\(--s1\) \* -0\.5\)/')
        ->and($css)->toMatch('/@media \(max-width: 699px\) \{\s*\.mtable thead th:first-child[^{]*\{[^}]*white-space:\s*normal/s');
});
