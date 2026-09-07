{{--
    Avatar

    A round photo, or the first two letters of the name on a violet ground when
    there is no photo (PRD §5.8).

    Article 16-bis: those two letters are ONE text node. Arabic letters connect,
    so the name is never split into per-character spans — `mb_substr` takes the
    first two characters of the string and they render joined, exactly as they
    would inside the full word.

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 14, 15, 16, 16-bis, 18

    Props
      variant   default (violet) | teal | neutral
      size      xs | sm | md | lg | xl
      state     default | loading
      name      the display name — the source of both the initials and the alt
      src       photo URL; a missing file simply falls back to the initials
      ring      draw the brand ring around the avatar
--}}
@props([
    'variant' => 'default',
    'size' => 'md',
    'state' => 'default',
    'name' => '',
    'src' => null,
    'ring' => false,
])

@php
    $variant = in_array($variant, ['default', 'teal', 'neutral'], true) ? $variant : 'default';
    $size = in_array($size, ['xs', 'sm', 'md', 'lg', 'xl'], true) ? $size : 'md';

    $clean = trim(preg_replace('/\s+/u', ' ', (string) $name));
    // One text node, two joined letters — never one span per character.
    $initials = $clean === '' ? '' : mb_substr($clean, 0, 2);

    $alt = $clean === '' ? __('ui.avatar.placeholder') : __('ui.avatar.alt', ['name' => $clean]);
@endphp

@if ($state === 'loading')
    <span class="ui-sk ui-sk-avatar" role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
    </span>
@else
    <span
        {{ $attributes->class([
            'ui-avatar',
            'ui-avatar--' . $size,
            'ui-avatar--' . $variant => $variant !== 'default',
            'ui-avatar--ring' => $ring,
        ]) }}
        @if ($src === null) role="img" aria-label="{{ $alt }}" @endif
    >
        @if ($src !== null)
            <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async">
        @else
            <span aria-hidden="true">{{ $initials }}</span>
        @endif
    </span>
@endif
