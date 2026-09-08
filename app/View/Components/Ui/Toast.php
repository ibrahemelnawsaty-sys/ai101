<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;

/**
 * Toast stack view model (PRD §5.8).
 *
 * The messages handed in are flattened into the shape the Alpine store expects,
 * so the template hands over a value and never builds one.
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 13, 18
 */
final class Toast extends UiComponent
{
    /** One icon per status, so colour is never the only carrier of meaning (art. 18). */
    public const ICONS = [
        'success' => 'i-check',
        'warning' => 'i-warn',
        'error' => 'i-warn',
        'info' => 'i-info',
    ];

    /** @var list<array{id: string, variant: string, title: string, text: string, leaving: bool, sticky: bool}> */
    public array $initial = [];

    /** @var array<string, string> */
    public array $iconFor = self::ICONS;

    public int $duration;

    /**
     * `$messages` and `$duration` stay `mixed`: Blade passes a template
     * attribute through untouched, so `duration="3000"` arrives as a string and
     * a flashed message bag can hold anything. rows() is the boundary that
     * drops what is not a message instead of rendering it half-formed.
     */
    public function __construct(
        public string $variant = 'info',
        public string $size = 'md',
        public string $state = 'default',
        mixed $messages = [],
        mixed $duration = 5000,
    ) {
        $this->duration = (int) $duration;

        foreach (self::rows($messages) as $index => $message) {
            $this->initial[] = [
                'id' => 's'.$index,
                'variant' => (string) ($message['variant'] ?? $variant),
                'title' => (string) ($message['title'] ?? ''),
                'text' => (string) ($message['text'] ?? ''),
                'leaving' => false,
                'sticky' => (bool) ($message['sticky'] ?? false),
            ];
        }
    }

    public function render(): View
    {
        return view('components.ui.toast');
    }
}
