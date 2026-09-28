{{--
    Button

    Five visual variants, three sizes, and the loading / disabled states the PRD
    fixes. Renders an <a> when `href` is given and a <button> otherwise, so a
    navigation never posts and an action never looks like a link.

    Icon-only (D-127): `icon-only` draws the glyph alone in a square target of at
    least 44px. The words are not dropped, they move: `label` (or the slot text
    when no label is given) becomes the accessible name AND a tooltip that shows
    on hover and on keyboard focus. Use it only where the glyph is universally
    clear — pencil, trash, close, more, download, print, copy, share, search.
    An icon-only control with no name at all is not rendered icon-only: it
    falls back to the ordinary button rather than ship an unnamed target
    (Article 18).

    A disabled link is still rendered as an <a> but with aria-disabled and no
    keyboard stop, because removing href would strip its accessible role.

    Nothing here decides anything: a caller passes `state="disabled"` only to
    mirror a refusal the server already made (Article 5).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 18 · CONTRACT §12

    Props
      variant      primary | secondary | ghost | danger | danger-ghost
                   danger-ghost is the quiet trigger for a destructive row action
                   (remove, archive, suspend); the SOLID danger is reserved for
                   the final confirm inside <x-ui.confirm>.
      size         sm | md | lg
      state        default | loading | disabled
      type         submit | button | reset      (ignored when href is set)
      href         renders an anchor instead of a button
      block        full-width
      icon         sprite id without the leading '#', drawn before the label
      iconEnd      sprite id drawn after the label
      directional  mirror the icons in RTL (arrows, chevrons)
      icon-only    glyph alone; needs `icon` and a name (`label` or slot text)
      label        accessible name + tooltip of an icon-only button
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
    'iconOnly' => false,
    'label' => null,
])

@php
    $variant = in_array($variant, ['primary', 'secondary', 'ghost', 'danger', 'danger-ghost'], true) ? $variant : 'primary';
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';

    $isLoading = $state === 'loading';
    $isDisabled = $isLoading || $state === 'disabled';

    $tag = $href !== null ? 'a' : 'button';

    // The name an icon-only button carries: an explicit label, else the words
    // the caller put in the slot (which are then NOT drawn beside the glyph).
    $name = trim((string) ($label ?? strip_tags((string) $slot)));
    $glyphOnly = (bool) $iconOnly && $icon && $name !== '';

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
        'ui-btn--icon' => $glyphOnly,
        'is-loading' => $isLoading,
    ]) }}
    @if ($glyphOnly)
        aria-label="{{ $name }}"
        data-tip="{{ $name }}"
    @endif
>
    @if ($isLoading)
        <span class="ui-spinner" aria-hidden="true"></span>
        <span class="ui-sr">{{ __('app.states.loading') }}</span>
    @elseif ($icon)
        <svg class="{{ $iconClass }}" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
    @endif

    @unless ($glyphOnly)
        <span>{{ $slot }}</span>
    @endunless

    @if ($iconEnd && ! $isLoading && ! $glyphOnly)
        <svg class="{{ $iconClass }}" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($iconEnd, 'i-') ? $iconEnd : 'i-'.$iconEnd }}"/></svg>
    @endif
</{{ $tag }}>
