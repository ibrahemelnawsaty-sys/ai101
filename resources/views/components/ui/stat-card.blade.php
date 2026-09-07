{{--
    StatCard

    A big number, a label, an icon and an optional change indicator.

    Every number shown here was computed on the server — an attendance rate, a
    score out of 100, a count of due assignments. The card formats; it never
    calculates and never rounds a rule into existence (Article 5, Article 6).

    The change indicator carries an arrow AND a sign, so "up" is never conveyed
    by green alone (Article 18). The arrows are drawn vertically, so they need no
    RTL mirroring.

    Numerals stay Latin and tabular (Article 15).

    @see PRD §5.8, §5.9, §9.5.3 · CONSTITUTION Articles 5, 6, 15, 16, 17, 18

    Props
      variant   default | brand | teal | success | warning | error | plain
      size      sm | md | lg
      state     default | loading | empty | error
      value     the figure itself, already formatted by the caller
      unit      a suffix printed smaller next to the value (%, / 100)
      label     what the figure measures
      icon      sprite id for the leading badge
      delta     the change, e.g. "+3" — printed as given
      trend     up | down | flat
      href      makes the whole card a link

    Slots
      foot      extra content under the label (a progress bar, a hint)
--}}
@props([
    'variant' => 'default',
    'size' => 'md',
    'state' => 'default',
    'value' => null,
    'unit' => null,
    'label' => null,
    'icon' => null,
    'delta' => null,
    'trend' => 'flat',
    'href' => null,
])

@php
    $allowed = ['default', 'brand', 'teal', 'success', 'warning', 'error', 'plain'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'default';
    $trend = in_array($trend, ['up', 'down', 'flat'], true) ? $trend : 'flat';

    $tag = $href !== null ? 'a' : 'div';

    $trendIcon = match ($trend) {
        'up' => 'i-chevup',
        'down' => 'i-chevdown',
        default => 'i-info',
    };
@endphp

@if ($state === 'loading')
    <div {{ $attributes->class(['ui-stat']) }} role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        <div class="ui-stat__body" aria-hidden="true">
            <span class="ui-sk ui-sk-stat__value"></span>
            <span class="ui-sk ui-sk-line"></span>
        </div>
    </div>

@elseif ($state === 'empty' || $state === 'error')
    <div {{ $attributes->class(['ui-stat']) }} @if ($state === 'error') role="alert" @endif>
        <div class="ui-stat__body">
            <x-ui.empty-state
                size="sm"
                :variant="$state === 'error' ? 'error' : 'default'"
                :icon="$state === 'error' ? 'i-warn' : ($icon ?? 'i-chart')"
                :title="$label"
                :description="$state === 'error' ? __('app.states.error_body') : __('app.states.empty_body')"
            />
        </div>
    </div>

@else
    <{{ $tag }}
        @if ($href !== null) href="{{ $href }}" @endif
        {{ $attributes->class([
            'ui-stat',
            'ui-stat--' . $variant => $variant !== 'default',
            'ui-stat--' . $size => $size !== 'md',
        ]) }}
    >
        @if ($icon)
            <span class="ui-stat__icon" aria-hidden="true">
                <svg class="ui-icon" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
            </span>
        @endif

        <div class="ui-stat__body">
            <p class="ui-stat__value ui-num">
                {{ $value }}@if ($unit !== null)<span class="ui-stat__unit">{{ $unit }}</span>@endif
            </p>

            @if ($label !== null)
                <p class="ui-stat__label">{{ $label }}</p>
            @endif

            @if ($delta !== null)
                {{-- Arrow + sign, so direction survives without colour vision. --}}
                <p class="ui-stat__delta ui-stat__delta--{{ $trend }}">
                    <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#{{ $trendIcon }}"/></svg>
                    <span class="ui-num">{{ $delta }}</span>
                </p>
            @endif

            @isset($foot)
                <div class="ui-stat__foot">{{ $foot }}</div>
            @endisset
        </div>
    </{{ $tag }}>
@endif
