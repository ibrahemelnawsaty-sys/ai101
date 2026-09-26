<?php

declare(strict_types=1);

/**
 * The account menu at the end of the app bar, as the owner drew it (D-123).
 *
 * WHAT THESE PIN
 *  · The button carries who is signed in — the name and the role — and its
 *    accessible name is that visible text; the old aria-label replaced it.
 *  · Each role is offered exactly its own entries, in the approved order, and
 *    nothing for a feature that is not built yet (support tickets, the tour,
 *    English, dark mode): an entry that does nothing is the D-54/D-66 defect.
 *  · Every entry opens for the account it is shown to — never a link to a 403.
 *  · Sign-out is back in the menu as a CSRF-protected POST in the danger
 *    colour, and the rail keeps its own copy (D-108).
 *  · The panel stays on the screen and scrolls inside it; on a phone the name
 *    leaves the eye, not the button's accessible name.
 *
 * @see PRD §9.5.2 · D-86, D-108, D-117, D-123 · CONSTITUTION art. 5, 16, 18, 24
 */

use App\Models\User;
use Illuminate\Support\Facades\File;

/** The page parsed for XPath queries. */
function accountMenuXpath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
}

/** An XPath predicate matching one whole class name. */
function accountMenuClass(string $name): string
{
    return 'contains(concat(" ", normalize-space(@class), " "), " '.$name.' ")';
}

/**
 * The hrefs of the menu's links, in document order.
 *
 * @return list<string>
 */
function accountMenuHrefs(string $html): array
{
    $links = accountMenuXpath($html)->query('//*[@id="account-menu"]//a['.accountMenuClass('menu__i').']');
    $hrefs = [];

    foreach ($links === false ? [] : $links as $link) {
        if ($link instanceof DOMElement) {
            $hrefs[] = $link->getAttribute('href');
        }
    }

    return $hrefs;
}

/** A stylesheet from resources/css, whitespace collapsed. */
function accountMenuCss(string $file): string
{
    return (string) preg_replace('/\s+/', ' ', (string) File::get(resource_path('css/'.$file)));
}

/**
 * Each role, signed in on its own home screen, with the entries the menu
 * owes it (D-123): the card for the trainee alone, no information dashboard
 * for the system administrator (D-117), the account page for everyone, and
 * «Support» for everyone who takes part in tickets — not the trainer (D-124).
 *
 * @return array<string, array{0: Closure(): array{0: User, 1: string}, 1: list<string>}>
 */
function accountMenuRoles(): array
{
    return [
        'participant' => [
            static fn (): array => [makeParticipant(makeCohort()), 'dashboard'],
            ['participant.card', 'dashboard', 'profile', 'support.index'],
        ],
        'trainer' => [
            static function (): array {
                $cohort = makeCohort();

                return [makeTrainer($cohort), route('trainer.dashboard', ['cohort' => $cohort->id])];
            },
            ['dashboard', 'profile'],
        ],
        'coordinator' => [
            static function (): array {
                $cohort = makeCohort();

                return [makeCoordinator($cohort), route('coordinator.dashboard', ['cohort' => $cohort->id])];
            },
            ['dashboard', 'profile', 'support.index'],
        ],
        'admin' => [
            static fn (): array => [makeAdmin(), route('admin.dashboard')],
            ['dashboard', 'profile', 'support.index'],
        ],
        'system_admin' => [
            static fn (): array => [makeSystemAdmin(), route('admin.users.index')],
            ['profile', 'support.index'],
        ],
    ];
}

/** The screen a role case opens: a route name or an already built URL. */
function accountMenuScreen(string $screen): string
{
    return str_starts_with($screen, 'http') ? $screen : route($screen);
}

it('D-123: زر الحساب يعرض الاسم والدور، واسمه المقروء هو النص الظاهر نفسه', function (): void {
    $participant = makeParticipant(makeCohort());
    $participant->loadMissing('profile');
    $name = trim((string) (data_get($participant, 'profile.short_name_ar')
        ?: data_get($participant, 'profile.full_name_ar')
        ?: $participant->email));

    $html = (string) $this->actingAs($participant)->get(route('dashboard'))->assertOk()->getContent();
    $xpath = accountMenuXpath($html);
    $button = $xpath->query('//button[@aria-controls="account-menu"]')->item(0);

    expect($button)->toBeInstanceOf(DOMElement::class);
    assert($button instanceof DOMElement);

    // An aria-label would replace the visible name as the button's name, which
    // is what the old icon button did (WCAG 2.5.3).
    expect($button->hasAttribute('aria-label'))->toBeFalse()
        ->and($button->getAttribute('aria-expanded'))->toBe('false')
        // Escape returns focus HERE: Safari and Firefox on macOS never focus
        // a button on a click, so "what was focused before" can be <body>.
        ->and($button->getAttribute('x-ref'))->toBe('trigger')
        ->and($button->getAttribute('class'))->toContain('acct');

    $nameNode = $xpath->query('.//*['.accountMenuClass('acct__name').']', $button)->item(0);
    $roleNode = $xpath->query('.//*['.accountMenuClass('ui-badge').']', $button)->item(0);

    expect($nameNode?->textContent !== null ? trim($nameNode->textContent) : null)->toBe($name)
        ->and($roleNode?->textContent !== null ? trim($roleNode->textContent) : null)->toBe(__('enums.user_role.participant'))
        ->and($button->textContent)->toContain(__('app.accessibility.user_menu'));
});

