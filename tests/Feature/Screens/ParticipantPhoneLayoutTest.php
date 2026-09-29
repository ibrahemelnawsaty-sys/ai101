<?php

declare(strict_types=1);

/**
 * Phase 3 (3-ب … 3-د) — the participant's screens hold on a phone.
 *
 * Each rule below was found by opening the real page at 390 px in a real browser
 * (the sweep and the screenshots are the proof; a PHP test cannot see pixels).
 * What can be asserted on the markup is asserted here, so the day one of them
 * comes back it fails in CI, which has no browser.
 *
 * @see PRD §5.4, §13 · CONSTITUTION art. 16, 18 · D-139
 */
beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort(['status' => 'running']);
    $this->participant = makeParticipant($this->cohort);
});

it('D-139: حلقة نسبة الحضور تحمل الرقم وحده — العبارة تحتها لا داخلها فلا تفيض الحلقة', function (): void {
    // The ring is 120px wide. "من جلسات دفعتك" at the heading size was two lines
    // wider than the circle it sat in, over the arc.
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 0);
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'absent', 1);

    $html = (string) $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect(preg_match('/<div class="arate__v">(.*?)<\/div>/s', $html, $inside))->toBe(1)
        ->and($inside[1])->toContain('%')
        ->and($inside[1])->not->toContain(__('attendance.rate.label'))
        // The words are still on the page, as the ring's caption.
        ->and($html)->toContain('class="arate__cap"')
        ->and($html)->toContain(__('attendance.rate.label'));
});

it('D-139: ملاحظة بلا أيقونة تكدّس عناصرها عموديًا — العنوان والنص والتاريخ لا تتجاور في صفٍّ يضغط النص في عمود رفيع', function (): void {
    // `.note` was a row: its heading, its paragraph and its date became three flex
    // items side by side, and a trainer's feedback wrapped one word per line.
    // A note that leads with an icon is still a row (icon | text).
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/^\.note\s*\{([^}]*)\}/m', $css, $base);

    expect($base)->not->toBeEmpty()
        ->and($base[1])->toContain('flex-direction: column')
        ->and($css)->toMatch('/\.note:has\(\s*>\s*svg\s*\)\s*\{[^}]*flex-direction:\s*row/');
});

it('D-139: سطر المدرّب تحت موعد الجلسة سطرٌ مستقل لا يتصل بالتاريخ فيقسم اسمه بين سطرين', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/^\.row__by\s*\{([^}]*)\}/m', $css, $rule);

    expect($rule)->not->toBeEmpty()->and($rule[1])->toContain('display: flex');
});

it('D-139: نص خطوة الرحلة يلوّن ابنه المباشر وحده — لا وسم <span> داخل زر الخطوة فيصير نصّه رماديًا على بنفسجي (تباين 1.07)', function (): void {
    // `.jn__b span { color: muted }` reached the label inside the step's button
    // (the button's text is a span): grey on violet, 1.07:1, an unreadable button.
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    expect($css)->not->toMatch('/\.jn__b\s+span\s*\{/')
        ->and($css)->not->toMatch('/\.jn__b\s+b\s*\{/')
        ->and($css)->toMatch('/\.jn__b\s*>\s*span\s*\{/');
});

it('D-139: سطر يوم في خطوة الرحلة يلتفّ ويحفظ تاريخه في سطر واحد — لا يُقسَّم «السبت 29 أغسطس 2026» على ثلاثة أسطر', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/^\.jn__day\s*\{([^}]*)\}/m', $css, $day);
    preg_match('/^\.jn__day b\s*\{([^}]*)\}/m', $css, $date);

    expect($day)->not->toBeEmpty()->and($day[1])->toContain('flex-wrap: wrap')
        ->and($date)->not->toBeEmpty()->and($date[1])->toContain('white-space: nowrap');
});

it('D-139: وقت الإشعار «قبل 21 ساعة» ثانوي — صغير ورمادي لا بحجم العنوان', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/^\.row__e\s*>\s*time\s*\{([^}]*)\}/m', $css, $time);

    expect($time)->not->toBeEmpty()
        ->and($time[1])->toContain('font-size: var(--fs-xs)')
        ->and($time[1])->toContain('color: var(--text-muted)')
        ->and($time[1])->toContain('white-space: nowrap');
});

/*
|--------------------------------------------------------------------------
| 3-ج — tables become cards on a phone
|--------------------------------------------------------------------------
*/

it('D-139: جدول الجلسات يتحوّل إلى بطاقات على الهاتف — لكل خلية اسم عمودها تحمله بنفسها', function (): void {
    $week = makeWeek($this->cohort, 1, ['title' => 'Week alpha']);
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 0, $week->id);

    $html = (string) $this->actingAs($this->participant)->get(route('schedule'))->assertOk()->getContent();

    expect($html)->toContain('class="atable atable--stack"');

    foreach (['col_date', 'col_time', 'col_topic', 'col_trainer', 'col_status'] as $column) {
        expect($html)->toContain('data-label="'.__('schedule.'.$column).'"');
    }

    // The session's title is the card's own heading: it needs no label.
    expect($html)->toContain('class="atable__lead"');
});

it('D-139: سجل الحضور بطاقات على الهاتف — والخلية الفارغة العنوان (الإجراء) بلا اسم زائف', function (): void {
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 0);

    $html = (string) $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect($html)->toContain('class="atable atable--stack"');

    foreach (['col_date', 'col_check_in', 'col_check_out', 'col_status', 'col_note'] as $column) {
        expect($html)->toContain('data-label="'.__('attendance.'.$column).'"');
    }
});

