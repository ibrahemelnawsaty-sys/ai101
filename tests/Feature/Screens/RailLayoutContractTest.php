<?php

declare(strict_types=1);

/**
 * The rail holds together at every screen height, for every role (D-115).
 *
 * WHY THIS SUITE EXISTS
 * The rail's link list was a flex child allowed to shrink to nothing
 * (`flex: 1; min-block-size: 0`) and never told to scroll. On any screen
 * shorter than the links needed — a trainer's rail below 768px of height, a
 * trainee's or an administrator's below 900px, which is most laptops — the
 * list went on painting its full height over the identity card, the cohort
 * box and the sign-out row. It was reported from the live host, on the
 * trainer's dashboard, as a jumble of text at the foot of the rail.
 *
 * The browser proof is tools/offline-checks/measure-rail-fit.mjs: every
 * rendered screen, seven heights, open and collapsed. CI has no browser, so
 * these are the tripwires that fail the day the markup loses one of its three
 * regions for some role, or the stylesheet loses the rule that makes the list
 * a scroller of its own.
 *
 * @see PRD §9.5.1 · CONSTITUTION art. 16, 18 · D-86, D-108, D-115
 */

use Illuminate\Support\Facades\File;

/** A stylesheet from resources/css, whitespace collapsed. */
function railCss(string $file): string
{
    return (string) preg_replace('/\s+/', ' ', (string) File::get(resource_path('css/'.$file)));
}

/** Every declaration block written for exactly $selector, not as part of a longer one. */
function railRule(string $css, string $selector): string
{
    preg_match_all('/(?:^|[{}\/])\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $css, $m);

    return implode(' ', $m[1]);
}

/**
 * The regions directly inside the element carrying $class, and the blocks
 * directly inside its account region, each named by its `side__*` class.
 *
 * @return array{regions: list<string>, account: list<string>}
 */
function railStructure(string $html, string $class): array
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $hasClass = static fn (string $name): string => 'contains(concat(" ", normalize-space(@class), " "), " '.$name.' ")';
    $root = $xpath->query('//*['.$hasClass($class).']')->item(0);

    expect($root)->toBeInstanceOf(DOMElement::class, "no .{$class} in the page");

    $name = static function (DOMElement $element): string {
        preg_match('/\bside__[a-z-]+/', $element->getAttribute('class'), $m);

        return $m[0] ?? $element->tagName;
    };

    $children = static fn (DOMNode $node): array => array_values(array_filter(
        iterator_to_array($node->childNodes),
        static fn (DOMNode $child): bool => $child instanceof DOMElement,
    ));

    $regions = array_map($name, $children($root));
    $account = $xpath->query('./*['.$hasClass('side__account').']', $root)->item(0);

    return [
        'regions' => $regions,
        'account' => $account instanceof DOMElement ? array_map($name, $children($account)) : [],
    ];
}

it('D-115: شريط كل دور ثلاث مناطق مباشرة بالترتيب — العلامة ثم الروابط ثم الحساب — في الشريط والدرج', function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));
    $cohort = makeCohort();

    // The account region's blocks, in order: the cohort box only where the
    // shell names a cohort (a trainee, a trainer), then who is signed in,
    // then sign-out — never the cohort box between the last two.
    $screens = [
        'participant' => [makeParticipant($cohort), route('dashboard'), ['side__foot', 'side__identity', 'side__logout']],
        'trainer' => [makeTrainer($cohort), route('trainer.dashboard', ['cohort' => $cohort->id]), ['side__foot', 'side__identity', 'side__logout']],
        'coordinator' => [makeCoordinator($cohort), route('coordinator.dashboard', ['cohort' => $cohort->id]), ['side__identity', 'side__logout']],
        'admin' => [makeAdmin(), route('admin.dashboard'), ['side__identity', 'side__logout']],
        // D-117 — the fifth rail: no cohort, so no cohort box either.
        'system_admin' => [makeSystemAdmin(), route('admin.users.index'), ['side__identity', 'side__logout']],
    ];

    foreach ($screens as $role => [$user, $url, $account]) {
        $html = (string) $this->actingAs($user)->get($url)->assertOk()->getContent();

        // The rail beside the page and its copy in the phone drawer.
        foreach (['side', 'drawer__panel'] as $shell) {
            $rail = railStructure($html, $shell);

            expect($rail['regions'])->toBe(['side__brand', 'side__nav', 'side__account'], "{$role} · .{$shell}")
                ->and($rail['account'])->toBe($account, "{$role} · .{$shell} · account region");
        }
    }
});

