<?php

declare(strict_types=1);

/**
 * The fixes that make the platform hold on a phone, in every browser the
 * project supports, and in one typeface (D-86).
 *
 * WHY THIS SUITE EXISTS
 * None of these failures showed in a stylesheet or in a PHP test — each was
 * found by opening the real pages in a browser at phone widths
 * (tools/offline-checks/measure-responsive.mjs): every page with a table
 * zoomed out on a phone, the messages list vanished below 860px, the
 * certificate cut off at 320px, the account menu opened off the screen.
 * The browser sweep is the proof; these assertions are the tripwires that
 * fail in CI the day one of those rules is removed, because CI has no
 * browser.
 *
 * @see PRD §5.4, §9.10, §13 · BR-24 · CONSTITUTION art. 16, 18, 19 · D-86
 */

use App\Presenters\Participant\LiveSessionPresenter;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Mail\EmailPalette;
use Illuminate\Support\Facades\Cache;

function cssSource(string $file): string
{
    return (string) file_get_contents(resource_path('css/'.$file));
}

/** Every declaration block for $selector in $css, whitespace collapsed. */
function cssBlocks(string $css, string $selector): string
{
    preg_match_all('/(?:^|[},])\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/m', $css, $m);

    return (string) preg_replace('/\s+/', ' ', implode(' ', $m[1]));
}

// ------------------------------------------------------------- one typeface

it('D-86: خطّ واحد — الأدوار الثلاثة تشير إلى IBM Plex Sans Arabic، ولا @font-face لغيره', function (): void {
    $tokens = cssSource('tokens.css');
    $app = cssSource('app.css');

    expect($tokens)->toContain('--font: "IBM Plex Sans Arabic"')
        ->and($tokens)->toContain('--font-display: var(--font);')
        ->and($tokens)->toContain('--font-mono: var(--font);');

    preg_match_all('/@font-face\s*\{[^}]*font-family:\s*"([^"]+)"/', $app, $faces);

    expect(array_unique($faces[1]))->toBe(['IBM Plex Sans Arabic'])
        ->and(count($faces[1]))->toBe(4)
        ->and(file_exists(public_path('fonts/reem-kufi-variable.woff2')))->toBeFalse();
});

it('D-86: البريد يأخذ الخطّ نفسه، ودور الخطّ الثابت يُحَلّ إليه لا يسقط', function (): void {
    Cache::forget('athar.mail.theme');
    $palette = app(EmailPalette::class)->all();

    // A `var(--font)` alias used to be skipped by the reader, which then threw
    // for a missing mono role — every invitation letter would have failed.
    expect($palette['font'])->toStartWith('"IBM Plex Sans Arabic"')
        ->and($palette['fontMono'])->toBe($palette['font'])
        ->and($palette['font'])->not->toContain('var(');
});

// ------------------------------------------------------------ the phone shell

it('D-86: صندوق التمرير يحتوي ما في داخله — وإلا كبّر الجدول صفحة الجوّال كلها', function (): void {
    $app = cssSource('app.css');

    expect($app)->toMatch('/\.tscroll,\s*\.ui-table-scroll,[^{]*\{\s*position:\s*relative;\s*\}/')
        // …and the content column may not grow past the screen.
        ->and($app)->toContain('.shell { grid-template-columns: minmax(0, 1fr); }')
        ->and($app)->toContain('.shell__main { grid-column: 1 / -1; }');
});

it('D-86: قائمة المحادثات تظهر على الجوّال، والمحادثة المفتوحة تُعلَّم بالصنف الذي يعرفه الأسلوب', function (): void {
    $app = cssSource('app.css');

    expect(cssBlocks($app, '.chat__list'))->toContain('display: block')
        ->and((string) file_get_contents(resource_path('views/participant/messages.blade.php')))
        ->toContain("? 'is-on' : ''");
});

it('D-86: قائمة الحساب تنفتح نحو الشاشة لا خارجها، وأيقونة الملاحظة لها حجم', function (): void {
    $app = cssSource('app.css');

    expect(cssBlocks($app, '.appbar__menu .menu'))->toContain('inset-inline-end: 0')
        ->and(cssBlocks($app, '.note > svg'))->toContain('inline-size: var(--icon-md)');
});

it('D-86: الشريط يطوى ولا تضيع أسماء روابطه', function (): void {
    $app = cssSource('app.css');

    // Visually hidden, never display:none — the label is the link's name.
    expect(cssBlocks($app, '.shell[data-collapsed="true"] .side__b .side__label'))
        ->toContain('clip-path: inset(50%)')
        ->not->toContain('display: none');
});

