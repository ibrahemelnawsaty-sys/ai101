<?php

declare(strict_types=1);

/**
 * The shared interaction primitives of the UI overhaul, phase 0 (D-127): the
 * icon-only button and its quiet destructive variant, the one confirmation
 * dialog, the server-open panel, the double-submit guard, the tone glyphs on
 * toasts and the calmer preview bar.
 *
 * None of these decides anything the server decides: every form a confirm wraps
 * is still validated and authorised on the server (Article 5). What is pinned
 * here is that a person is told what is about to happen, that nothing that
 * names an action is left without a name (Article 18), and that colour is never
 * the only carrier of a tone.
 *
 * @see D-127 · PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 17, 18, 23
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

it('D-127: an icon-only button keeps its words as an accessible name and a tooltip', function (): void {
    $html = Blade::render('<x-ui.button icon="pencil" :icon-only="true" label="Edit the session" variant="ghost" size="sm">ignored</x-ui.button>');

    expect($html)->toContain('aria-label="Edit the session"')
        ->and($html)->toContain('data-tip="Edit the session"')
        ->and($html)->toContain('ui-btn--icon')
        ->and($html)->toContain('#i-pencil')
        ->and($html)->not->toContain('<span>ignored</span>'); // the slot is the name, not drawn beside the glyph
});

it('D-127: an icon-only button takes its name from the slot when no label is given', function (): void {
    $html = Blade::render('<x-ui.button icon="trash" :icon-only="true">Remove trainer</x-ui.button>');

    expect($html)->toContain('aria-label="Remove trainer"')
        ->and($html)->toContain('data-tip="Remove trainer"');
});

it('D-127: an icon-only button with no name at all is not rendered icon-only', function (): void {
    $html = Blade::render('<x-ui.button icon="pencil" :icon-only="true"></x-ui.button>');

    expect($html)->not->toContain('ui-btn--icon')
        ->and($html)->not->toContain('aria-label=');
});

it('D-127: danger-ghost is the quiet destructive trigger and an unknown variant still falls back', function (): void {
    expect(Blade::render('<x-ui.button variant="danger-ghost">x</x-ui.button>'))->toContain('ui-btn--danger-ghost')
        ->and(Blade::render('<x-ui.button variant="nonsense">x</x-ui.button>'))->toContain('ui-btn--primary');
});

it('D-127: the confirmation dialog names the action, puts Cancel first and the action last, and spoofs the verb', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm name="remove-trainer-1" action="/admin/x" method="DELETE"
            title="Remove Sara from the cohort" description="She loses access to its sessions."
            confirm-label="Remove trainer" trigger-label="Remove" trigger-icon="trash"
            reason-name="reason" :reason-required="true" />',
    ), ENT_QUOTES);

    expect($html)->toContain('role="dialog"')
        ->and($html)->toContain('Remove Sara from the cohort')
        ->and($html)->toContain('She loses access to its sessions.')
        ->and($html)->toContain('name="_method" value="DELETE"')
        ->and($html)->toContain('name="_token"')
        ->and($html)->toContain('ui-btn--danger-ghost') // the trigger is quiet; the solid danger lives in the dialog
        ->and($html)->toContain("\$dispatch('ui-dialog-open', 'remove-trainer-1')")
        ->and($html)->toContain('name="reason"')
        ->and($html)->toContain('form="remove-trainer-1-form"');

    $cancel = strpos($html, __('ui.confirm.cancel'));
    $confirm = strrpos($html, 'Remove trainer');

    expect($cancel)->not->toBeFalse()
        ->and($confirm)->not->toBeFalse()
        ->and($cancel)->toBeLessThan($confirm); // the action is the last thing in the row
});

it('D-127: a confirmation the server renders open leaves through a plain link, so it works without scripting', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm name="revoke-1" action="/admin/y" title="Revoke the certificate"
            confirm-label="Revoke" :open="true" close-href="/admin/certificates" />',
    ), ENT_QUOTES);

    expect($html)->toContain('href="/admin/certificates"')
        ->and($html)->toContain('closeHref:')
        ->and($html)->not->toContain("ui-dialog-close', 'revoke-1'"); // Cancel is a link here, not a client-side close
});

it('D-127: the confirmation id can never carry script into the Alpine expression', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm :name="$name" action="/x" title="T" confirm-label="Go" trigger-label="Open" />',
        ['name' => "x');alert(1);('"],
    ), ENT_QUOTES);

    expect($html)->not->toContain('alert(1)')
        ->and($html)->toContain("\$dispatch('ui-dialog-open', 'x-alert-1')");
});

it('D-127: a side panel rendered open by the server leaves through a link to the list', function (): void {
    $html = Blade::render('<x-ui.drawer name="edit-panel" title="Edit resource" :open="true" close-href="/trainer/resources">body</x-ui.drawer>');

    expect($html)->toContain('href="/trainer/resources"')
        ->and($html)->toContain('closeHref:')
        ->and($html)->toContain('role="dialog"');
});

it('D-127: the double-submit guard never disables the pressed button, skips GET and new-tab forms, and writes nothing to the console', function (): void {
    $source = (string) File::get(resource_path('js/submit-guard.js'));

    // A disabled submitter is left out of the form data: `name="next" value="1"`
    // would be lost, and the server could not tell which button was pressed.
    expect($source)->not->toContain('.disabled = true')
        ->and($source)->not->toContain('setAttribute(\'disabled\'')
        ->and($source)->not->toContain('console.')
        ->and($source)->toContain("method === 'get'")
        ->and($source)->toContain("'_self'")
        ->and($source)->toContain('data-submit-guard')
        ->and((string) File::get(resource_path('js/app.js')))->toContain("import './submit-guard.js'");
});

it('D-127: every tone a toast can carry has its own glyph, and each glyph is drawn', function (): void {
    $app = (string) File::get(resource_path('js/app.js'));
    $sprite = (string) File::get(resource_path('views/partials/icon-sprite.blade.php'));

    expect(preg_match('/const TOAST_ICONS = \{(.*?)\};/s', $app, $block))->toBe(1);

    preg_match_all("/(\w+):\s*'#(i-[a-z-]+)'/", $block[1], $pairs, PREG_SET_ORDER);
    $tones = array_column($pairs, 1);
    $glyphs = array_column($pairs, 2);

    expect($tones)->toEqualCanonicalizing(['ok', 'info', 'warn', 'bad'])
        ->and(count(array_unique($glyphs)))->toBe(4, 'an error and a warning must not share one drawing');

    foreach ($glyphs as $glyph) {
        expect($sprite)->toContain('id="'.$glyph.'"');
    }

    foreach (['app', 'auth'] as $layout) {
        expect((string) File::get(resource_path("views/layouts/{$layout}.blade.php")))->toContain('toast__ic');
    }
});

it('D-127, المادة 23: the preview bar is a labelled region and announces only twice, never every second', function (): void {
    $bar = (string) File::get(resource_path('views/components/layout/impersonation-bar.blade.php'));
    $js = (string) File::get(resource_path('js/app.js'));

    // The stop control is still always present (Article 23).
    expect($bar)->toContain('role="region"')
        ->and($bar)->toContain('data-impersonation-bar')
        ->and($bar)->toContain('impbar__stop')
        ->and($bar)->not->toContain('aria-live="assertive"')
        ->and($bar)->not->toContain('<p class="impbar__timer" aria-live')
        ->and($bar)->toContain('role="status" aria-live="polite" x-text="announcement"')
        ->and($js)->toContain('prevLeft')
        ->and($js)->not->toContain('announced.')
        ->and($bar)->toContain('data-submit-guard="off"')
        ->and($js)->toContain('config.fiveMinutes')
        ->and($js)->toContain('config.oneMinute');

    $missing = [];

    foreach (['ar', 'en'] as $locale) {
        $lang = (array) require lang_path("{$locale}/admin.php");

        foreach (['region', 'five_minutes_left', 'one_minute_left'] as $key) {
            if (! is_string($lang['impersonation'][$key] ?? null)) {
                $missing[] = "{$locale}: admin.impersonation.{$key}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('D-127: a button that carries the form__submit class is a flex button, so its glyph never stacks above its label', function (): void {
    // `.form__submit` is a grid meant for a wrapper; six auth screens put it on
    // the button itself. Without this rule the glyph became a second grid row.
    expect((string) File::get(resource_path('css/screens.css')))
        ->toContain('.ui-btn.form__submit { display: flex; inline-size: 100%; }');
});

it('D-127: a panel that closes by navigating only ever navigates to a path on this site', function (): void {
    $render = static fn (string $tag, string $href): string => html_entity_decode(
        Blade::render($tag, ['href' => $href]),
        ENT_QUOTES,
    );

    foreach (['//evil.example/x', '/\\evil.example', 'javascript:alert(1)', 'https://evil.example/', "/ok\nx", ''] as $bad) {
        foreach ([
            '<x-ui.modal name="m" title="T" :open="true" :close-href="$href">b</x-ui.modal>',
            '<x-ui.drawer name="d" title="T" :open="true" :close-href="$href">b</x-ui.drawer>',
            '<x-ui.confirm name="c" action="/x" title="T" confirm-label="Go" :open="true" :close-href="$href" />',
        ] as $tag) {
            $html = $render($tag, $bad);

            expect($html)->not->toContain('evil.example')
                ->and($html)->not->toContain('javascript:alert')
                ->and($html)->toContain('role="dialog"');
        }
    }

    expect($render('<x-ui.drawer name="d" title="T" :open="true" :close-href="$href">b</x-ui.drawer>', '/trainer/resources?open=3'))
        ->toContain('href="/trainer/resources?open=3"');
});

it('D-127: the confirmation dialog is never unnamed, and a reason typed earlier is only shown where the caller says so', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm name="c1" action="/x" confirm-label="Remove trainer" reason-name="reason" />
         <x-ui.confirm name="c2" action="/x" title="Remove Sara" confirm-label="Remove" reason-name="reason" reason-value="She left" :open="true" />',
    ), ENT_QUOTES);

    // No title given: the confirm label is the accessible name.
    expect($html)->toContain('aria-labelledby="c1-title"')
        ->and($html)->toContain('Remove trainer')
        // Only the dialog the caller re-renders carries the typed reason.
        ->and(substr_count($html, 'She left'))->toBe(1);
});

it('D-127: the submit guard also lets go of a button that lives outside its form, and a busy button stays named', function (): void {
    $guard = (string) File::get(resource_path('js/submit-guard.js'));
    $css = (string) File::get(resource_path('css/components.css'));

    // <button form="id"> in the confirmation footer is not a descendant of the form.
    expect($guard)->toContain("document.querySelectorAll('.ui-btn.is-loading')")
        ->and($guard)->toContain('button.form !== form')
        ->and($guard)->not->toContain("form.querySelectorAll('.ui-btn.is-loading')");

    // states.css hides every child of .is-loading; a busy button must not go blank.
    expect($css)->toContain('.ui-btn.is-loading > * { visibility: visible; }');
});

it('D-127: ending a preview and logging out are never held back by the double-submit guard', function (): void {
    $stopForm = (string) File::get(resource_path('views/components/layout/impersonation-bar.blade.php'));

    expect($stopForm)->toContain('data-submit-guard="off"');

    $missing = [];

    foreach ([
        'components/layout/header',
        'components/layout/sidebar',
        'auth/first-password',
        'auth/verify-email-notice',
    ] as $view) {
        preg_match_all('/<form[^>]*logout[^>]*>/', (string) File::get(resource_path("views/{$view}.blade.php")), $forms);

        foreach ($forms[0] as $form) {
            if (! str_contains($form, 'data-submit-guard="off"')) {
                $missing[] = "{$view}: {$form}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('D-127: an icon-only button in a list says WHICH row it acts on, and never double-escapes its name', function (): void {
    $html = Blade::render('<x-ui.button icon="pencil" :icon-only="true" label="Edit" context="Cohort A">x</x-ui.button>');

    // Read aloud: "Edit — Cohort A". Seen on hover: just "Edit".
    expect(html_entity_decode($html, ENT_QUOTES))->toContain('aria-label="Edit — Cohort A"')
        ->and($html)->toContain('data-tip="Edit"');

    $escaped = Blade::render('<x-ui.button icon="pencil" :icon-only="true">{{ $t }}</x-ui.button>', ['t' => "Q&A's"]);

    expect($escaped)->toContain('aria-label="Q&amp;A&#039;s"')
        ->and($escaped)->not->toContain('&amp;amp;');
});

it('D-127: every icon-only button that sits in a list carries its row as context', function (): void {
    $missing = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $relative = str_replace(resource_path('views').'/', '', $file->getPathname());

        // The components define the prop; the digital card's two buttons act on the page, not on a row.
        if (str_starts_with($relative, 'components/') || $relative === 'participant/card.blade.php') {
            continue;
        }

        foreach (explode('<x-ui.button', (string) File::get($file->getPathname())) as $index => $chunk) {
            $tag = explode('</x-ui.button>', $chunk)[0];

            if ($index > 0 && str_contains($tag, ':icon-only="true"') && ! str_contains($tag, ':context=')) {
                $missing[] = $relative.': '.trim(substr($tag, 0, 90));
            }
        }
    }

    expect($missing)->toBe([]);
});

it('D-127: the tooltip leaves no box behind when hidden, hangs inward inside a scrolling table, and yields to Esc', function (): void {
    $css = (string) File::get(resource_path('css/components.css'));
    $js = (string) File::get(resource_path('js/ui.js'));

    preg_match('/\[data-tip\]::after \{(.*?)\n\}/s', $css, $rule);

    // A `visibility:hidden` box still widens a scroll container; `display:none` does not.
    expect($rule[1] ?? '')->toContain('display: none;')
        ->and($rule[1] ?? '')->not->toContain('visibility: hidden')
        ->and($css)->toContain('[data-tip]:hover::after,')
        ->and($css)->toContain('.tscroll [data-tip]::after { inset-inline-start: auto; inset-inline-end: 0; translate: none; }')
        ->and($css)->toContain('[data-tip][data-tip-dismissed]::after { display: none; }')
        ->and($js)->toContain("event.key !== 'Escape'")
        ->and($js)->toContain('data-tip-dismissed');
});

it('D-127: a panel the server renders open is visible with scripting off; one that starts closed is cloaked', function (): void {
    foreach (['modal', 'drawer'] as $component) {
        $open = Blade::render("<x-ui.{$component} name=\"p\" title=\"T\" :open=\"true\">b</x-ui.{$component}>");
        $closed = Blade::render("<x-ui.{$component} name=\"p\" title=\"T\">b</x-ui.{$component}>");

        expect($open)->not->toContain('x-cloak')
            ->and($closed)->toContain('x-cloak');
    }
});

it('D-127: the submit guard never times out a multipart upload, and a busy icon-only button trades its glyph for the spinner', function (): void {
    expect((string) File::get(resource_path('js/submit-guard.js')))->toContain("form.enctype !== 'multipart/form-data'")
        ->and((string) File::get(resource_path('css/components.css')))->toContain('.ui-btn--icon.is-loading > .ui-icon { display: none; }');
});

it('D-127: every confirmation dialog gives its reason field an id of its own', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm name="a" action="/x" title="T" confirm-label="Go" reason-name="reason" />
         <x-ui.confirm name="b" action="/x" title="T" confirm-label="Go" reason-name="reason" />',
    ), ENT_QUOTES);

    expect($html)->toContain('id="a-form-reason"')
        ->and($html)->toContain('id="b-form-reason"');
});

it('D-127: an icon-only button is a full 44px target on every pointer, not only on touch screens', function (): void {
    $css = (string) File::get(resource_path('css/components.css'));
    $tokens = (string) File::get(resource_path('css/tokens.css'));

    expect($tokens)->toContain('--touch-min:')
        ->and($css)->toContain('.ui-btn--icon.ui-btn--sm { inline-size: var(--touch); min-block-size: var(--touch); }')
        // The old rule gave a small icon button 36px unless the device was coarse.
        ->and($css)->not->toContain('.ui-btn--icon.ui-btn--sm { inline-size: var(--s9); }');
});

it('D-133: a confirmation can confirm a form that already lives on the page, drawing none of its own', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm name="change-role" title="T" confirm-label="Go" variant="primary" target-form="role-change-form"><p>The summary</p></x-ui.confirm>',
    ), ENT_QUOTES);

    expect($html)->toContain('form="role-change-form"')
        ->and($html)->toContain('<p>The summary</p>')
        ->and($html)->not->toContain('<form')
        ->and($html)->not->toContain('_token'); // nothing is posted from the dialog itself
});

it('D-133: the id of the form a confirmation targets can carry nothing but id characters', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm name="c" title="T" confirm-label="Go" :target-form="$form"><p>x</p></x-ui.confirm>',
        ['form' => 'a"><script>alert(1)</script>'],
    ), ENT_QUOTES);

    expect($html)->not->toContain('<script>alert')
        ->and($html)->toContain('form="a-script-alert-1-script"');
});

it('D-133: a card can sit under a heading of its own, so its title is one level lower', function (): void {
    $default = Blade::render('<x-ui.card title="T">b</x-ui.card>');
    $nested = Blade::render('<x-ui.card :level="3" title="T">b</x-ui.card>');
    $clamped = Blade::render('<x-ui.card :level="9" title="T">b</x-ui.card>');

    expect($default)->toContain('<h2 class="ui-card__title"')
        ->and($nested)->toContain('<h3 class="ui-card__title"')->and($nested)->not->toContain('<h2')
        ->and($clamped)->toContain('<h6 class="ui-card__title"'); // never a level HTML does not have
});

it('D-133: a dialog can be described by the summary inside it as well as by its description line', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ui.confirm name="c" title="T" description="D" confirm-label="Go" target-form="f" described-by="the-summary"><p id="the-summary">S</p></x-ui.confirm>',
    ), ENT_QUOTES);

    expect($html)->toContain('aria-describedby="c-desc the-summary"');

    // No description line: the summary alone.
    $bare = html_entity_decode(Blade::render(
        '<x-ui.confirm name="c" title="T" confirm-label="Go" target-form="f" described-by="the-summary"><p id="the-summary">S</p></x-ui.confirm>',
    ), ENT_QUOTES);

    expect($bare)->toContain('aria-describedby="the-summary"');
});