it('D-115: قائمة الروابط وحدها تتمرّر ولها أرضية، وطرفا الشريط لا ينكمشان', function (): void {
    $app = railCss('app.css');
    $list = railRule($app, '.side__nav');

    expect($list)->toContain('flex: 1 1 auto')
        ->and($list)->toContain('overflow-y: auto')
        ->and($list)->toContain('min-block-size: var(--side-nav-min)')
        // The pair that shipped: allowed to shrink to nothing, never told to scroll.
        ->and($list)->not->toContain('min-block-size: 0')
        // The active marker hangs 8px outside its link, in the gutter this
        // padding gives back; without it the scroller would clip the marker
        // PRD §9.5.1 asks for on the item's right edge. The block padding must
        // outreach a focus ring (2px outline + 4px offset): at 4px the first
        // link's ring lost its whole top edge.
        ->and($list)->toContain('margin-inline: calc(var(--s3) * -1)')
        ->and($list)->toContain('padding: var(--s2) var(--s3)')
        ->and(railRule($app, '.side__brand'))->toContain('flex: none')
        ->and(railRule($app, '.side__account'))->toContain('flex: none')
        ->and(railCss('tokens.css'))->toContain('--side-nav-min: calc(var(--touch-min) * 3);');
});

it('D-115: الشريط المطويّ يُخفي الاسم عن العين لا عن قارئ الشاشة، ولا شريط تمرير يأكل الأهداف', function (): void {
    $app = railCss('app.css');

    expect(railRule($app, '.shell[data-collapsed="true"] .side__identity-text'))
        ->toContain('clip-path: inset(50%)')
        ->not->toContain('display: none')
        ->and($app)->not->toMatch('/\.shell\[data-collapsed="true"\] \.side__identity-text\s*,/')
        ->and(railRule($app, '.shell[data-collapsed="true"] .side__nav'))->toContain('scrollbar-width: none');
});

it('D-115: تذييل اللوحة يأخذ الهامش الجانبي نفسه الذي للهيدر والمحتوى', function (): void {
    expect(railCss('app.css'))
        ->toMatch('/\.appbar, \.body, \.foot--app \{ padding-inline: max\(var\(--s5\), env\(safe-area-inset-left\), env\(safe-area-inset-right\)\); \}/');
});

it('D-115: كل صنف u-* تكتبه القوالب له قاعدة في الأنماط', function (): void {
    // u-mb-4 was written into two screens and u-mt-3 into one, and neither
    // existed anywhere: a class with no rule fails silently, and the block it
    // sat on touched the next one.
    $css = implode(' ', array_map(
        static fn (SplFileInfo $file): string => (string) File::get($file->getPathname()),
        File::allFiles(resource_path('css')),
    ));

    $missing = [];

    foreach (File::allFiles(resource_path('views')) as $view) {
        preg_match_all('/\bclass="([^"]*)"/', (string) File::get($view->getPathname()), $lists);

        foreach ($lists[1] as $list) {
            foreach (preg_split('/\s+/', $list) ?: [] as $class) {
                if (str_starts_with($class, 'u-') && preg_match('/\.'.preg_quote($class, '/').'(?![\w-])/', $css) !== 1) {
                    $missing[] = $class.' ← '.$view->getRelativePathname();
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

/** The body of the first `name() { … }` in $js, found by matching braces, not by indentation. */
function railMethodBody(string $js, string $name): string
{
    $start = strpos($js, $name.'() {');
    expect($start)->not->toBeFalse("no {$name}() in app.js");

    $open = (int) strpos($js, '{', (int) $start);

    for ($depth = 0, $at = $open; $at < strlen($js); $at++) {
        $depth += match ($js[$at]) {
            '{' => 1, '}' => -1, default => 0
        };

        if ($depth === 0) {
            return substr($js, $open + 1, $at - $open - 1);
        }
    }

    return '';
}

it('D-115: إظهار الرابط الحالي يحرّك القائمة وحدها ولا يحرّك الصفحة — عند الفتح والطيّ وعودة الشريط', function (): void {
    $js = (string) File::get(resource_path('js/app.js'));
    $sidebar = railMethodBody($js, 'function sidebar');
    $reveal = railMethodBody($sidebar, 'revealCurrent');

    // scrollIntoView() scrolls every scrollable ancestor, the page included:
    // a screen would open already moved away from where the reader left it.
    expect($reveal)->toContain('list.scrollTop')
        ->not->toContain('scrollIntoView')
        // On load, after a collapse or an expand (the group labels come and
        // go, so the list changes height), and when the rail comes back at
        // 1024px after a screen opened narrower.
        ->and(railMethodBody($sidebar, 'init'))->toContain('this.$nextTick(() => this.revealCurrent());')
        ->and(railMethodBody($sidebar, 'init'))->toContain("matchMedia('(min-width: 1024px)')")
        ->and(railMethodBody($sidebar, 'toggle'))->toContain('this.$nextTick(() => this.revealCurrent());');
});
