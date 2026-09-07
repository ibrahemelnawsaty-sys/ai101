<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Tooltip view model — the bubble opens on hover AND on focus (PRD §5.8).
 *
 * Whether the trigger is the icon or the slot is decided in the template,
 * because a slot does not exist until after the component is constructed.
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 18
 */
final class Tooltip extends UiComponent
{
    public string $bubbleId;

    public string $triggerLabel;

    public function __construct(
        public string $variant = 'top',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $text = null,
        public string $icon = 'i-info',
        public ?string $label = null,
    ) {
        $this->variant = self::oneOf($variant, ['top', 'bottom', 'start', 'end'], 'top');
        $this->bubbleId = 'tip-'.Str::random(6);
        $this->triggerLabel = $label ?? (string) __('ui.tooltip.more_info');
    }

    public function render(): View
    {
        return view('components.ui.tooltip');
    }
}