it('D-123: قائمة الحساب تعرض لكل دور عناصره بالترتيب المعتمد، ولا عنصر لميزة لم تُبنَ', function (Closure $as, array $expected): void {
    [$user, $screen] = $as();

    $html = (string) $this->actingAs($user)->get(accountMenuScreen($screen))->assertOk()->getContent();

    // Exact equality: an extra entry — the tour, English, dark mode — fails
    // here until its feature exists (D-54, D-66). Support is built (D-124),
    // for every role but the trainer, who takes no part in tickets.
    expect(accountMenuHrefs($html))->toBe(array_map(static fn (string $name): string => route($name), $expected));
})->with(accountMenuRoles());

it('D-123: كل رابط في قائمة الحساب يفتح لمن ظهر له — لا رابط إلى 403', function (Closure $as, array $expected): void {
    [$user, $screen] = $as();

    $html = (string) $this->actingAs($user)->get(accountMenuScreen($screen))->assertOk()->getContent();
    $hrefs = accountMenuHrefs($html);

    expect($hrefs)->toHaveCount(count($expected));

    foreach ($hrefs as $href) {
        $this->actingAs($user)->followingRedirects()->get($href)->assertOk();
    }
})->with(accountMenuRoles());

it('D-123: تسجيل الخروج في القائمة نموذج POST بـ CSRF وبلون الخطر، ويبقى في الشريط الجانبي', function (Closure $as): void {
    [$user, $screen] = $as();

    $html = (string) $this->actingAs($user)->get(accountMenuScreen($screen))->assertOk()->getContent();
    $xpath = accountMenuXpath($html);
    $logout = route('logout');

    $menuForms = $xpath->query('//*[@id="account-menu"]//form[@method="POST"][@action="'.$logout.'"]');
    expect($menuForms === false ? 0 : $menuForms->length)->toBe(1);

    $form = $menuForms === false ? null : $menuForms->item(0);
    assert($form instanceof DOMElement);

    $token = $xpath->query('.//input[@type="hidden"][@name="_token"]', $form);
    $button = $xpath->query('.//button[@type="submit"]['.accountMenuClass('menu__i--danger').']', $form);
    $separator = $xpath->query('//*[@id="account-menu"]//hr['.accountMenuClass('menu__sep').']');

    expect($token === false ? 0 : $token->length)->toBe(1)
        ->and($button === false ? 0 : $button->length)->toBe(1)
        ->and($separator === false ? 0 : $separator->length)->toBe(1);

    // D-108 — the rail keeps its own sign-out: the one that needs no script
    // and stays in the 72px rail. The page renders the rail twice (the rail
    // and the drawer's copy), so at least one outside the menu.
    $railForms = $xpath->query('//form['.accountMenuClass('side__logout').'][@method="POST"][@action="'.$logout.'"]');
    expect($railForms === false ? 0 : $railForms->length)->toBeGreaterThanOrEqual(1);
})->with(array_map(static fn (array $case): array => [$case[0]], accountMenuRoles()));

it('D-123: عنصر الصفحة الحالية وحده يحمل aria-current', function (): void {
    $participant = makeParticipant(makeCohort());
    $current = static function (string $html): array {
        $xpath = accountMenuXpath($html);
        $links = $xpath->query('//*[@id="account-menu"]//a[@aria-current="page"]');
        $hrefs = [];

        foreach ($links === false ? [] : $links as $link) {
            if ($link instanceof DOMElement) {
                $hrefs[] = $link->getAttribute('href');
            }
        }

        return $hrefs;
    };

    $onProfile = (string) $this->actingAs($participant)->get(route('profile'))->assertOk()->getContent();
    $onDashboard = (string) $this->actingAs($participant)->get(route('dashboard'))->assertOk()->getContent();

    expect($current($onProfile))->toBe([route('profile')])
        ->and($current($onDashboard))->toBe([route('dashboard')]);
});

