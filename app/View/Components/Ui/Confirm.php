<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Confirmation dialog view model — the ONE way the platform asks "are you
 * sure?" before an action that cannot be taken back or that reaches other
 * people (D-127).
 *
 * Before it, three patterns coexisted: a card drawn at the foot of the page
 * after a full reload, a button that simply did the thing, and a danger button
 * in every row. This component replaces them with a dialog whose title says
 * what is about to happen, whose body says who is affected and what cannot be
 * undone, and whose two buttons are named for the action — never a bare
 * "OK" and "Cancel" (Article 15).
 *
 * WHAT IT IS NOT: a permission boundary. The form it wraps is still validated by
 * its FormRequest and authorised by its Policy on the server (Article 5); this
 * only makes a human stop for a second before sending it.
 *
 * Two ways in:
 *  · a trigger the component draws itself (`trigger-label`), opening the dialog
 *    client-side; and
 *  · `open` with a `close-href`, for a confirmation the server renders already
 *    open from a query string — Cancel is then a plain link that clears it, so
 *    the step still works with scripting off.
 *  · `target-form`, for a form that already lives on the page and was filled
 *    in there (D-133): the dialog draws no form of its own, shows what its slot
 *    says, and its confirming button submits THAT form.
 *
 * @see D-127 · PRD §5.8, §5.9 · CONSTITUTION Articles 5, 13, 15, 17, 18
 */
final class Confirm extends UiComponent
{
    /** The verbs a form may be spoofed with; POST needs no spoof. */
    private const SPOOFED = ['PUT', 'PATCH', 'DELETE'];

    public string $uid;

    public string $formId;

    public ?string $spoof;

    public bool $open;

    public bool $iconOnlyTrigger;

    public bool $reasonRequired;

    /** The id of the form the confirming button submits: this dialog's own, or one elsewhere on the page. */
    public string $submitsForm;

    public function __construct(
        public ?string $name = null,
        public string $action = '',
        public string $method = 'POST',
        public string $title = '',
        public ?string $description = null,
        public string $confirmLabel = '',
        public ?string $cancelLabel = null,
        public string $variant = 'danger',
        public ?string $icon = null,
        public ?string $confirmIcon = null,
        public ?string $triggerLabel = null,
        public ?string $triggerIcon = null,
        public string $triggerVariant = 'danger-ghost',
        public string $triggerSize = 'sm',
        mixed $triggerIconOnly = false,
        public ?string $reasonName = null,
        public ?string $reasonLabel = null,
        public ?string $reasonHint = null,
        public ?string $reasonValue = null,
        mixed $reasonRequired = false,
        mixed $open = false,
        public ?string $closeHref = null,
        public ?string $targetForm = null,
        public ?string $describedBy = null,
    ) {
        $this->variant = self::oneOf($variant, ['danger', 'primary'], 'danger');
        $this->triggerVariant = self::oneOf(
            $triggerVariant,
            ['primary', 'secondary', 'ghost', 'danger', 'danger-ghost'],
            'danger-ghost',
        );
        $this->triggerSize = self::oneOf($triggerSize, ['sm', 'md', 'lg'], 'sm');

        $verb = strtoupper($method);
        $this->spoof = in_array($verb, self::SPOOFED, true) ? $verb : null;

        $this->open = (bool) $open;
        $this->closeHref = self::localPath($closeHref);
        $this->iconOnlyTrigger = (bool) $triggerIconOnly;
        $this->reasonRequired = (bool) $reasonRequired;

        // The id lands inside an Alpine expression in the template, and Blade does
        // not compile `@js()` inside a component tag's attribute (D-116) — so it is
        // reduced here to characters that need no quoting at all.
        $this->uid = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name ?? ''), '-');
        $this->uid = $this->uid !== '' ? $this->uid : 'confirm-'.Str::random(6);
        $this->formId = $this->uid.'-form';

        // D-133 — a dialog may confirm a form that already lives on the page (the
        // role-change form, whose fields the person has filled). Reduced to id characters.
        $external = $targetForm !== null ? trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $targetForm), '-') : '';
        $this->targetForm = $external !== '' ? $external : null;
        $this->submitsForm = $this->targetForm ?? $this->formId;

        // The title is the dialog's accessible name: never leave it empty.
        $this->title = $title !== '' ? $title : $confirmLabel;

        $this->icon ??= $this->variant === 'danger' ? 'warn' : 'info';
        $this->cancelLabel ??= __('ui.confirm.cancel');
        $this->reasonLabel ??= $reasonName !== null ? __('ui.confirm.reason') : null;
    }

    public function render(): View
    {
        return view('components.ui.confirm');
    }
}
