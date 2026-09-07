<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Text, textarea and password field view model (PRD §5.8).
 *
 * The validation message is the server's answer, read off the shared error bag
 * and never decided here (CONSTITUTION art. 5).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 5, 13, 16, 18
 */
final class Input extends UiComponent
{
    public string $fieldId;

    public ?string $message;

    public bool $isLoading;

    public bool $isDisabled;

    public bool $isReadonly;

    public bool $isSuccess;

    public ?string $hintId;

    public ?string $errorId;

    public bool $counterOn;

    public ?string $counterId;

    public string $describedBy;

    public bool $isTextarea;

    public bool $isPassword;

    public string $current;

    public int $used;

    public string $controlClass;

    public string $wrapClass;

    public bool $required;

    public bool $ltr;

    public int $rows;

    public ?int $maxlength;

    public bool $counter;

    public function __construct(
        public string $variant = 'text',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $name = null,
        ?string $id = null,
        public string $type = 'text',
        public ?string $label = null,
        public ?string $hint = null,
        ?string $error = null,
        mixed $value = null,
        public ?string $placeholder = null,
        mixed $required = false,
        mixed $ltr = false,
        public ?string $icon = null,
        mixed $rows = 4,
        mixed $maxlength = null,
        mixed $counter = false,
    ) {
        $this->required = (bool) $required;
        $this->ltr = (bool) $ltr;
        $this->rows = (int) $rows;
        $this->maxlength = $maxlength === null ? null : (int) $maxlength;
        $this->counter = (bool) $counter;
        $this->fieldId = self::fieldId('f', $name, $id);
        $this->message = self::errorFor($name, $error);

        $this->isLoading = $state === 'loading';
        $this->isDisabled = $state === 'disabled';
        $this->isReadonly = $state === 'readonly';
        $this->isSuccess = $state === 'success' && $this->message === null;

        $this->hintId = $hint !== null ? $this->fieldId.'-hint' : null;
        $this->errorId = $this->message !== null ? $this->fieldId.'-error' : null;
        $this->counterOn = $this->counter && $this->maxlength !== null;
        $this->counterId = $this->counterOn ? $this->fieldId.'-counter' : null;

        $this->describedBy = self::describedBy($this->errorId, $this->hintId, $this->counterId);

        $this->isTextarea = $variant === 'textarea';
        $this->isPassword = ! $this->isTextarea && $type === 'password';

        $this->current = (string) ($value ?? ($name !== null && $name !== '' ? old($name, '') : ''));
        $this->used = mb_strlen($this->current);

        $sizeClass = in_array($size, ['sm', 'lg'], true) ? ' ui-input--'.$size : '';

        $this->controlClass = 'ui-input'
            .$sizeClass
            .($this->isTextarea ? ' ui-input--textarea' : '')
            .($this->ltr ? ' ui-input--ltr' : '')
            .($this->message !== null ? ' ui-input--error' : '')
            .($this->isSuccess ? ' ui-input--success' : '');

        $this->wrapClass = 'ui-input-wrap'
            .($icon !== null && $icon !== '' ? ' ui-input-wrap--icon-start' : '')
            .($this->isPassword ? ' ui-input-wrap--action-end' : '');
    }

    public function render(): View
    {
        return view('components.ui.input');
    }
}
