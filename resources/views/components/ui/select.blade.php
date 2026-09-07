{{--
    Select

    Two modes, one component (PRD §5.9: never build a second component when a
    prop will do).

      * NATIVE   — the default, and the right answer for short lists. Renders a
                   real <select>, so it works with no JavaScript at all.
      * LISTBOX  — used when `:options` is an array. Full keyboard support
                   (Up/Down/Home/End/Enter/Escape) and an internal search box
                   that appears past 8 options, exactly as the PRD requires.

    The listbox posts through a hidden input, so a form submit carries the same
    payload either way and the server sees no difference (Article 5).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 17, 18

    Props
      variant   native | listbox            'listbox' is implied by :options
      size      sm | md | lg
      state     default | loading | disabled | error
      options   array of ['value' => …, 'label' => …, 'disabled' => bool]
      value     currently selected value (the server's answer, not the browser's)
--}}


<div {{ $attributes->only('class')->class(['ui-field']) }}>
    @if ($label !== null)
        <label class="ui-field__label" @if (! $useListbox) for="{{ $fieldId }}" @endif>
            {{ $label }}
            @if ($required)
                <i class="ui-field__required" aria-hidden="true">*</i>
            @endif
        </label>
    @endif

    @if ($isLoading)
        <div class="ui-sk" style="block-size: var(--touch); border-radius: var(--r-md);" role="status" aria-live="polite">
            <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        </div>

    @elseif ($useListbox)
        <div
            class="ui-select"
            x-data="uiSelect({
                value: @js($current),
                items: @js($items ?? []),
                searchable: @js((bool) $withSearch),
                placeholder: @js($placeholderText)
            })"
            x-on:click.outside="close()"
        >
            @if ($name)
                <input type="hidden" name="{{ $name }}" x-bind:value="value" value="{{ $current }}">
            @endif

            <button
                type="button"
                id="{{ $fieldId }}"
                class="ui-select__button @if ($message !== null) ui-select__button--error @endif"
                x-ref="button"
                aria-haspopup="listbox"
                aria-expanded="false"
                aria-controls="{{ $listId }}"
                @if ($label !== null) aria-label="{{ $label }}" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                @if ($message !== null) aria-invalid="true" @endif
                @disabled($isDisabled)
                x-bind:aria-expanded="open ? 'true' : 'false'"
                x-on:click="toggle()"
                x-on:keydown="open ? onListKeydown($event) : onButtonKeydown($event)"
                {{ $attributes->except('class') }}
            >
                <span
                    class="ui-select__value"
                    x-bind:class="{ 'ui-select__value--placeholder': ! selectedLabel }"
                    x-text="selectedLabel || placeholder"
                >{{ $placeholderText }}</span>
                <svg class="ui-icon ui-icon--sm ui-select__caret" aria-hidden="true" focusable="false"><use href="#i-chevdown"/></svg>
            </button>

            <div class="ui-select__panel" x-show="open" x-cloak x-on:keydown="onListKeydown($event)">
                <template x-if="searchable">
                    <div class="ui-select__search">
                        <input
                            type="text"
                            class="ui-input"
                            x-ref="search"
                            x-model="query"
                            placeholder="{{ __('ui.select.search') }}"
                            aria-label="{{ __('ui.select.search_label') }}"
                            aria-controls="{{ $listId }}"
                        >
                    </div>
                </template>

                <ul class="ui-select__list" id="{{ $listId }}" role="listbox" x-ref="list" tabindex="-1">
                    <template x-for="(item, i) in filtered" x-bind:key="item.value">
                        <li
                            class="ui-select__option"
                            role="option"
                            x-bind:class="{ 'is-active': activeIndex === i }"
                            x-bind:aria-selected="String(item.value) === String(value) ? 'true' : 'false'"
                            x-bind:aria-disabled="item.disabled ? 'true' : 'false'"
                            x-on:click="pick(item)"
                            x-on:mousemove="activeIndex = i"
                        >
                            <span x-text="item.label"></span>
                            <svg
                                class="ui-icon ui-icon--sm ui-select__option-check"
                                aria-hidden="true"
                                focusable="false"
                                x-show="String(item.value) === String(value)"
                            ><use href="#i-check"/></svg>
                        </li>
                    </template>
                </ul>

                <p class="ui-select__empty" x-show="filtered.length === 0">{{ __('ui.select.no_results') }}</p>
            </div>
        </div>

    @else
        <select
            id="{{ $fieldId }}"
            @if ($name) name="{{ $name }}" @endif
            class="ui-select__native"
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($message !== null) aria-invalid="true" @endif
            @required($required)
            @disabled($isDisabled)
            {{ $attributes->except('class') }}
        >
            @if ($placeholder !== false)
                <option value="">{{ $placeholderText }}</option>
            @endif
            {{ $slot }}
        </select>
    @endif

    @if ($message !== null)
        <p class="ui-field__hint ui-field__hint--error" id="{{ $errorId }}" role="alert">
            <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-warn"/></svg>
            <span>{{ $message }}</span>
        </p>
    @elseif ($hint !== null)
        <p class="ui-field__hint" id="{{ $hintId }}">{{ $hint }}</p>
    @endif
</div>
