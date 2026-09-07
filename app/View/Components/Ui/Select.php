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
        ?string $placeholder = null,
        mixed $required = false,
        mixed $searchable = null,
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
        $this->useListbox = $this->items !== null || $variant === 'listbox';
        $this->withSearch = $searchable === null
            ? ($this->items !== null && count($this->items) > self::SEARCH_THRESHOLD)
            : (bool) $searchable;

        $this->current = (string) ($value ?? ($name !== null && $name !== '' ? old($name, '') : ''));
        $this->placeholderText = $placeholder ?? (string) __('ui.select.placeholder');

        $this->listId = $this->fieldId.'-list';
    }

    public function render(): View
    {
        return view('components.ui.select');
    }
}
