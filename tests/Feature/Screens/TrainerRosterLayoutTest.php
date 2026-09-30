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

it('D-143: التركيز بلوحة المفاتيح لا يختفي تحت الشريط — ارتفاعه يُكتب في حشوة التمرير', function (): void {
    $screens = (string) file_get_contents(resource_path('css/screens.css'));
    $js = (string) file_get_contents(resource_path('js/app.js'));

    expect($screens)->toContain('scroll-padding-block-end: var(--bulkbar-h, 0px)')
        ->and($js)->toContain("setProperty('--bulkbar-h'")
        ->and($js)->toContain("Alpine.data('atharBulkSelect'");
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
