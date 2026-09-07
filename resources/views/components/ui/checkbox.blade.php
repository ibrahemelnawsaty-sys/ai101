{{--
    Checkbox

    A single checkbox, or a whole group when `:options` is passed. The label is
    the click target, so the interactive area is the full row and never under
    44px in either axis (Article 18).

    An unchecked box submits nothing, so a companion hidden input carries the
    "0" whenever `name` is set — otherwise a server-side boolean can never be
    turned off (Article 5: the server must see the real intent).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 18

    Props
      variant   default | cards          'cards' outlines each option
      size      sm | md | lg             reserved; the target is fixed at 44px
      state     default | disabled | error
      options   array of ['value','label','description','disabled']
      checked   the server's answer for a single box
      value     array|scalar of selected values for a group
--}}


@if ($items !== null)
    <fieldset {{ $attributes->only('class')->class(['ui-check-group', 'ui-check-group--cards' => $variant === 'cards']) }}>
        @if ($legend !== null)
            <legend class="ui-check-group__legend">
                {{ $legend }}
                @if ($required)
                    <i class="ui-field__required" aria-hidden="true">*</i>
                @endif
            </legend>
        @endif

        @foreach ($items as $i => $option)
            <label class="ui-check @if ($optionDisabled($option)) ui-check--disabled @endif" for="{{ $optionId($i) }}">
                <span class="ui-check__control">
                    <input
                        type="checkbox"
                        class="ui-check__input"
                        id="{{ $optionId($i) }}"
                        @if ($name) name="{{ $name }}[]" @endif
                        value="{{ $option['value'] ?? '' }}"
                        @checked($optionChecked($option))
                        @disabled($optionDisabled($option))
                        @if ($message !== null) aria-invalid="true" @endif
                        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
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

        @if ($message !== null)
            <p class="ui-field__hint ui-field__hint--error" id="{{ $errorId }}" role="alert">
                <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
                <span>{{ $message }}</span>
            </p>
        @elseif ($hint !== null)
            <p class="ui-field__hint" id="{{ $hintId }}">{{ $hint }}</p>
        @endif
    </fieldset>
@else
    <div {{ $attributes->only('class')->class(['ui-field']) }}>
        @if ($name && $withFalse)
            <input type="hidden" name="{{ $name }}" value="0">
        @endif

        <label class="ui-check @if ($isDisabled) ui-check--disabled @endif" for="{{ $baseId }}">
            <span class="ui-check__control">
                <input
                    type="checkbox"
                    class="ui-check__input"
                    id="{{ $baseId }}"
                    @if ($name) name="{{ $name }}" @endif
                    value="{{ $value ?? 1 }}"
                    @checked($checked)
                    @disabled($isDisabled)
                    @required($required)
                    @if ($indeterminate) x-data x-init="$el.indeterminate = true" @endif
                    @if ($message !== null) aria-invalid="true" @endif
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    {{ $attributes->except('class') }}
                >
                <span class="ui-check__box" aria-hidden="true">
                    @if ($indeterminate)
                        <span class="ui-check__dash"></span>
                    @else
                        <svg class="ui-icon ui-check__mark" focusable="false"><use href="#i-check"/></svg>
                    @endif
                </span>
            </span>
            <span class="ui-check__text">
                <span class="ui-check__title">
                    {{ $label ?? $slot }}
                    @if ($required)
                        <i class="ui-field__required" aria-hidden="true">*</i>
                    @endif
                </span>
                @if ($description !== null)
                    <span class="ui-check__desc">{{ $description }}</span>
                @endif
            </span>
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
@endif
