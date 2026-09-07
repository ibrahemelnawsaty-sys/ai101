{{--
    Pill

    The rounded sibling of <x-ui.badge>, used for filters, tags and the LIVE
    marker on a running session. The live dot pulses, and that pulse is
    cancelled entirely by prefers-reduced-motion in components.css.

    "Live" here is a rendering of a session state the server computed from
    Clock::now() — the browser never decides that a session is live (BR-07).

    @see PRD §5.8, §5.9, §9.10 · CONSTITUTION Articles 5, 11, 15, 18

    Props
      variant   success | warning | error | info | brand | neutral | solid | live
      size      sm | md | lg
      state     default | loading
      icon      sprite id drawn before the label
      dot       show the leading status dot
--}}
@props([
    'variant' => 'neutral',
    'size' => 'md',
    'state' => 'default',
    'icon' => null,
    'dot' => false,
])

@php
    $allowed = ['success', 'warning', 'error', 'info', 'brand', 'neutral', 'solid', 'live'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'neutral';
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';

    $showDot = $dot || $variant === 'live';
@endphp

@if ($state === 'loading')
    <span class="ui-sk ui-sk-chip" role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
    </span>
@else
    <span {{ $attributes->class([
        'ui-pill',
        'ui-pill--' . $variant,
        'ui-pill--' . $size,
    ]) }}>
        @if ($showDot)
            <span class="ui-pill__dot" aria-hidden="true"></span>
        @endif
        @if ($icon)
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
        @endif
        {{ $slot }}
    </span>
@endif
