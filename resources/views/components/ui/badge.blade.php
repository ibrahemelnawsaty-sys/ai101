{{--
    Badge

    A square-cornered status chip. Colour never carries the meaning on its own:
    either an icon or the text itself says what the state is (Article 18).

    A badge reports a state the server already decided — it is never a control.
    Use <x-ui.button> if it should be clickable.

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 15, 16, 18

    Props
      variant   success | warning | error | info | brand | neutral | solid | outline | count
      size      sm | md | lg
      state     default | loading
      icon      sprite id drawn before the label
--}}
@props([
    'variant' => 'neutral',
    'size' => 'md',
    'state' => 'default',
    'icon' => null,
])

@php
    $allowed = ['success', 'warning', 'error', 'info', 'brand', 'neutral', 'solid', 'outline', 'count'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'neutral';
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
@endphp

@if ($state === 'loading')
    <span class="ui-sk ui-sk-chip" role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
    </span>
@else
    <span {{ $attributes->class([
        'ui-badge',
        'ui-badge--' . $variant,
        'ui-badge--' . $size,
    ]) }}>
        @if ($icon)
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
        @endif
        {{ $slot }}
    </span>
@endif
