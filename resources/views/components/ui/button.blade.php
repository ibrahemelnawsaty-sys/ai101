{{--
    Button

    Four visual variants, three sizes, and the loading / disabled states the PRD
    fixes. Renders an <a> when `href` is given and a <button> otherwise, so a
    navigation never posts and an action never looks like a link.

    A disabled link is still rendered as an <a> but with aria-disabled and no
    keyboard stop, because removing href would strip its accessible role.

    Nothing here decides anything: a caller passes `state="disabled"` only to
    mirror a refusal the server already made (Article 5).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 18 · CONTRACT §12

    Props
      variant      primary | secondary | ghost | danger
      size         sm | md | lg
      state        default | loading | disabled
      type         submit | button | reset      (ignored when href is set)
      href         renders an anchor instead of a button
      block        full-width
      icon         sprite id without the leading '#', drawn before the label
      iconEnd      sprite id drawn after the label
      directional  mirror the icons in RTL (arrows, chevrons)
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'state' => 'default',
    'type' => 'button',
    'href' => null,
    'block' => false,
    'icon' => null,
    'iconEnd' => null,
    'directional' => false,
])

@php
    $variant = in_array($variant, ['primary', 'secondary', 'ghost', 'danger'], true) ? $variant : 'primary';
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';

    $isLoading = $state === 'loading';
    $isDisabled = $isLoading || $state === 'disabled';

    $tag = $href !== null ? 'a' : 'button';

    $iconClass = 'ui-icon'
        . ($size === 'sm' ? ' ui-icon--sm' : '')
        . ($directional ? ' ui-icon--dir' : '');
@endphp

<{{ $tag }}
    @if ($href !== null)
        href="{{ $href }}"
    @else
        type="{{ in_array($type, ['submit', 'button', 'reset'], true) ? $type : 'button' }}"
        @disabled($isDisabled)
    @endif
    @if ($isDisabled)
        aria-disabled="true"
        @if ($href !== null) tabindex="-1" @endif
    @endif
    @if ($isLoading) aria-busy="true" @endif
    {{ $attributes->class([
        'ui-btn',
        'ui-btn--' . $variant,
        'ui-btn--' . $size,
        'ui-btn--block' => $block,
        'is-loading' => $isLoading,
    ]) }}
>
    @if ($isLoading)
        <span class="ui-spinner" aria-hidden="true"></span>
        <span class="ui-sr">{{ __('app.states.loading') }}</span>
    @elseif ($icon)
        <svg class="{{ $iconClass }}" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
    @endif

    <span>{{ $slot }}</span>

    @if ($iconEnd && ! $isLoading)
        <svg class="{{ $iconClass }}" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($iconEnd, 'i-') ? $iconEnd : 'i-'.$iconEnd }}"/></svg>
    @endif
</{{ $tag }}>
