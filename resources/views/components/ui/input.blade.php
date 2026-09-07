{{--
    Input

    One field shell for text, email, password, number, phone, url and textarea.
    Renders label + control + hint + error, wires aria-describedby and
    aria-invalid, and offers the four mandatory states (Article 17):
      normal · loading (a skeleton the exact height of the control) · empty
      (the placeholder) · error (the server's message, never a client guess).

    The error text always comes from the server: `$errors` is the bag a
    FormRequest filled. This component validates nothing itself (Article 5).

    Latin-script fields (e-mail, URL, GitHub handle) read LTR inside the box
    while the label stays RTL (Article 16).

    @see PRD §5.8, §5.9, §11.1 · CONSTITUTION Articles 5, 15, 16, 17, 18

    Props
      variant   text | textarea            control shape
      size      sm | md | lg               reserved; the shell is one size today
      state     default | loading | disabled | readonly | success
      name      form field name (also the default id)
      label     visible label — omit only when an aria-label is supplied
      hint      helper text under the field
      error     explicit error message; falls back to $errors->first($name)
      ltr       force LTR text direction inside the box
      icon      sprite id drawn at the inline-start edge
      counter   show a character counter (requires maxlength)
--}}


<div
    {{ $attributes->only('class')->class(['ui-field']) }}
    @if ($counterOn)
        x-data="{ used: {{ $used }}, cap: {{ (int) $maxlength }} }"
        x-on:input="used = ($event.target.value || '').length"
    @endif
>
    @if ($label !== null)
        <label class="ui-field__label" for="{{ $fieldId }}">
            {{ $label }}
            @if ($required)
                <i class="ui-field__required" aria-hidden="true">*</i>
            @endif
        </label>
    @endif

    @if ($isLoading)
        {{-- Loading: a bar the exact height of the control it stands in for. --}}
        <div class="ui-sk" style="block-size: var(--touch); border-radius: var(--r-md);" role="status" aria-live="polite">
            <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        </div>
    @else
        <div
            class="{{ $wrapClass }}"
            @if ($isPassword)
                x-data="{ shown: false, showLabel: @js(__('auth.shared.show_password')), hideLabel: @js(__('auth.shared.hide_password')) }"
            @endif
        >
            @if ($icon)
                <span class="ui-input-wrap__icon ui-input-wrap__icon--start" aria-hidden="true">
                    <svg class="ui-icon ui-icon--sm" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
                </span>
            @endif

            @if ($isTextarea)
                <textarea
                    id="{{ $fieldId }}"
                    @if ($name) name="{{ $name }}" @endif
                    class="{{ $controlClass }}"
                    rows="{{ (int) $rows }}"
                    @if ($placeholder !== null) placeholder="{{ $placeholder }}" @endif
                    @if ($maxlength !== null) maxlength="{{ (int) $maxlength }}" @endif
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    @if ($message !== null) aria-invalid="true" @endif
                    @required($required)
                    @disabled($isDisabled)
                    @readonly($isReadonly)
                    {{ $attributes->except('class') }}
                >{{ $current }}</textarea>
            @else
                <input
                    id="{{ $fieldId }}"
                    @if ($name) name="{{ $name }}" @endif
                    type="{{ $isPassword ? 'password' : $type }}"
                    @if ($isPassword) x-bind:type="shown ? 'text' : 'password'" @endif
                    class="{{ $controlClass }}"
                    value="{{ $current }}"
                    @if ($placeholder !== null) placeholder="{{ $placeholder }}" @endif
                    @if ($maxlength !== null) maxlength="{{ (int) $maxlength }}" @endif
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    @if ($message !== null) aria-invalid="true" @endif
                    @required($required)
                    @disabled($isDisabled)
                    @readonly($isReadonly)
                    {{ $attributes->except('class') }}
                >
            @endif

            @if ($isPassword)
                {{-- Without JS the field stays a password field and this button is inert but labelled. --}}
                <button
                    type="button"
                    class="ui-input-wrap__action"
                    aria-label="{{ __('auth.shared.show_password') }}"
                    x-on:click="shown = ! shown"
                    x-bind:aria-label="shown ? hideLabel : showLabel"
                    x-bind:aria-pressed="shown ? 'true' : 'false'"
                >
                    <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-eye"/></svg>
                </button>
            @endif
        </div>
    @endif

    @if ($message !== null)
        <p class="ui-field__hint ui-field__hint--error" id="{{ $errorId }}" role="alert">
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
            <span>{{ $message }}</span>
        </p>
    @elseif ($isSuccess && $hint !== null)
        <p class="ui-field__hint ui-field__hint--success" id="{{ $hintId }}">
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-check"/></svg>
            <span>{{ $hint }}</span>
        </p>
    @elseif ($hint !== null)
        <p class="ui-field__hint" id="{{ $hintId }}">{{ $hint }}</p>
    @endif

    @if ($counterOn && ! $isLoading)
        <p
            class="ui-field__counter"
            id="{{ $counterId }}"
            aria-live="polite"
            x-bind:class="{ 'ui-field__counter--over': used > cap }"
        >
            <span class="ui-num" x-text="used">{{ $used }}</span>
            <span aria-hidden="true">/</span>
            <span class="ui-num">{{ (int) $maxlength }}</span>
        </p>
    @endif
</div>
