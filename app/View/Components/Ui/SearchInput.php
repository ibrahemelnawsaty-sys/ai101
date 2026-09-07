<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Search field view model — PRD §5.8 asks for a 300ms debounce and a clear
 * button; both are the template's and the stylesheet's business, the query it
 * starts from is this class's.
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 18
 */
final class SearchInput extends UiComponent
{
    public string $fieldId;

    public string $current;

    public bool $isDisabled;

    public string $fieldLabel;

    public string $statusId;

    public bool $autoSubmit;

    public int $delay;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public string $name = 'q',
        ?string $id = null,
        public ?string $label = null,
        public ?string $placeholder = null,
        mixed $value = null,
        mixed $autoSubmit = true,
        mixed $delay = 300,
        public ?string $status = null,
    ) {
        $this->autoSubmit = (bool) $autoSubmit;
        $this->delay = (int) $delay;
        $this->fieldId = $id ?? 'q-'.Str::slug(str_replace(['[', ']', '.'], '-', $name));
        $this->current = (string) ($value ?? request()->query($name, ''));
        $this->isDisabled = $state === 'disabled';
        $this->fieldLabel = $label ?? (string) __('ui.search.label');
        $this->statusId = $this->fieldId.'-status';
    }

    public function render(): View
    {
        return view('components.ui.search-input');
    }
}
