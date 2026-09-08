<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Services\Time\Clock;
use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Live countdown view model (PRD §5.8).
 *
 * Every instant here is built by Clock and nothing else: no now(), no
 * new DateTime, no browser clock (CONSTITUTION art. 11, art. 13 rule 2). The
 * server's instant travels to the page beside the target so the browser can
 * only measure the difference, never decide the moment (BR-07).
 *
 * A string target is read as a Riyadh wall-clock value and converted to UTC.
 *
 * @see PRD §5.8, §9.9.8 · BR-07 · CONSTITUTION.md Articles 11, 13, 18
 */
final class CountdownTimer extends UiComponent
{
    public ?string $targetIso;

    public string $serverIso;

    public int $urgentMs;

    public bool $isCompact;

    public bool $showDays;

    /** @var list<array{key: string, label: string}> */
    public array $cells;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        mixed $target = null,
        public ?string $label = null,
        mixed $urgentBelow = 0,
        mixed $showDays = true,
    ) {
        $targetAt = $target instanceof \DateTimeInterface
            ? Clock::toUtc($target)
            : ($target !== null ? Clock::fromRiyadh((string) $target) : null);

        $this->targetIso = $targetAt?->toIso8601String();
        $this->serverIso = Clock::now()->toIso8601String();

        $this->urgentMs = max(0, (int) $urgentBelow) * 1000;
        $this->isCompact = $variant === 'compact';

        $cells = [
            ['key' => 'days', 'label' => (string) __('app.time.days_label')],
            ['key' => 'hours', 'label' => (string) __('app.time.hours_label')],
            ['key' => 'minutes', 'label' => (string) __('app.time.minutes_label')],
            ['key' => 'seconds', 'label' => (string) __('app.time.seconds_label')],
        ];

        $this->showDays = (bool) $showDays;

        if (! $this->showDays) {
            array_shift($cells);
        }

        $this->cells = $cells;
    }

    public function render(): View
    {
        return view('components.ui.countdown-timer');
    }
}
