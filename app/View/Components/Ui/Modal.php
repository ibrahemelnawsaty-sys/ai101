<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Dialog view model — focus is trapped inside and returns to the trigger
 * (PRD §5.8, CONSTITUTION art. 18).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 18
 */
final class Modal extends UiComponent
{
    public string $uid;

    public string $titleId;

    public ?string $descId;

    public bool $open;

    public bool $dismissible;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $name = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $icon = null,
        mixed $open = false,
        mixed $dismissible = true,
    ) {
        $this->open = (bool) $open;
        $this->dismissible = (bool) $dismissible;
        $this->variant = self::oneOf($variant, ['default', 'danger', 'warning', 'success'], 'default');
        $this->size = self::oneOf($size, ['sm', 'md', 'lg'], 'md');

        $this->uid = $name ?? 'modal-'.Str::random(6);
        $this->titleId = $this->uid.'-title';
        $this->descId = $description !== null ? $this->uid.'-desc' : null;
    }

    public function render(): View
    {
        return view('components.ui.modal');
    }
}
