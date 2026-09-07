<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Switch view model (PRD §5.8).
 *
 * The class is SwitchControl rather than Switch because `switch` is a reserved
 * word in PHP and cannot name a class; the tag stays `<x-ui.switch>` through an
 * alias registered in AppServiceProvider.
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 5, 13, 18
 */
final class SwitchControl extends UiComponent
{
    public bool $isDisabled;

    public ?string $message;

    public string $fieldId;

    public ?string $errorId;

    public ?string $hintId;

    public string $describedBy;

    public bool $checked;

    public bool $withFalse;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $name = null,
        ?string $id = null,
        public ?string $label = null,
        public ?string $description = null,
        public ?string $hint = null,
        ?string $error = null,
        mixed $checked = false,
        public mixed $value = 1,
        public mixed $offValue = 0,
        mixed $withFalse = true,
    ) {
        $this->checked = (bool) $checked;
        $this->withFalse = (bool) $withFalse;
        $this->isDisabled = $state === 'disabled';
        $this->message = self::errorFor($name, $error);
        $this->fieldId = self::fieldId('w', $name, $id);

        $this->errorId = $this->message !== null ? $this->fieldId.'-error' : null;
        $this->hintId = $hint !== null ? $this->fieldId.'-hint' : null;
        $this->describedBy = self::describedBy($this->errorId, $this->hintId);
    }

    public function render(): View
    {
        return view('components.ui.switch');
    }
}