it('D-139: تفضيلات الإشعارات بطاقة لكل حدث — قناتاه بعنوانيهما لا عمودان فارغان من الأسماء', function (): void {
    $html = (string) $this->actingAs($this->participant)->get(route('profile'))->assertOk()->getContent();

    expect($html)->toContain('class="atable atable--stack atable--prefs"')
        ->and($html)->toContain('data-label="'.__('notifications.channel_platform').'"')
        ->and($html)->toContain('data-label="'.__('notifications.channel_email').'"');
});

it('D-139: قاعدة البطاقات في CSS — تحت 700px يُخفى الرأس ويظهر عنوان كل خلية من data-label', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/@media \(max-width: 699px\) \{\s*\/\* atable--stack.*?\n\}/s', $css, $block);

    expect($block)->not->toBeEmpty()
        ->and($block[0])->toContain('.atable--stack thead')
        ->and($block[0])->toContain('content: attr(data-label)');
});

it('D-139: مفتاح التفضيل يأخذ 44px للضغط ونصّه الطويل للقارئ الآلي وحده', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/\.atable--prefs \.ui-check__title\s*\{([^}]*)\}/', $css, $title);
    preg_match('/\.atable--prefs \.ui-switch\s*\{([^}]*)\}/', $css, $switch);

    expect($title)->not->toBeEmpty()->and($title[1])->toContain('clip-path: inset(50%)')
        ->and($switch)->not->toBeEmpty()->and($switch[1])->toContain('min-block-size: var(--touch)');
});

it('D-139: عنوان الصفحة في الشريط العلوي يلتفّ على الهاتف بدل أن يُقصّ بنقاط — «المحاضرات المب…»', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/@media \(max-width: 699px\) \{\s*\/\* appbar title.*?\n\}/s', $css, $block);

    expect($block)->not->toBeEmpty()
        ->and($block[0])->toContain('.appbar__t h1')
        ->and($block[0])->toContain('white-space: normal');
});

it('D-139: رابط تسليم المتدرّب (مشروع يعمل · GitHub) هدف لمس كامل — 21px كانت أقل من حدّ 44px', function (): void {
    expect((string) file_get_contents(resource_path('views/partials/hand-in-answers.blade.php')))
        ->toContain('class="deflist__link"');

    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/\.deflist__link\s*\{([^}]*)\}/', $css, $rule);

    expect($rule)->not->toBeEmpty()->and($rule[1])->toContain('min-block-size: var(--touch)');
});

/*
|--------------------------------------------------------------------------
| The independent review (Art. 27), each finding pinned
|--------------------------------------------------------------------------
*/

it('D-139: خلية جدول تحمل u-num تبقى خلية جدول — inline-block كان يدمج خلايا كشف المدرّب في خلية بلا اسم', function (): void {
    // `.u-num { display: inline-block }` turned every <td class="u-num"> into an
    // anonymous box: the roster's check-in and check-out merged, and its status
    // landed under the wrong header.
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match('/td\.u-num,\s*th\.u-num\s*\{([^}]*)\}/', $css, $rule);

    expect($rule)->not->toBeEmpty()->and($rule[1])->toContain('display: table-cell');
});

it('D-139: عدّاد الجلسة التالية داخل بطاقة الجدول لا يتجاوز عرضها — 302px في بطاقة 276px يقصّ «ثانية» عند 360px', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/@media \(max-width: 699px\) \{\s*\/\* atable--stack.*?\n\}/s', $css, $block);

    expect($block)->not->toBeEmpty()
        ->and($block[0])->toMatch('/\.atable--stack \.ui-countdown[^{]*\{[^}]*max-inline-size: 100%/');
});

it('D-139: آخر بطاقة في الجدول المكدّس تحتفظ بفواصل خلاياها — قاعدة «آخر صف بلا حدّ» الأقدم تغلب عليها بالتخصيص', function (): void {
    $css = (string) file_get_contents(resource_path('css/screens.css'));

    preg_match('/@media \(max-width: 699px\) \{\s*\/\* atable--stack.*?\n\}/s', $css, $block);

    expect($block)->not->toBeEmpty()
        ->and($block[0])->toContain('.atable--stack tbody tr:last-child td');
});

it('D-139: كل صف وخلية في الجداول المكدّسة تحمل دورها — بما فيها صف الرأس، وبلا دور خارج هذه الجداول', function (): void {
    // The rows and cells stop being table boxes on a phone (display: block/flex),
    // and some assistive technology then drops the table. The explicit roles give
    // it back; one row or cell without a role leaves a hole in it.
    foreach (['participant/schedule', 'participant/attendance', 'participant/profile'] as $view) {
        $html = (string) file_get_contents(resource_path("views/{$view}.blade.php"));

        preg_match_all('/<table class="atable atable--stack.*?<\/table>/s', $html, $tables);

        expect($tables[0])->not->toBeEmpty($view);

        foreach ($tables[0] as $table) {
            expect(preg_match_all('/<tr(?![^>]*\brole=)/', $table))->toBe(0, "{$view}: <tr> without role")
                ->and(preg_match_all('/<t[dh](?![^>]*\brole=)/', $table))->toBe(0, "{$view}: <td>/<th> without role");
        }

        // …and roles exist nowhere else in the view.
        $outside = preg_replace('/<table class="atable atable--stack.*?<\/table>/s', '', $html) ?? '';
        expect(preg_match('/<(?:tr|td|th|thead|tbody)\b[^>]*\brole=/', $outside))->toBe(0, "{$view}: role outside the stack tables");
    }
});
