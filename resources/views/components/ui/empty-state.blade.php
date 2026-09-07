{{--
    EmptyState

    Illustration + title + description + action, and every one of those four is
    passed in. Article 17 is unambiguous: each screen writes its OWN empty copy,
    because a generic "no data" line tells a participant nothing about
    what to do next.

    This component therefore authors no copy at all. It has no default title and
    no default description — a caller that omits them gets an empty box, which
    is exactly the review signal we want.

    The illustration is a simple line drawing in the two brand colours only
    (PRD §5.7). Pass one through the `art` slot, or name a sprite id with `icon`.

    @see PRD §5.7, §5.8, §5.9 · CONSTITUTION Articles 14, 15, 17, 18

    Props
      variant       default | error | success | locked
      size          sm | md | lg
      state         default        an empty state has one state
      title         the heading — screen-specific
      description   what to do next — screen-specific
      icon          sprite id used when no `art` slot is given

    Slots
      art       the illustration (an inline SVG, brand colours only)
      action    the call to action, usually one <x-ui.button>
--}}


<div
    {{ $attributes->class([
        'ui-empty',
        'ui-empty--' . $variant => $variant !== 'default',
        'ui-empty--' . $size => $size !== 'md',
    ]) }}
    @if ($variant === 'error') role="alert" @endif
    @if ($title !== null) aria-labelledby="{{ $headingId }}" @endif
>
    <div class="ui-empty__art" aria-hidden="true">
        @isset($art)
            {{ $art }}
        @else
            <svg class="ui-icon" focusable="false"><use href="#{{ str_starts_with($fallbackIcon, 'i-') ? $fallbackIcon : 'i-'.$fallbackIcon }}"/></svg>
        @endisset
    </div>

    @if ($title !== null)
        <h3 class="ui-empty__title" id="{{ $headingId }}">{{ $title }}</h3>
    @endif

    @if ($description !== null)
        <p class="ui-empty__desc">{{ $description }}</p>
    @endif

    {{ $slot }}

    @isset($action)
        <div class="ui-empty__actions">{{ $action }}</div>
    @endisset
</div>
