<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Radio group view model (PRD §5.8).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 5, 13, 18
 */
final class Radio extends UiComponent
{
    public bool $isDisabled;

    public ?string $message;

    public string $baseId;

    /** @var list<array<string, mixed>> */
    public array $items;

    public string $current;

    public ?string $errorId;

    public ?string $hintId;

    public string $describedBy;

    public bool $required;

    /**
     * `$options` stays `mixed`: Blade passes a template attribute through
     * untouched, so an option list written without a colon arrives as a string.
     * rows() is the boundary that turns it into the shape the group renders.
     */
    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $name = null,
        ?string $id = null,
        public ?string $legend = null,
        public ?string $hint = null,
        ?string $error = null,
        mixed $options = [],
        mixed $value = null,
        mixed $required = false,
    ) {
        $this->required = (bool) $required;
        $this->isDisabled = $state === 'disabled';
        $this->message = self::errorFor($name, $error);
        $this->baseId = self::fieldId('r', $name, $id);

        $this->items = self::rows($options);
        $this->current = (string) ($value ?? ($name !== null && $name !== '' ? old($name, '') : ''));

        $this->errorId = $this->message !== null ? $this->baseId.'-error' : null;
        $this->hintId = $hint !== null ? $this->baseId.'-hint' : null;
        $this->describedBy = self::describedBy($this->errorId, $this->hintId);
    }

    public function optionId(int $index): string
    {
        return $this->baseId.'-'.$index;
    }

    /**
     * @param  array<string, mixed>  $option
     */
    public function optionValue(array $option): string
    {
        return (string) ($option['value'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $option
     */
    public function optionDisabled(array $option): bool
    {
        return $this->isDisabled || ! empty($option['disabled']);
    }

    public function render(): View
    {
        return view('components.ui.radio');
    }
}