it('المادة 18: أهداف اللمس 44 على الجوّال، والحقول 16px كي لا يكبّر iOS الصفحة', function (): void {
    $app = cssSource('app.css');
    $tokens = cssSource('tokens.css');

    expect($app)->toContain('.segmented__b { min-block-size: var(--touch-min); }')
        ->and($app)->toContain('select { font-size: var(--fs-input-touch); }')
        ->and($tokens)->toContain('--fs-input-touch: 16px;')
        ->and($tokens)->toContain('--fs-2xs:  12px;');
});

it('D-86: كل dvh يسبقه vh للمتصفحات التي لا تعرفه', function (): void {
    foreach (['app.css', 'components.css', 'screens.css', 'public.css'] as $file) {
        $lines = explode("\n", cssSource($file));

        foreach ($lines as $i => $line) {
            if (! preg_match('/(min-|max-)?block-size:\s*(calc\()?100dvh/', $line, $m)) {
                continue;
            }

            // The same property in vh comes first: on the line before, or
            // earlier on the same line for a one-line rule.
            $property = ($m[1] ?? '').'block-size';
            $before = ($lines[$i - 1] ?? '').' '.substr($line, 0, (int) strpos($line, '100dvh'));

            expect((bool) preg_match('/'.preg_quote($property, '/').':\s*(calc\()?100vh/', $before))
                ->toBeTrue("{$file}:".($i + 1).' has 100dvh with no 100vh before it');
        }
    }
});

it('D-86: طباعة الشهادة لا تعتمد على :has() وحده، وتحفظ ألوانها', function (): void {
    $screens = cssSource('screens.css');

    expect($screens)->toContain('.page--sheet-certificate { page: certificate; }')
        ->and($screens)->toContain('print-color-adjust: exact;')
        ->and((string) file_get_contents(resource_path('views/participant/certificate-print.blade.php')))
        ->toContain("@section('sheet', 'certificate')");
});

// ---------------------------------------------------------- the live screen

it('BR-24: كلمة مرور الاجتماع لا تظهر قبل نافذة الدخول بثانية، وتظهر عندها مع زرّ نسخ', function (): void {
    $cohort = makeCohort();
    $participant = makeParticipant($cohort);
    $start = riyadhAt('2026-10-14 17:00:00');
    $session = sessionInCohort($cohort, $start, $start->addHours(2), [
        'zoom_url' => 'https://example.test/meeting',
        'zoom_passcode' => 'CANARY-PASS',
        'join_opens_minutes' => null,
    ]);
    $window = app(AttendanceWindow::class);
    $opens = $start->subMinutes(App\Http\Controllers\Participant\LiveController::JOIN_OPENS_BEFORE_START_MINUTES);

    expect(LiveSessionPresenter::from($session, $window, $opens->subSecond(), true)->passcode)->toBeNull()
        ->and(LiveSessionPresenter::from($session, $window, $opens, true)->passcode)->toBe('CANARY-PASS')
        // Open, but the join endpoint would refuse this account: no passcode.
        ->and(LiveSessionPresenter::from($session, $window, $opens, false)->passcode)->toBeNull();

    freezeAt($opens->subSecond());
    $this->actingAs($participant)->get(route('live'))->assertOk()->assertDontSee('CANARY-PASS', false);

    freezeAt($opens);
    $this->actingAs($participant)->get(route('live'))
        ->assertOk()
        ->assertSee('CANARY-PASS', false)
        ->assertSee('atharCopy', false)
        // PRD §9.10: the meeting opens in a new tab.
        ->assertSee('target="_blank"', false)
        // The template that threw on every render is gone.
        ->assertDontSee('x-if="passcode"', false);

    // A registrant whose enrolment is still pending sees the page — and not
    // the passcode, exactly as the join endpoint refuses them the link.
    $pending = makeParticipant($cohort);
    App\Models\Enrollment::query()->where('user_id', $pending->id)->update(['status' => 'pending']);

    $this->actingAs($pending)->get(route('live'))->assertDontSee('CANARY-PASS', false);
    $this->actingAs($pending)->post(route('live.join', $session))->assertForbidden();
});

// --------------------------------------------------------------- the header

it('D-86: «البطاقة الرقمية» في قائمة الحساب للمتدرّب وحده — لم تعد رابطًا إلى 403', function (): void {
    $cohort = makeCohort();

    $this->actingAs(makeParticipant($cohort))->get(route('dashboard'))
        ->assertSee(route('participant.card'), false);

    $this->actingAs(makeAdmin())->get(route('admin.dashboard'))
        ->assertDontSee(route('participant.card'), false);
});

it('D-86: بلا جافاسكربت تبقى القائمة وتسجيل الخروج في متناول الجوّال', function (): void {
    $this->actingAs(makeParticipant(makeCohort()))->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<noscript>', false)
        ->assertSee('class="nojs-logout"', false)
        ->assertSee('drawer__host', false);
});
