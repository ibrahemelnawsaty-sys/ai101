<?php

declare(strict_types=1);

/**
 * A component must declare every prop its callers hand it.
 *
 * WHY THIS SUITE EXISTS
 * `<x-ui.empty-state>` was given `action-label` and `action-href` at SEVENTY
 * call sites across THIRTY-FOUR views. It declared neither. Blade does not fail
 * on an unknown attribute — it spills it onto the root element — so every empty
 * and error state on the platform rendered as
 *
 *     <div class="ui-empty" action-label="اعرض جدول البرنامج" action-href="/schedule">
 *
 * with no button at all. Article 17 asks an empty state for a drawing, a title,
 * an explanation AND a way forward; the way forward was an HTML attribute
 * nobody could click, on every screen, for the life of the project.
 *
 * Nothing failed, because nothing was wrong in any one file. This test compares
 * what the views pass with what the components accept.
 *
 * WHY THIS GATE IS TRUSTWORTHY AND THE FIELD-NAME ONE WAS NOT
 * A sibling idea — comparing form fields to FormRequest rules — was measured and
 * abandoned in D-42 because it produced 28 findings that were mostly false: those
 * rules are built in loops over traits and a static reader cannot see them. This
 * one was measured too, and after the fix it reports ZERO. A gate that cries
 * wolf costs more than no gate; a gate measured at zero is worth keeping.
 *
 * @see CONSTITUTION.md Article 17, Article 21 · D-45
 */

use Illuminate\Support\Facades\File;

/**
 * Attributes every HTML element may carry, or that Blade routes elsewhere.
 * `dir` is here on purpose: thirty views pass it to <x-ui.input> so a Latin
 * field reads left-to-right inside an RTL label, and it is meant to land on the
 * element (CLAUDE.md, RTL rules).
 */
const PASSTHROUGH_ATTRS = [
    'class', 'id', 'style', 'role', 'type', 'name', 'value', 'href', 'dir', 'lang',
    'disabled', 'required', 'placeholder', 'title', 'checked', 'readonly', 'rows',
    'cols', 'min', 'max', 'step', 'autocomplete', 'inputmode', 'pattern', 'accept',
    'maxlength', 'minlength', 'multiple', 'target', 'rel', 'tabindex', 'form',
    'method', 'action', 'for', 'colspan', 'scope', 'src', 'alt', 'width', 'height',
    'loading', 'decoding', 'autofocus', 'hidden',
];

/** @return array<string, list<string>> component => declared prop names */
function declaredUiProps(): array
{
    $props = [];

    foreach (File::files(app_path('View/Components/Ui')) as $file) {
        $source = (string) File::get($file->getPathname());

        $names = [];

        if (preg_match('/function __construct\((.*?)\)\s*\{/s', $source, $ctor) === 1) {
            preg_match_all('/\$(\w+)/', $ctor[1], $vars);
            $names = $vars[1];
        }

        $kebab = strtolower((string) preg_replace('/(?<!^)(?=[A-Z])/', '-', $file->getFilenameWithoutExtension()));
        $props[$kebab] = $names;
    }

    return $props;
}

function camelAttr(string $attribute): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $attribute))));
}

it('D-45: كل خاصّية تُمرَّر لمكوّن ui مُعلَنة فيه', function (): void {
    $declared = declaredUiProps();
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) File::get($file->getPathname());

        if (preg_match_all('/<x-ui\.([a-z0-9-]+)([^>]*?)\/?>/s', $source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($tags as $tag) {
            $component = $tag[1][0];

            if (! array_key_exists($component, $declared)) {
                continue;
            }

            $line = substr_count(substr($source, 0, (int) $tag[0][1]), "\n") + 1;

            preg_match_all('/(?:^|\s):?([a-zA-Z][a-zA-Z0-9-]*)\s*=/', $tag[2][0], $attrs);

            foreach ($attrs[1] as $attribute) {
                // Alpine, Livewire, data-*, aria-* and event bindings are meant
                // to reach the element.
                if (preg_match('/^(x-|v-|wire|data-|aria-|@)/', $attribute) === 1) {
                    continue;
                }

                if (in_array($attribute, PASSTHROUGH_ATTRS, true)) {
                    continue;
                }

                if (in_array(camelAttr($attribute), $declared[$component], true)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s:%d — <x-ui.%s> is given "%s", which it does not declare',
                    str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                    $line,
                    $component,
                    $attribute,
                );
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('D-45: الحالة الفارغة تعرض زرًّا حين يُمرَّر لها', function (): void {
    // The whole point of the props: an empty state must offer a way forward.
    $rendered = (string) view('components.ui.empty-state', [
        'variant' => 'default',
        'size' => 'md',
        'state' => 'default',
        'title' => 'CANARY-TITLE',
        'description' => 'CANARY-DESC',
        'icon' => null,
        'fallbackIcon' => 'i-file',
        'headingId' => 'canary-heading',
        'actionLabel' => 'CANARY-ACTION',
        'actionHref' => '/canary',
        'attributes' => new Illuminate\View\ComponentAttributeBag([]),
        'slot' => new Illuminate\Support\HtmlString(''),
    ])->render();

    expect($rendered)->toContain('CANARY-ACTION')
        ->and($rendered)->toContain('/canary')
        ->and($rendered)->toContain('ui-empty__actions')
        // The failure mode: the label landing as an attribute instead of a button.
        ->and($rendered)->not->toContain('action-label="CANARY-ACTION"');
});
