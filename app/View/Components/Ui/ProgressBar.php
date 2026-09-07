<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Progress bar view model (PRD §5.8).
 *
 * The bar fills from the RIGHT: it is drawn with logical properties in
 * components.css, so the percentage here is a plain number and the direction is
 * the stylesheet's business (CONSTITUTION art. 16).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 16, 18
 */
final class ProgressBar extends UiComponent
{
    public float $ceiling;

    public float $percent;

    public string $printed;

    public bool $isIndeterminate;

    public string $barId;

    public ?string $labelId;

    public ?float $mark;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        mixed $value = 0,
        mixed $max = 100,
        public ?string $label = null,
        mixed $threshold = null,
        public ?string $thresholdLabel = null,
        public bool $showValue = true,
    ) {
        $this->variant = self::oneOf($variant, ['default', 'brand', 'success', 'warning', 'error'], 'default');
        $this->size = self::oneOf($size, ['sm', 'md', 'lg'], 'md');

        $ceiling = is_numeric($max) ? (float) $max : 100.0;
        $this->ceiling = $ceiling > 0.0 ? $ceiling : 100.0;

        $this->percent = max(0.0, min(100.0, ((float) $value / $this->ceiling) * 100.0));

        $printed = rtrim(rtrim(number_format($this->percent, 1, '.', ''), '0'), '.');
        $this->printed = $printed === '' ? '0' : $printed;

        $this->isIndeterminate = $state === 'indeterminate';
        $this->barId = 'pb-'.Str::random(6);
        $this->labelId = $label !== null ? $this->barId.'-label' : null;

        $this->mark = $threshold === null
            ? null
            : max(0.0, min(100.0, ((float) $threshold / $this->ceiling) * 100.0));
    }

    public function render(): View
    {
        return view('components.ui.progress-bar');
    }
}
