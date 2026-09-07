<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Vertical timeline view model — PRD §5.8 fixes the three states: completed,
 * current, locked. Each carries an icon and a word as well as a colour, because
 * colour alone never carries meaning (CONSTITUTION art. 18).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 16, 18
 */
final class Timeline extends UiComponent
{
    /** @var list<array<string, mixed>> */
    public array $steps;

    public int $total;

    public int $done;

    public float $fill;

    public string $listLabel;

    /** @var array<string, string> */
    public array $statusText;

    /** @var array<string, string> */
    public array $statusIcon;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        mixed $items = [],
        mixed $percent = null,
        ?string $label = null,
    ) {
        $this->steps = is_array($items) ? array_values($items) : [];
        $this->total = count($this->steps);

        $done = 0;

        foreach ($this->steps as $step) {
            if (($step['status'] ?? 'locked') === 'completed') {
                $done++;
            }
        }

        $this->done = $done;

        $this->fill = $percent !== null
            ? max(0.0, min(100.0, (float) $percent))
            : ($this->total > 0 ? round(($done / $this->total) * 100, 2) : 0.0);

        $this->listLabel = $label ?? (string) __('ui.timeline.label');

        $this->statusText = [
            'completed' => (string) __('ui.timeline.completed'),
            'current' => (string) __('ui.timeline.current'),
            'locked' => (string) __('ui.timeline.locked'),
        ];

        $this->statusIcon = [
            'completed' => 'i-check',
            'current' => 'i-spark',
            'locked' => 'i-lock',
        ];
    }

    public function render(): View
    {
        return view('components.ui.timeline');
    }
}
