<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Pagination view model — PRD §5.8 asks for page navigation that also states
 * the total; PRD §5.5 makes paging mandatory past fifty rows.
 *
 * The page window (first · … · neighbours · … · last) is arithmetic, so it is
 * computed here rather than in a loop inside the template (Article 13 item 13).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 16, 19
 */
final class Pagination extends UiComponent
{
    public mixed $p;

    public bool $hasPages;

    public bool $isLengthAware;

    public int $current;

    public int $last;

    public int $side;

    /** @var list<int|null> null marks the gap that renders as an ellipsis */
    public array $window = [];

    public ?int $total;

    public ?int $firstItem;

    public ?int $lastItem;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        mixed $paginator = null,
        mixed $onEachSide = 1,
    ) {
        $this->p = $paginator;
        $this->hasPages = is_object($paginator) && method_exists($paginator, 'hasPages') && $paginator->hasPages();
        $this->isLengthAware = is_object($paginator) && method_exists($paginator, 'lastPage');

        $this->current = is_object($paginator) && method_exists($paginator, 'currentPage')
            ? (int) $paginator->currentPage()
            : 1;
        $this->last = $this->isLengthAware ? (int) $paginator->lastPage() : $this->current;
        $this->side = max(0, (int) $onEachSide);

        if ($this->isLengthAware && $variant !== 'simple') {
            $this->window = $this->buildWindow();
        }

        $this->total = $this->isLengthAware && method_exists($paginator, 'total') ? (int) $paginator->total() : null;
        $this->firstItem = is_object($paginator) && method_exists($paginator, 'firstItem') ? $paginator->firstItem() : null;
        $this->lastItem = is_object($paginator) && method_exists($paginator, 'lastItem') ? $paginator->lastItem() : null;
    }

    /**
     * @return list<int|null>
     */
    private function buildWindow(): array
    {
        $from = max(1, $this->current - $this->side);
        $to = min($this->last, $this->current + $this->side);

        $window = [1];

        if ($from > 2) {
            $window[] = null;
        }

        for ($i = $from; $i <= $to; $i++) {
            if ($i !== 1 && $i !== $this->last) {
                $window[] = $i;
            }
        }

        if ($to < $this->last - 1) {
            $window[] = null;
        }

        if ($this->last > 1) {
            $window[] = $this->last;
        }

        return $window;
    }

    public function render(): View
    {
        return view('components.ui.pagination');
    }
}
