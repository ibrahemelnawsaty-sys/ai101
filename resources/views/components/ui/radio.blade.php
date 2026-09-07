{{--
    Radio

    Always rendered as a group inside a <fieldset>, because a lone radio is a
    UI bug: the reader needs the legend to know what the choice is about.
    Pass `:options` for the choices; the label row is the click target, so the
    interactive area is never under 44px (Article 18).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 18

    Props
      variant   default | cards          'cards' outlines each option
      size      sm | md | lg             reserved; the target is fixed at 44px
      state     default | disabled | error
      options   array of ['value','label','description','disabled']
      value     the currently selected value — the server's answer
--}}


<fieldset
    {{ $attributes->only('class')->class(['ui-check-group', 'ui-check-group--cards' => $variant === 'cards']) }}
    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
    @if ($message !== null) aria-invalid="true" @endif
>
    @if ($legend !== null)
        <legend class="ui-check-group__legend">
            {{ $legend }}
            @if ($required)
                <i class="ui-field__required" aria-hidden="true">*</i>
            @endif
        </legend>
    @endif

    @foreach ($items as $i => $option)
        <label class="ui-check ui-check--radio @if ($optionDisabled($option)) ui-check--disabled @endif" for="{{ $optionId($i) }}">
            <span class="ui-check__control">
                <input
                    type="radio"
                    class="ui-check__input"
                    id="{{ $optionId($i) }}"
                    @if ($name) name="{{ $name }}" @endif
                    value="{{ $optionValue($option) }}"
                    @checked($current !== '' && $current === $optionValue($option))
                    @disabled($optionDisabled($option))
                    @required($required && $i === 0)
                    @if ($message !== null) aria-invalid="true" @endif
                >
                <span class="ui-check__box" aria-hidden="true">
                    <svg class="ui-icon ui-check__mark" focusable="false"><use href="#i-check"/></svg>
                </span>
            </span>
            <span class="ui-check__text">
                <span class="ui-check__title">{{ $option['label'] ?? '' }}</span>
                @if (! empty($option['description']))
                    <span class="ui-check__desc">{{ $option['description'] }}</span>
                @endif
            </span>
        </label>
    @endforeach

    {{-- Empty state (Article 17): a choice list with nothing to choose from. --}}
    @if (count($items) === 0)
        <p class="ui-field__hint">{{ $slot->isNotEmpty() ? $slot : __('app.states.empty_body') }}</p>
    @endif

    @if ($message !== null)
        <p class="ui-field__hint ui-field__hint--error" id="{{ $errorId }}" role="alert">
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
            <span>{{ $message }}</span>
        </p>
    @elseif ($hint !== null)
        <p class="ui-field__hint" id="{{ $hintId }}">{{ $hint }}</p>
    @endif
</fieldset>
