{{--
    Tooltip

    Appears on hover AND on keyboard focus, exactly as PRD §5.8 requires — a
    hover-only tooltip is invisible to anyone who does not use a mouse. Escape
    dismisses it, and the bubble is wired with aria-describedby so a screen
    reader reads it as part of the trigger rather than as loose text.

    A tooltip may only ever hold a hint. Anything a user MUST read to act
    correctly belongs in the field hint or the error line, where it is always
    visible (Article 17).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 15, 16, 18

    Props
      variant   top | bottom | start | end        where the bubble sits
      size      sm | md | lg                      reserved
      state     default
      text      the hint itself
      icon      sprite id when the trigger is the standard info button

    Slots
      $slot     the trigger; omit it to get the default info button
      bubble    rich bubble content, replacing `text`
--}}


<span
    {{ $attributes->class(['ui-tooltip', 'ui-tooltip--' . $variant]) }}
    x-data="uiTooltip()"
    x-on:keydown.escape="hide()"
>
    <button
        type="button"
        @class(['ui-tooltip__trigger', 'ui-tooltip__trigger--icon' => $slot->isEmpty()])
        aria-describedby="{{ $bubbleId }}"
        @if ($slot->isEmpty()) aria-label="{{ $triggerLabel }}" @endif
        x-on:mouseenter="show()"
        x-on:mouseleave="hide()"
        x-on:focus="show()"
        x-on:blur="hide()"
    >
        @if ($slot->isEmpty())
            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
        @else
            {{ $slot }}
        @endif
    </button>

    <span class="ui-tooltip__bubble" id="{{ $bubbleId }}" role="tooltip" x-show="open" x-cloak>
        @isset($bubble)
            {{ $bubble }}
        @else
            {{ $text }}
        @endisset
    </span>
</span>
