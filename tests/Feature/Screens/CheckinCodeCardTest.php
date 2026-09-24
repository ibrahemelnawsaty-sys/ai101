<?php

declare(strict_types=1);

/**
 * The self-check-in QR card on the attendance screen reaches the browser as a
 * working Alpine component (D-116, the display half of D-106).
 *
 * WHY THIS SUITE EXISTS
 * The card's state was written into the `x-data` attribute of `<x-ui.card>`.
 * Inside a component tag's attributes Blade turns `{{ }}` into PHP and
 * rewrites `@class(...)` and `@style(...)` into bound attributes — and
 * compiles no other directive: `@js(...)` stays the literal text `@js(...)`.
 * The browser received `initialOpen: @js($checkInCode !== null)`,
 * the expression threw a SyntaxError, and the card never once showed the code
 * — to the trainer, the coordinator or the administrator — although the server
 * minted it correctly on every request. Every server-side rule of D-106 had a
 * test (SelfCheckInAndExceptionsTest); the one line between the server's
 * answer and the screen had none.
 *
 * What the card may show is decided on the server alone (BR-07): these tests
 * read the rendered page at each edge of the [S, S+60m] window, ±1 second
 * (Article 20), and require the card to carry exactly that answer.
 *
 * @see D-106, D-116 · BR-07 · CONSTITUTION art. 5, 20
 */

use App\Presenters\Trainer\CheckinCode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Js;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    makeParticipant($this->cohort);

    $this->start = riyadhAt('2026-10-12 18:00:00');
    $this->session = sessionInCohort($this->cohort, $this->start, $this->start->addHours(2));
});

/** The attendance screen for $this->session, as $user sees it. */
function checkinCardPage(object $test, App\Models\User $user): string
{
    return (string) $test->actingAs($user)
        ->get(route('trainer.attendance', ['cohort' => $test->cohort->id, 'session' => $test->session->id]))
        ->assertOk()
        ->getContent();
}

/** The argument the page hands `atharCheckinCode(...)`, exactly as the browser receives it. */
function checkinCardConfig(string $html): string
{
    expect(preg_match('/x-data="atharCheckinCode\((\{.*?\})\)"/s', $html, $m))->toBe(1, 'no atharCheckinCode component on the page');

    return $m[1];
}

it('D-116: بطاقة رمز التحضير الذاتي تصل المتصفح بتعبير مترجَم لا بنصّ @js حرفيّ — للمدرب والمنسّق والمشرف', function (): void {
    freezeAt($this->start);

    $roles = [
        'trainer' => makeTrainer($this->cohort),
        'coordinator' => makeCoordinator($this->cohort),
        'admin' => makeAdmin(),
    ];

    $code = CheckinCode::for($this->session);
    $seen = [];

    // One row per role, so a failure names the role it failed for.
    foreach ($roles as $role => $user) {
        $html = checkinCardPage($this, $user);
        $config = checkinCardConfig($html);

        $seen[$role] = [
            'no literal @js' => ! str_contains($html, '@js('),
            'open' => str_contains($config, 'initialOpen: true,'),
            'url' => str_contains($config, 'initialUrl: '.Js::from($code->get('url'))->toHtml().','),
            'svg' => str_contains($config, 'initialSvg: '.Js::from($code->get('svg'))->toHtml().','),
            'poll' => str_contains($config, "pollUrl: '".route('trainer.attendance.checkinCode', $this->session->id)."',"),
        ];
    }

    $working = ['no literal @js' => true, 'open' => true, 'url' => true, 'svg' => true, 'poll' => true];

    expect($seen)->toBe(['trainer' => $working, 'coordinator' => $working, 'admin' => $working]);
});

it('D-116: البطاقة تحمل قرار الخادم عند حدود نافذة التحضير الذاتي [S, S+60m] بثانية واحدة', function (): void {
    $trainer = makeTrainer($this->cohort);

    // Each edge at -1s, 0 and +1s. S-1s and S+60m+1s: closed, and no code at
    // all reaches the page.
    foreach ([$this->start->subSecond(), $this->start->addMinutes(60)->addSecond()] as $instant) {
        freezeAt($instant);
        $config = checkinCardConfig(checkinCardPage($this, $trainer));

        expect($config)->toContain('initialOpen: false,')
            ->and($config)->toContain("initialUrl: '',")
            ->and($config)->toContain("initialSvg: '',");
    }

    // S, S+1s, S+60m-1s and S+60m: open, carrying the code minted for that
    // very instant.
    foreach ([$this->start, $this->start->addSecond(), $this->start->addMinutes(60)->subSecond(), $this->start->addMinutes(60)] as $instant) {
        freezeAt($instant);
        $config = checkinCardConfig(checkinCardPage($this, $trainer));

        expect($config)->toContain('initialOpen: true,')
            ->and($config)->toContain('initialUrl: '.Js::from(CheckinCode::for($this->session)->get('url'))->toHtml().',');
    }
});

it('D-116: لا توجيه Blade داخل سمات وسم مكوّن <x-…> في أي قالب إلا @class و@style — وحدهما يترجمهما Blade هناك', function (): void {
    // ComponentTagCompiler rewrites exactly these two into bound attributes
    // (`:class` / `:style`); every other directive reaches the page as text.
    $compiled = ['class', 'style'];
    $found = [];

    foreach (File::allFiles(resource_path('views')) as $view) {
        if (! str_ends_with($view->getFilename(), '.blade.php')) {
            continue;
        }

        // A directive named in a Blade comment is prose, not code.
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) File::get($view->getPathname()));

        for ($at = strpos($source, '<x-'); $at !== false; $at = strpos($source, '<x-', $at + 3)) {
            // The tag runs to the first '>' outside a quoted attribute value.
            $quote = null;

            for ($end = $at + 3; $end < strlen($source); $end++) {
                $char = $source[$end];

                if ($quote !== null) {
                    $quote = $char === $quote ? null : $quote;
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    break;
                }
            }

            if (preg_match_all('/(?<![\w@])@([a-zA-Z]\w*)\s*\(/', substr($source, $at, $end - $at), $directives) > 0) {
                foreach (array_diff($directives[1], $compiled) as $directive) {
                    $found[] = $view->getRelativePathname().' — @'.$directive;
                }
            }
        }
    }

    expect($found)->toBe([]);
});
