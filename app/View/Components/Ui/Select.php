<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Select / listbox view model.
 *
 * PRD §5.8: the internal search appears once a list passes eight options, and
 * the listbox is fully keyboard-operable. The threshold lives here so no
 * template repeats it.
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 5, 13, 18
 */
final class Select extends UiComponent
{
    /** PRD §5.8 — beyond this many options the list gets its own search. */
    public const SEARCH_THRESHOLD = 8;

    public string $fieldId;

    public ?string $message;

    public bool $isLoading;

    public bool $isDisabled;

    public ?string $hintId;

    public ?string $errorId;

    public string $describedBy;

    /** @var list<array<string, mixed>>|null */
    public ?array $items;

    public bool $useListbox;

    public bool $withSearch;

    public string $current;

    public string $placeholderText;

    /**
     * What the button says BEFORE Alpine starts: the chosen option's label, or
     * the placeholder. Rendered by the server so a filter that is on never
     * flashes the word "All" for the moment the page is booting.
     */
    public string $initialLabel;

    /**
     * A filter's select: the listbox leads with an entry that clears it.
     * Only for a select whose empty value means "everything" — never for a
     * field the form requires.
     */
    public bool $clearable;

    /**
     * Whether the native select leads with the empty option. The template read
     * `$placeholder`, which the class never published, so the native branch
     * was a 500 for every caller (D-78). A required select always keeps it: a
     * single-value select marked required must start empty, or the browser
     * preselects the first real option and `required` can never fire.
     */
    public bool $withPlaceholder;

    public string $listId;

    public bool $required;

    /**
     * @param  array<int, array<string, mixed>>|null  $options
     */
    public function __construct(
        public string $variant = 'native',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $name = null,
        ?string $id = null,
        public ?string $label = null,
        public ?string $hint = null,
        ?string $error = null,
        mixed $options = null,
        mixed $value = null,
        mixed $placeholder = null,
        mixed $required = false,
        mixed $searchable = null,
        mixed $clearable = false,
    ) {
        $this->required = (bool) $required;
        $this->fieldId = self::fieldId('s', $name, $id);
        $this->message = self::errorFor($name, $error);

        $this->isLoading = $state === 'loading';
        $this->isDisabled = $state === 'disabled';

        $this->hintId = $hint !== null ? $this->fieldId.'-hint' : null;
        $this->errorId = $this->message !== null ? $this->fieldId.'-error' : null;
        $this->describedBy = self::describedBy($this->errorId, $this->hintId);

        $this->items = is_array($options) ? array_values($options) : null;
        $this->clearable = (bool) $clearable;
        $this->useListbox = $this->items !== null || $variant === 'listbox';
        $this->withSearch = $searchable === null
            ? ($this->items !== null && count($this->items) > self::SEARCH_THRESHOLD)
            : (bool) $searchable;

        $this->current = (string) ($value ?? ($name !== null && $name !== '' ? old($name, '') : ''));
        // mixed, not ?string: `:placeholder="false"` must stay false rather
        // than be coerced to an empty string and lose its meaning.
        $this->withPlaceholder = $placeholder !== false || $this->required;
        $this->placeholderText = is_string($placeholder) && $placeholder !== ''
            ? $placeholder
            : (string) ($this->clearable ? __('ui.select.all') : __('ui.select.placeholder'));

        // A FILTER must be able to go back to "no filter": a list of only real
        // values has no way to un-choose one, and the person is left with a
        // narrowed list and no control that widens it (D-136, review of 2-C).
        // The leading entry posts an empty value, which every list screen reads
        // as "no filter" (ListFilter).
        if ($this->clearable && $this->items !== null) {
            array_unshift($this->items, ['value' => '', 'label' => $this->placeholderText]);
        }

        $this->initialLabel = $this->placeholderText;

        foreach ($this->items ?? [] as $item) {
            if ((string) ($item['value'] ?? '') === $this->current && is_string($item['label'] ?? null) && $this->current !== '') {
                $this->initialLabel = $item['label'];

                break;
            }
        }

        $this->listId = $this->fieldId.'-list';
    }

    public function render(): View
    {
        return view('components.ui.select');
    }
}
