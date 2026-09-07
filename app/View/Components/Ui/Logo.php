<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Logo view model.
 *
 * The two path strings are the wordmark and the mark extracted from ATHAR.pdf
 * and must match it exactly (CONSTITUTION art. 14); they live here rather than
 * in the template so the template holds no data at all.
 *
 * @see CONSTITUTION.md Articles 13, 14
 */
final class Logo extends UiComponent
{
    /** viewBox of the small mark used at icon sizes. */
    public const MARK_VIEWBOX = '0 0 36.440 99.820';

    /** viewBox of the full wordmark. */
    public const WORDMARK_VIEWBOX = '0 0 242.186 102.814';

    private const MARK_PATH = 'M27.590 99.820L9.580 99.820L9.580 31.870L27.590 31.870Z M36.440 6.430L30.000 0.000L18.220 11.780L6.440 0.000L0.000 6.430L18.220 24.670Z';

    private const WORDMARK_PATH = 'M192.007 94.192L192.007 76.592L-0.003 35.672L-0.003 102.812L18.007 102.812L18.007 57.782Z M233.337 102.812L215.327 102.812L215.327 34.862L233.337 34.862Z M146.957 25.752L137.867 34.842L121.227 18.202L104.577 34.842L95.487 25.752L121.227 0.002Z M242.187 9.422L235.747 2.992L223.967 14.772L212.187 2.992L205.747 9.422L223.967 27.662Z';

    public string $tag;

    public string $alt;

    public string $titleId;

    public string $viewBox;

    public string $path;

    public function __construct(
        public string $variant = 'wordmark',
        public string $size = 'md',
        public string $state = 'default',
        public string $tone = 'brand',
        public ?string $href = null,
        public ?string $tagline = null,
    ) {
        $this->variant = $variant === 'mark' ? 'mark' : 'wordmark';
        $this->size = self::oneOf($size, ['sm', 'md', 'lg', 'xl'], 'md');
        $this->tone = self::oneOf($tone, ['brand', 'light', 'ink', 'teal', 'current'], 'brand');

        $this->tag = $href !== null ? 'a' : 'span';
        $this->alt = (string) __('ui.logo.alt');
        $this->titleId = 'logo-'.Str::random(6);

        $isMark = $this->variant === 'mark';
        $this->viewBox = $isMark ? self::MARK_VIEWBOX : self::WORDMARK_VIEWBOX;
        $this->path = $isMark ? self::MARK_PATH : self::WORDMARK_PATH;
    }

    public function render(): View
    {
        return view('components.ui.logo');
    }
}
