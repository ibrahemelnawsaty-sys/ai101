{{--
    Switch

    A checkbox with `role="switch"`. Used only where the change takes effect on
    its own (a notification preference, a visibility flag) — never as a form
    field the user must then remember to save.

    The thumb travels toward the track's inline-end edge, which is the LEFT in
    RTL; that flip lives in components.css, not here (Article 16).

    A companion hidden input carries the "off" value, so turning the switch off
    reaches the server as a real value rather than a missing key (Article 5).
    A locked switch that is ON posts its on value there instead — a disabled
    checkbox is not submitted, so the companion is all that arrives (D-78).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 18

    Props
      variant   default | reverse       'reverse' puts the label first
      size      sm | md | lg            reserved; the track is one size
      state     default | disabled | error
      checked   the server's answer, not the browser's memory
--}}


<div {{ $attributes->only('class')->class(['ui-field']) }}>
    @if ($name && $withFalse)
        <input type="hidden" name="{{ $name }}" value="{{ $hiddenValue }}">
    @endif

    <label
        class="ui-switch @if ($variant === 'reverse') ui-switch--reverse @endif @if ($isDisabled) ui-switch--disabled @endif"
        for="{{ $fieldId }}"
        x-data="{ on: @js((bool) $checked), onLabel: @js(__('ui.switch.on')), offLabel: @js(__('ui.switch.off')) }"
    >
        <span class="ui-switch__control">
            <input
                type="checkbox"
                role="switch"
                class="ui-switch__input"
                id="{{ $fieldId }}"
                @if ($name) name="{{ $name }}" @endif
                value="{{ $value }}"
                @checked($checked)
                @disabled($isDisabled)
                aria-checked="{{ $checked ? 'true' : 'false' }}"
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                x-on:change="on = $event.target.checked"
                x-bind:aria-checked="on ? 'true' : 'false'"
                {{ $attributes->except('class') }}
            >
            <span class="ui-switch__track" aria-hidden="true">
                <span class="ui-switch__thumb"></span>
            </span>
        </span>

        <span class="ui-check__text">
            <span class="ui-check__title">{{ $label ?? $slot }}</span>
            @if ($description !== null)
                <span class="ui-check__desc">{{ $description }}</span>
            @endif
        </span>

        {{-- State is never carried by colour alone (Article 18). --}}
        <span class="ui-sr" x-text="on ? onLabel : offLabel">{{ $checked ? __('ui.switch.on') : __('ui.switch.off') }}</span>
    </label>

    @if ($message !== null)
        <p class="ui-field__hint ui-field__hint--error" id="{{ $errorId }}" role="alert">
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
            <span>{{ $message }}</span>
        </p>
    @elseif ($hint !== null)
        <p class="ui-field__hint" id="{{ $hintId }}">{{ $hint }}</p>
    @endif
</div>
