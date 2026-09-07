<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Empty-state view model (PRD §5.8, CONSTITUTION art. 17).
 *
 * The illustration follows the variant, so a screen that only says "error" or
 * "locked" still gets the right drawing without repeating the mapping.
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 17
 */
final class EmptyState extends UiComponent
{
    public string $fallbackIcon;

    public string $headingId;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $title = null,
        public ?string $description = null,
        public ?string $icon = null,
    ) {
        $this->variant = self::oneOf($variant, ['default', 'error', 'success', 'locked'], 'default');
        $this->size = self::oneOf($size, ['sm', 'md', 'lg'], 'md');

        $this->fallbackIcon = $icon ?? match ($this->variant) {
            'error' => 'i-warn',
            'success' => 'i-check',
            'locked' => 'i-lock',
            default => 'i-folder',
        };

        $this->headingId = 'empty-'.Str::random(6);
    }

    public function render(): View
    {
        return view('components.ui.empty-state');
    }
}
