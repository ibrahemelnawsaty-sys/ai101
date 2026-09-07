<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Card container view model (PRD §5.8).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 17
 */
final class Card extends UiComponent
{
    public string $tag;

    public bool $isFlush;

    public ?string $headingId;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $icon = null,
        public ?string $href = null,
        mixed $flush = false,
        public string $as = 'section',
    ) {
        $this->variant = self::oneOf($variant, ['default', 'flat', 'interactive', 'brand', 'raised'], 'default');
        $this->size = self::oneOf($size, ['sm', 'md', 'lg'], 'md');

        $this->tag = $href !== null ? 'a' : $as;
        $this->isFlush = filter_var($flush, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $flush;
        $this->headingId = $title !== null ? 'card-'.Str::random(6) : null;
    }

    public function render(): View
    {
        return view('components.ui.card');
    }
}
