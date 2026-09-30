<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Checkbox and checkbox-group view model.
 *
 * PRD §5.8 fixes the touch target at 44px in either axis; that is the
 * stylesheet's job. This class only answers what the template needs to know
 * about each option, so the loop over the options carries no PHP island of its
 * own (Article 13 item 13).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 5, 13, 18
 */
final class Checkbox extends UiComponent
{
    public bool $isDisabled;

    public ?string $message;

    public string $baseId;

    /** @var list<array<string, mixed>>|null */
    public ?array $items;

    /** @var list<string> */
    public array $selected;

    public ?string $errorId;

    public ?string $hintId;

    public string $describedBy;

    public bool $checked;

    public bool $indeterminate;

    public bool $required;

    public bool $withFalse;

    /** The label is for assistive technology only — drawn off-screen, never removed (D-143). */
    public bool $labelHidden;

    /** What the hidden companion posts — see SwitchControl::$hiddenValue (D-78). */
    public mixed $hiddenValue;

    /**
     * @param  array<int, array<string, mixed>>|null  $options
     */
    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $name = null,
        ?string $id = null,
        public ?string $label = null,
        public ?string $description = null,
        public ?string $legend = null,
        public ?string $hint = null,
        ?string $error = null,
        mixed $options = null,
        public mixed $value = null,
        mixed $checked = false,
        mixed $indeterminate = false,
        mixed $required = false,
        mixed $withFalse = true,
        mixed $disabled = false,
        mixed $labelHidden = false,
    ) {
        $this->checked = (bool) $checked;
        $this->indeterminate = (bool) $indeterminate;
        $this->required = (bool) $required;
        $this->labelHidden = (bool) $labelHidden;
        $this->isDisabled = (bool) $disabled || $state === 'disabled';

        // A list of boxes (`name="user_id[]"`) has no "false" to post: the companion
        // would add a literal "0" to the very array the server validates as ids, so
        // every bulk form built on one was refused (D-143). The one companion a list
        // still needs is the DISABLED, ticked box — a disabled control posts nothing,
        // and what it carries is its own value, never a "0".
        $listed = str_ends_with((string) $name, '[]');
        $this->withFalse = (bool) $withFalse && (! $listed || ($this->isDisabled && $this->checked));
        $this->hiddenValue = $this->isDisabled && $this->checked ? ($value ?? 1) : 0;
        $this->message = self::errorFor($name, $error);
        $this->baseId = self::fieldId('c', $name, $id);

        // Sixty boxes named `user_id[]` all became `c-user-id`, and a <label for> then
        // points at the FIRST of them: the wide hit area beside any row toggled the top
        // row's box, and a screen reader read one name for all of them (D-143).
        if ($listed && ($id === null || $id === '') && $options === null) {
            $this->baseId .= '-'.self::listedSuffix($value);
        }

        $this->items = is_array($options) ? array_values($options) : null;

        // array_values keeps the promise the property makes: a checked-value bag
        // arriving keyed (old('roles') from a keyed input) would otherwise stay
        // keyed and no longer be the list the template iterates.
        $this->selected = is_array($value)
            ? array_values(array_map(static fn (mixed $v): string => (string) $v, $value))
            : ($value === null ? [] : [(string) $value]);

        $this->errorId = $this->message !== null ? $this->baseId.'-error' : null;
        $this->hintId = $hint !== null ? $this->baseId.'-hint' : null;
        $this->describedBy = self::describedBy($this->errorId, $this->hintId);
    }

    /**
     * The part of a listed box's id that tells it from its siblings.
     *
     * A value that survives `Str::slug` unchanged and is short (a UUID does) is
     * used as it is, so the id stays readable. Any other value — different only
     * in case or punctuation, an emoji, Arabic, three hundred characters, none at
     * all — gets a short hash of the RAW value, so two different values can never
     * share an id (a slug alone maps 'AB', 'ab' and 'a.b' to one).
     */
    private static function listedSuffix(mixed $value): string
    {
        if (! is_scalar($value)) {
            return Str::random(6);
        }

        $raw = (string) $value;
        $slug = Str::slug($raw);

        if ($slug !== '' && $slug === $raw && strlen($slug) <= 64) {
            return $slug;
        }

        return ($slug !== '' ? substr($slug, 0, 32).'-' : '').substr(hash('sha256', $raw), 0, 8);
    }

    public function optionId(int $index): string
    {
        return $this->baseId.'-'.$index;
    }

    /**
     * @param  array<string, mixed>  $option
     */
    public function optionDisabled(array $option): bool
    {
        return $this->isDisabled || ! empty($option['disabled']);
    }

    /**
     * @param  array<string, mixed>  $option
     */
    public function optionChecked(array $option): bool
    {
        return in_array((string) ($option['value'] ?? ''), $this->selected, true);
    }

    public function render(): View
    {
        return view('components.ui.checkbox');
    }
}
