<?php

declare(strict_types=1);

/**
 * The certificate simulator never shows a visitor a variable name or a NaN.
 *
 * WHY THIS SUITE EXISTS
 * Both of these were live on the landing page:
 *
 *     <span id="simPctA">NaN</span>
 *     ينقصك حضور :count جلسة للوصول إلى 75%
 *
 * The NaN: `attended / SESSIONS` on a cohort whose sessions had not been
 * created yet. `data-sessions="0"` is a perfectly good number, so the reader's
 * fallback — which only fires for an absent or unparseable attribute — never
 * ran, and 0/0 rounded to NaN.
 *
 * The `:count`: the Arabic paucal and plural forms carry their own placeholder
 * ("‏:count جلسات"). `plural()` returned the form raw, and `fill()` injected it
 * into the sentence AFTER its single pass had gone past that position — so the
 * placeholder inside the injected fragment was never completed. Every other
 * placeholder on that page is fine, which is why nobody looked here.
 *
 * The second bug is the interesting one: no amount of reading `landing.php` or
 * `landing.js` in isolation reveals it. It only exists in the seam between
 * them, which is why this suite tests the RENDERED page rather than either
 * side's source.
 *
 * @see PRD §9.1 · CONSTITUTION.md Article 15, Article 17 · D-50
 */

use Illuminate\Support\Facades\File;

/**
 * Every placeholder `landing.js` supplies to `fill()`, across all call sites.
 * Read off the source, not guessed: `spare`, `need`, `score_over`,
 * `score_under` and the four verdict bodies between them pass exactly these.
 */
const SIMULATOR_SUPPLIED = [
    'count', 'sessions', 'min', 'tasks', 'project', 'pass', 'short', 'total', 'attendance',
];

it('D-50: كل نائب يصل المتصفح له قيمة تملؤه', function (): void {
    $body = $this->get(route('home'))->assertOk()->getContent();

    // The strings the script completes in the browser: the copy templates and
    // the four Arabic plural forms.
    preg_match_all('/data-(?:t|sessions)-[a-z_]+="([^"]*)"/', $body, $carried);

    expect($carried[1])->not->toBeEmpty('the simulator carries no copy at all');

    $offenders = [];

    foreach ($carried[1] as $template) {
        preg_match_all('/:([a-z_]+)/', html_entity_decode($template), $found);

        foreach (array_unique($found[1]) as $placeholder) {
            if (! in_array($placeholder, SIMULATOR_SUPPLIED, true)) {
                $offenders[] = sprintf('":%s" in "%s" is never supplied', $placeholder, mb_substr($template, 0, 60));
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('D-50: صيغة الجمع تُكمل نائبها قبل أن تُحقن في جملة', function (): void {
    // The exact bug: a fragment carrying :count, injected by a single-pass
    // replacer into a position it had already left behind.
    $source = (string) File::get(resource_path('js/landing.js'));

    expect($source)->toMatch('/function plural\(forms, n\)/')
        ->and($source)->toContain("return fill(form, { count: n });")
        // The old shape returned the raw form and must not come back.
        ->and($source)->not->toMatch('/if \(n === 1\) return forms\.one;/');
});

it('D-50: المحاكي لا يقسم على صفر', function (): void {
    // A named helper, so the guard cannot be forgotten at one of four call
    // sites — which is how three were fine and the fourth produced NaN.
    $source = (string) File::get(resource_path('js/landing.js'));

    expect($source)->toContain('function pct(part, whole)')
        ->and($source)->toContain('whole > 0 ?')
        ->and($source)->not->toContain('(attended / SESSIONS) * 100')
        ->and($source)->not->toContain('(tasksDone / TASKS) * 100')
        ->and($source)->not->toContain('(projectScore / PROJECT_POINTS) * 100');
});

it('D-50: لا نائب خام في نصّ تقرؤه العين على صفحة الهبوط', function (): void {
    // The end of the chain. `data-*` attributes legitimately carry unfinished
    // templates for the browser to complete; the visible text may not.
    $body = $this->get(route('home'))->assertOk()->getContent();

    $visible = (string) preg_replace('/<script[^>]*>.*?<\/script>/s', '', $body);
    $visible = (string) preg_replace('/\s(?:data|aria)-[a-z_-]+="[^"]*"/', '', $visible);
    $visible = strip_tags($visible);

    expect($visible)->not->toMatch('/:('.implode('|', SIMULATOR_SUPPLIED).')\b/');
});