it('D-123: كل أيقونة في قائمة الحساب مرسومة في رموز لوحة التحكم', function (): void {
    $html = (string) $this->actingAs(makeParticipant(makeCohort()))->get(route('dashboard'))->assertOk()->getContent();
    $uses = accountMenuXpath($html)->query('//*['.accountMenuClass('appbar__menu').']//*[local-name()="use"]');
    $sprite = File::get(resource_path('views/components/layout/icons.blade.php'));
    $missing = [];
    $seen = 0;

    foreach ($uses === false ? [] : $uses as $use) {
        if (! $use instanceof DOMElement) {
            continue;
        }

        $seen++;
        $id = ltrim($use->getAttribute('href'), '#');

        if (! str_contains($sprite, 'id="'.$id.'"')) {
            $missing[] = $id;
        }
    }

    // A missing symbol draws an empty square and throws nothing.
    expect($seen)->toBeGreaterThanOrEqual(5)
        ->and($missing)->toBe([]);
});

it('D-123: الأنماط — القائمة داخل الشاشة وتتمرّر، والاسم على الجوّال يغيب عن العين لا عن قارئ الشاشة', function (): void {
    $app = accountMenuCss('app.css');

    preg_match('/\.appbar__menu \.menu \{([^}]*)\}/', $app, $panel);
    preg_match('/@media \(max-width: 767px\) \{ \.acct \{[^}]*\} \.acct__who \{([^}]*)\}/', $app, $phone);
    preg_match('/\.acct \{([^}]*)\}/', $app, $button);

    // Opens INTO the screen (D-86) and stops at its bottom — below the preview
    // banner too (--impbar-h) — scrolling inside.
    expect($panel[1] ?? '')->toContain('inset-inline-end: 0')
        ->toContain('max-block-size: calc(100vh - var(--impbar-h) - var(--header-h) - var(--s6))')
        ->toContain('max-block-size: calc(100dvh - var(--impbar-h) - var(--header-h) - var(--s6))')
        ->toContain('overflow-y: auto')
        // Focus scrolls only to the entry's own box; the ring needs the room.
        ->toContain('scroll-padding-block: var(--s2)');

    // Visually hidden, never display:none — the name is the button's name.
    expect($phone[1] ?? '')->toContain('clip-path: inset(50%)')
        ->not->toContain('display: none');

    // A finger-sized target, and sign-out in the danger colour, not by colour
    // alone: its label says what it does.
    expect($button[1] ?? '')->toContain('min-block-size: var(--touch)')
        ->and($app)->toContain('.menu__i--danger, .menu__i--danger svg { color: var(--bad-700); }')
        ->and($app)->toContain('.acct[aria-expanded="true"] .acct__chev { transform: rotate(180deg); }');
});

it('D-123: في المعاينة تعرض القائمة الحساب المعايَن وروابطه، وكلها تفتح (BR-33)', function (): void {
    $systemAdmin = makeSystemAdmin();
    $participant = makeParticipant(makeCohort());

    $this->actingAs($systemAdmin)->post(route('admin.users.preview', $participant))->assertRedirect();

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
    $menu = accountMenuXpath($html)->query('//*['.accountMenuClass('appbar__menu').']')->item(0);

    // A preview shows the account as its holder sees it (BR-33): the menu is
    // the previewed trainee's — its e-mail and its card — not the previewer's.
    expect($menu?->textContent)->toContain($participant->email)
        ->not->toContain($systemAdmin->email)
        ->and(accountMenuHrefs($html))->toBe([route('participant.card'), route('dashboard'), route('profile'), route('support.index')]);

    foreach (accountMenuHrefs($html) as $href) {
        $this->followingRedirects()->get($href)->assertOk();
    }
});

it('D-123: اسم العرض يُطبع في الزر نصًّا لا شيفرة (المادة 24)', function (): void {
    $participant = makeParticipant(makeCohort());
    App\Models\Profile::factory()->create([
        'user_id' => $participant->id,
        'first_name_ar' => '<img src=x onerror=alert(1)>',
        'last_name_ar' => '"\'><svg onload=alert(2)>',
    ]);

    $html = (string) $this->actingAs($participant->fresh())->get(route('dashboard'))->assertOk()->getContent();
    $name = accountMenuXpath($html)->query('//button[@aria-controls="account-menu"]//*['.accountMenuClass('acct__name').']')->item(0);

    // The hostile name arrives as text inside the button, and nowhere as markup.
    expect($name?->textContent)->toContain('<img src=x onerror=alert(1)>')
        ->and($html)->not->toContain('<img src=x onerror')
        ->and($html)->not->toContain('<svg onload=alert(2)>');
});

it('D-123: من التحق متدربًا وهو مدرّب يرى البطاقة في القائمة كما يراها في الشريط ويفتحها المسار', function (): void {
    $studied = makeCohort();
    $trainer = makeTrainer(makeCohort());
    enroll($trainer, $studied, 'participant');

    // The card is offered by the same check its route makes (role:participant
    // through RoleResolver), not by the account's own role column (art. 6).
    $html = (string) $this->actingAs($trainer)->followingRedirects()->get(route('dashboard'))->assertOk()->getContent();

    expect(accountMenuHrefs($html))->toContain(route('participant.card'));
    $this->actingAs($trainer)->get(route('participant.card'))->assertOk();
});
