<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Side panel view model — slides in from the right in RTL (PRD §5.8).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 16, 18
 */
final class Drawer extends UiComponent
{
    public string $uid;

    public string $titleId;

    public bool $open;

    public bool $dismissible;

    public function __construct(
        public string $variant = 'end',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $name = null,
        public ?string $title = null,
        mixed $open = false,
        mixed $dismissible = true,
    ) {
        $this->open = (bool) $open;
        $this->dismissible = (bool) $dismissible;
        $this->size = self::oneOf($size, ['sm', 'md', 'lg'], 'md');
        $this->uid = $name ?? 'drawer-'.Str::random(6);
        $this->titleId = $this->uid.'-title';
    }

    public function render(): View
    {
        return view('components.ui.drawer');
    }
}
