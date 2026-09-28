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
            reason-name="reason" :reason-required="true" />'
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
            confirm-label="Revoke" :open="true" close-href="/admin/certificates" />'
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
        ->and($js)->toContain('announced')
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
